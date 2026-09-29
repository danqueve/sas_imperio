<?php
// ============================================================
// cobrador/agenda_historico.php — Histórico de agendas semanales
// (solo lectura, exclusivo admin/supervisor). Reconstruye, para una
// semana pasada puntual, quién tenía una cuota venciendo esa semana y
// quién de esos efectivamente pagó durante esa misma semana. No es
// modo-histórico de la agenda en vivo: no tiene ningún botón de acción.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Rango de la semana seleccionada (mismo patrón que
//    admin/estadisticas_cobranza.php:206-225) ────────────────────
$hoy          = new DateTimeImmutable('today');
$dow          = (int) $hoy->format('N'); // 1=Lun … 7=Dom
$lunes_actual = $hoy->modify('-' . ($dow - 1) . ' days');

if (!empty($_GET['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana'])) {
    try {
        $lunes_sel = new DateTimeImmutable(calcular_semana_lunes($_GET['semana']));
    } catch (Exception $e) {
        $lunes_sel = $lunes_actual;
    }
} else {
    $lunes_sel = $lunes_actual;
}

// No tiene sentido "histórico" de una semana que todavía no terminó de
// pasar del todo, pero tampoco se bloquea la semana actual — un admin
// puede querer ver "cómo viene" la semana en curso a mitad de camino.
$lunes_str        = $lunes_sel->format('Y-m-d');
$sabado_str       = calcular_semana_sabado($lunes_str);
$es_semana_actual = ($lunes_str === $lunes_actual->format('Y-m-d'));
$semana_ant_str   = $lunes_sel->modify('-7 days')->format('Y-m-d');
$semana_sig_str   = $lunes_sel->modify('+7 days')->format('Y-m-d');

// ── Selector de cobrador (siempre visible — el cobrador mismo nunca
//    entra a esta pantalla, ver Contexto del plan) ───────────────
$cobradores  = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol = 'cobrador' AND activo = 1 ORDER BY apellido, nombre"
)->fetchAll();
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);

$cobrador_lbl = '';
$agenda       = null;
if ($cobrador_id > 0) {
    $cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ? AND rol = 'cobrador'");
    $cs->execute([$cobrador_id]);
    $cob = $cs->fetch();
    if ($cob) {
        $cobrador_lbl = $cob['apellido'] . ', ' . $cob['nombre'];
        $agenda = obtener_agenda_historica($pdo, $cobrador_id, $lunes_str, $sabado_str);
    } else {
        $cobrador_id = 0;
    }
}

// ── KPIs generales (suma de todos los grupos, ambas secciones) ───
// "Clientes" NO se puede sumar el 'clientes' de cada grupo (un mismo
// cliente puede tener, ej., un crédito semanal y otro mensual venciendo
// la misma semana, o 2 créditos semanales en días distintos) — se arma
// con un set de cliente_id para contarlo una sola vez.
$tot_clientes_set = [];
$tot_cuotas = $tot_pagaron = $tot_no_pagaron = $tot_ya_pagas = 0;
$tot_estimado = $tot_cobrado = 0.0;
if ($agenda !== null) {
    foreach (array_merge($agenda['semanales'], $agenda['otras_frecuencias']) as $g) {
        $tot_cuotas     += $g['resumen']['cuotas'];
        $tot_pagaron    += $g['resumen']['pagaron'];
        $tot_no_pagaron += $g['resumen']['no_pagaron'];
        $tot_ya_pagas   += $g['resumen']['ya_pagas'];
        $tot_estimado   += $g['resumen']['estimado'];
        $tot_cobrado    += $g['resumen']['cobrado'];
        foreach ($g['clientes'] as $c) $tot_clientes_set[$c['cliente_id']] = true;
    }
}
$tot_clientes = count($tot_clientes_set);
$tot_pct = $tot_estimado > 0 ? round($tot_cobrado / $tot_estimado * 100) : 0;

function renderTarjetaCliente(array $c): string
{
    $vencim = date('d/m/Y', strtotime($c['fecha_vencimiento']));

    if (!empty($c['ya_estaba_paga'])) {
        $estado = '<span class="ahist-estado ahist-estado--ya-paga"><i class="fa fa-clock-rotate-left"></i> Ya estaba paga'
            . ($c['fecha_pago_antes'] ? ' (' . e(date('d/m/Y', strtotime($c['fecha_pago_antes']))) . ')' : '') . '</span>';
    } elseif ($c['pago_realizado']) {
        $estado = '<span class="ahist-estado ahist-estado--pago"><i class="fa fa-check-circle"></i> Pagó '
            . e(formato_pesos($c['pagado_esa_semana']))
            . ($c['fecha_pago_esa_semana'] ? ' el ' . e(date('d/m', strtotime($c['fecha_pago_esa_semana']))) : '')
            . ($c['forma_pago'] ? ' — ' . e($c['forma_pago']) : '') . '</span>';
    } else {
        $estado = '<span class="ahist-estado ahist-estado--no-pago"><i class="fa fa-circle-xmark"></i> No pagó</span>';
    }

    $card_class = !empty($c['ya_estaba_paga']) ? ' ahist-card--ya-paga' : ($c['pago_realizado'] ? ' ahist-card--pago' : '');

    return '
    <div class="ahist-card' . $card_class . '">
        <div class="ahist-card-main">
            <div class="ahist-card-nombre">' . e($c['apellidos'] . ', ' . $c['nombres']) . '</div>
            <div class="ahist-card-sub">' . e($c['articulo']) . ' — Cuota #' . (int) $c['numero_cuota'] . '/' . (int) $c['cant_cuotas'] . '</div>
            <div class="ahist-card-sub">' . (e($c['zona'] ?: 'Sin zona')) . ' · Vence ' . $vencim . '</div>
        </div>
        <div class="ahist-card-side">
            <div class="ahist-card-monto">' . e(formato_pesos((float) $c['monto_cuota'])) . '</div>
            ' . $estado . '
        </div>
    </div>';
}

function renderResumenGrupo(array $r): string
{
    $ya_pagas_html = !empty($r['ya_pagas'])
        ? '<span style="color:var(--text-muted)"><strong>' . $r['ya_pagas'] . '</strong> ya estaban pagas</span>'
        : '';
    return '
    <div class="ahist-resumen">
        <span><strong>' . $r['clientes'] . '</strong> clientes</span>
        <span><strong>' . $r['cuotas'] . '</strong> cuotas</span>
        <span style="color:var(--success)"><strong>' . $r['pagaron'] . '</strong> pagaron</span>
        <span style="color:var(--danger)"><strong>' . $r['no_pagaron'] . '</strong> no pagaron</span>
        ' . $ya_pagas_html . '
        <span>Estimado: <strong>' . e(formato_pesos($r['estimado'])) . '</strong></span>
        <span>Cobrado: <strong>' . e(formato_pesos($r['cobrado'])) . '</strong></span>
    </div>';
}

$page_title   = 'Histórico de Agendas';
$page_current = 'agenda_historico';
require_once __DIR__ . '/../views/layout.php';
?>

<style>
.ahist-resumen { display:flex; gap:18px; flex-wrap:wrap; font-size:.82rem; color:var(--text-muted); padding:10px 14px; background:var(--dark-input); border-radius:8px; margin-bottom:10px; }
.ahist-card { display:flex; justify-content:space-between; gap:14px; padding:12px 14px; border-radius:10px; border:1px solid var(--dark-border); background:var(--dark-card); margin-bottom:8px; border-left:4px solid var(--danger); }
.ahist-card--pago { border-left-color:var(--success); }
.ahist-card--ya-paga { border-left-color:var(--text-muted); opacity:.75; }
.ahist-card-nombre { font-weight:700; color:var(--text-main); }
.ahist-card-sub { font-size:.8rem; color:var(--text-muted); margin-top:2px; }
.ahist-card-side { text-align:right; flex-shrink:0; }
.ahist-card-monto { font-weight:700; color:var(--text-main); }
.ahist-estado { display:inline-flex; align-items:center; gap:5px; font-size:.78rem; font-weight:600; margin-top:4px; }
.ahist-estado--pago { color:var(--success); }
.ahist-estado--no-pago { color:var(--danger); }
.ahist-estado--ya-paga { color:var(--text-muted); }
.ahist-grupo-titulo { font-weight:700; color:var(--text-main); margin:18px 0 8px; font-size:.95rem; }
</style>

<div style="font-size:.78rem;color:var(--text-muted);margin-bottom:10px">
    <i class="fa fa-circle-info"></i> Reconstruye la agenda de una semana pasada: quién tenía una cuota venciendo esa semana y quién pagó durante esa misma semana (no después). No incluye cuotas de créditos dados de baja (Retiro de Producto, Incobrabilidad, etc.) ni cuotas que el cliente ya había pagado por adelantado en una semana anterior (esas figuran como "Ya estaba paga"). Usa la asignación de cobrador <strong>actual</strong> de cada crédito — si un crédito fue reasignado a otro cobrador (Migrar Cobrador) después de esa semana, va a figurar acá bajo el cobrador de hoy, no el de entonces.
</div>

<!-- ── SELECTOR DE SEMANA ─────────────────────────────────────── -->
<div class="card-ic mb-4">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <a href="?semana=<?= urlencode($semana_ant_str) ?>&cobrador_id=<?= $cobrador_id ?>"
           class="btn-ic btn-ghost btn-sm" title="Semana anterior">
            <i class="fa fa-chevron-left"></i>
        </a>
        <div style="flex:1;text-align:center">
            <div style="font-size:1rem;font-weight:700;color:var(--text-main)">
                <?= $lunes_sel->format('d/m/Y') ?> — <?= (new DateTimeImmutable($sabado_str))->format('d/m/Y') ?>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                <?php if ($es_semana_actual): ?>
                    <span style="color:var(--success)"><i class="fa fa-circle" style="font-size:.5rem;vertical-align:middle"></i> Semana actual (en curso)</span>
                <?php else: ?>
                    Semana <?= $lunes_sel->format('W') ?> de <?= $lunes_sel->format('Y') ?>
                <?php endif; ?>
            </div>
        </div>
        <a href="?semana=<?= urlencode($semana_sig_str) ?>&cobrador_id=<?= $cobrador_id ?>"
           class="btn-ic btn-ghost btn-sm" title="Semana siguiente">
            <i class="fa fa-chevron-right"></i>
        </a>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
            <input type="date" name="semana" value="<?= e($lunes_str) ?>"
                   style="min-width:150px" max="<?= $lunes_actual->format('Y-m-d') ?>">
            <button type="submit" class="btn-ic btn-ghost btn-sm">
                <i class="fa fa-search"></i> Ir
            </button>
        </form>
        <?php if (!$es_semana_actual): ?>
        <a href="?cobrador_id=<?= $cobrador_id ?>" class="btn-ic btn-primary btn-sm">
            <i class="fa fa-calendar-check"></i> Semana actual
        </a>
        <?php endif; ?>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="semana" value="<?= e($lunes_str) ?>">
            <select name="cobrador_id" style="min-width:180px" onchange="this.form.submit()">
                <option value="0">Elegí un cobrador…</option>
                <?php foreach ($cobradores as $cob): ?>
                    <option value="<?= $cob['id'] ?>" <?= $cobrador_id === (int) $cob['id'] ? 'selected' : '' ?>>
                        <?= e($cob['apellido'] . ', ' . $cob['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($cobrador_id > 0): ?>
        <a href="agenda_historico_pdf?cobrador_id=<?= $cobrador_id ?>&semana=<?= urlencode($lunes_str) ?>"
           class="btn-ic btn-ghost btn-sm" target="_blank" title="Exportar a PDF">
            <i class="fa fa-file-pdf"></i> PDF
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($cobrador_id === 0): ?>
    <div class="card-ic" style="text-align:center;padding:40px 20px;color:var(--text-muted)">
        <i class="fa fa-user-clock" style="font-size:2rem;margin-bottom:10px;display:block"></i>
        Elegí un cobrador para ver su histórico de esa semana.
    </div>
<?php else: ?>

    <!-- ── KPIs GENERALES ────────────────────────────────────── -->
    <div class="kpi-grid mb-4">
        <div class="kpi-card" style="--kpi-color:var(--primary)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(60,80,224,.15);--icon-color:#3C50E0">
                <i class="fa fa-user-tie"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Cobrador</div>
                <div class="kpi-value" style="font-size:1.1rem"><?= e($cobrador_lbl) ?></div>
            </div>
        </div>
        <div class="kpi-card" style="--kpi-color:var(--text-muted)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(160,174,191,.15);--icon-color:#A0AEBF">
                <i class="fa fa-users"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Clientes con cuota esa semana</div>
                <div class="kpi-value"><?= $tot_clientes ?></div>
                <div class="kpi-sub"><?= $tot_cuotas ?> cuotas<?= $tot_ya_pagas > 0 ? ' · ' . $tot_ya_pagas . ' ya pagas' : '' ?></div>
            </div>
        </div>
        <div class="kpi-card" style="--kpi-color:var(--success)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(33,150,83,.15);--icon-color:#219653">
                <i class="fa fa-check"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Pagaron</div>
                <div class="kpi-value"><?= $tot_pagaron ?></div>
            </div>
        </div>
        <div class="kpi-card" style="--kpi-color:var(--danger)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(211,64,83,.15);--icon-color:#D34053">
                <i class="fa fa-xmark"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">No pagaron</div>
                <div class="kpi-value"><?= $tot_no_pagaron ?></div>
            </div>
        </div>
        <div class="kpi-card" style="--kpi-color:var(--warning)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(255,167,11,.15);--icon-color:#FFA70B">
                <i class="fa fa-scale-balanced"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Estimado vs Cobrado</div>
                <div class="kpi-value" style="font-size:1rem"><?= e(formato_pesos($tot_estimado)) ?> / <?= e(formato_pesos($tot_cobrado)) ?></div>
                <div class="kpi-sub"><?= $tot_pct ?>% cobrado</div>
            </div>
        </div>
    </div>

    <?php if ($tot_clientes === 0): ?>
        <div class="card-ic" style="text-align:center;padding:30px 20px;color:var(--text-muted)">
            No hay ninguna cuota venciendo esta semana para <?= e($cobrador_lbl) ?>.
        </div>
    <?php else: ?>

        <!-- ── SEMANALES ─────────────────────────────────────── -->
        <?php if (!empty($agenda['semanales'])): ?>
            <div class="card-ic mb-4">
                <div class="card-ic-header"><span class="card-title"><i class="fa fa-calendar-week"></i> Semanales</span></div>
                <?php foreach ($agenda['semanales'] as $g): ?>
                    <div class="ahist-grupo-titulo"><?= e($g['label']) ?></div>
                    <?= renderResumenGrupo($g['resumen']) ?>
                    <?php foreach ($g['clientes'] as $c): ?>
                        <?= renderTarjetaCliente($c) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ── QUINCENAL / MENSUAL / DIARIO ─────────────────────── -->
        <?php if (!empty($agenda['otras_frecuencias'])): ?>
            <div class="card-ic mb-4">
                <div class="card-ic-header"><span class="card-title"><i class="fa fa-calendar-days"></i> Quincenal / Mensual / Diario</span></div>
                <?php foreach ($agenda['otras_frecuencias'] as $g): ?>
                    <div class="ahist-grupo-titulo"><?= e($g['label']) ?></div>
                    <?= renderResumenGrupo($g['resumen']) ?>
                    <?php foreach ($g['clientes'] as $c): ?>
                        <?= renderTarjetaCliente($c) ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
<?php endif; ?>
