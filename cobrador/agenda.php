<?php
// ============================================================
// cobrador/agenda.php — Vista diaria del cobrador
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo = obtener_conexion();
$hoy_dt = new DateTime('today');
$dia_semana = (int) $hoy_dt->format('N'); // 1=Lun…7=Dom
$hoy = $hoy_dt->format('Y-m-d');
$user_id = $_SESSION['user_id'];
$is_cobrador = es_cobrador();

// Si es domingo (7) no hay agenda
$dia_laboral = $dia_semana !== 7;

// Filtro de cobrador (admin/supervisor pueden ver a otros)
$cobrador_filtro = $is_cobrador ? $user_id : (int) ($_GET['cobrador_id'] ?? $user_id);
$q_busca = trim($_GET['q'] ?? '');

// Cuotas del día: vencen hoy (semanal con dia_cobro = hoy)
// También incluye cuotas vencidas pendientes del cobrador
$condCobrador = 'AND cr.cobrador_id = ?';

$sql = "
    SELECT cu.*, cr.id AS credito_id, cr.frecuencia, cr.interes_moratorio_pct, cr.cobrador_id,
           cr.cant_cuotas,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE cu.estado IN ('PENDIENTE','VENCIDA')
      AND cr.estado = 'EN_CURSO'
      $condCobrador
    ORDER BY cu.fecha_vencimiento ASC, cl.apellidos ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$cobrador_filtro]);
$todas = $stmt->fetchAll();

// Calcular mora y separar: del día vs vencidas
$del_dia = [];
$vencidas = [];
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

foreach ($todas as $c) {
    $dias_atraso = dias_atraso_habiles($c['fecha_vencimiento']);
    $mora = calcular_mora($c['monto_cuota'], $dias_atraso, $c['interes_moratorio_pct']);
    $c['dias_atraso_calc'] = $dias_atraso;
    $c['mora_calc'] = $mora;
    $c['total_a_cobrar'] = $c['monto_cuota'] + $mora;

    // Filtro búsqueda
    if ($q_busca !== '' && !str_contains(strtolower($c['apellidos'] . ' ' . $c['nombres']), strtolower($q_busca)))
        continue;

    if ($dias_atraso === 0 && $c['fecha_vencimiento'] === $hoy) {
        $del_dia[] = $c;
    } elseif ($dias_atraso > 0) {
        $vencidas[] = $c;
    } elseif ($c['fecha_vencimiento'] === $hoy) {
        $del_dia[] = $c;
    }
}

// ── Vista semanal: clientes por dia_cobro ─────────────────────
$semana_stmt = $pdo->prepare("
    SELECT cu.id,
           cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.dia_cobro,
           cl.coordenadas,
           cr.interes_moratorio_pct, cr.cant_cuotas,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen
    FROM ic_clientes cl
    JOIN ic_creditos cr ON cr.cliente_id  = cl.id AND cr.cobrador_id = ? AND cr.estado = 'EN_CURSO'
    JOIN ic_cuotas  cu ON cu.credito_id   = cr.id AND cu.estado IN ('PENDIENTE','VENCIDA')
    LEFT JOIN ic_articulos a ON a.id           = cr.articulo_id
    WHERE cl.dia_cobro BETWEEN 1 AND 6
    ORDER BY cl.dia_cobro ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$semana_stmt->execute([$cobrador_filtro]);
$semana_rows_raw = $semana_stmt->fetchAll();

// Calcular mora para cada fila semanal
$semana_rows = [];
foreach ($semana_rows_raw as $r) {
    $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);
    $mora        = calcular_mora($r['monto_cuota'], $dias_atraso, $r['interes_moratorio_pct']);
    $r['dias_atraso_calc'] = $dias_atraso;
    $r['mora_calc']        = $mora;
    $r['total_a_cobrar']   = $r['monto_cuota'] + $mora;
    $r['pago_pen']         = $r['pago_pen'] ?? 0;
    $semana_rows[]         = $r;
}

// Agrupar por día
$por_dia = array_fill(1, 6, []);
foreach ($semana_rows as $r) {
    $por_dia[(int)$r['dia_cobro']][] = $r;
}
$nombres_dia = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado'];

$page_title = 'Agenda del ' . $hoy_dt->format('d/m/Y');
$page_current = 'agenda';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- FILTROS -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <input type="text" name="q" value="<?= e($q_busca) ?>" placeholder="🔍 Buscar cliente...">
        <?php if (!$is_cobrador): ?>
            <select name="cobrador_id">
                <?php foreach ($cobradores as $cob): ?>
                    <option value="<?= $cob['id'] ?>" <?= $cobrador_filtro == $cob['id'] ? 'selected' : '' ?>>
                        <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <?php if (!$is_cobrador): ?>
            <button type="button" class="btn-ic btn-ghost" onclick="openModal('modal-agenda-pdf')">
                <i class="fa fa-file-pdf"></i> Ficha PDF
            </button>
        <?php endif; ?>
    </form>
</div>

<?php if (!$is_cobrador): ?>
<!-- MODAL EXPORTAR FICHA PDF -->
<div class="modal-overlay" id="modal-agenda-pdf">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-file-pdf"></i> Exportar Ficha Semanal</div>
            <button class="modal-close" onclick="closeModal('modal-agenda-pdf')">✕</button>
        </div>
        <form id="form-agenda-pdf" target="_blank" action="agenda_pdf.php" method="GET">
            <div class="form-group mb-3">
                <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:6px">Cobrador</label>
                <select name="cobrador_id" style="width:100%">
                    <?php foreach ($cobradores as $cob): ?>
                        <option value="<?= $cob['id'] ?>" <?= $cobrador_filtro == $cob['id'] ? 'selected' : '' ?>>
                            <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-4">
                <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:8px">Días a incluir</label>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                    <?php
                    $dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado'];
                    foreach ($dias as $num => $nom):
                    ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.9rem">
                            <input type="checkbox" name="dias[]" value="<?= $num ?>" checked
                                style="width:16px;height:16px;cursor:pointer">
                            <?= $nom ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-primary w-100" style="justify-content:center">
                    <i class="fa fa-file-pdf"></i> Generar PDF
                </button>
                <button type="button" onclick="closeModal('modal-agenda-pdf')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- SUMARIO DEL DÍA -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <i class="fa fa-calendar-check kpi-icon"></i>
        <div class="kpi-label">Del día</div>
        <div class="kpi-value">
            <?= count($del_dia) ?>
        </div>
        <div class="kpi-sub">cuotas a cobrar hoy</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <i class="fa fa-fire kpi-icon"></i>
        <div class="kpi-label">Vencidas</div>
        <div class="kpi-value">
            <?= count($vencidas) ?>
        </div>
        <div class="kpi-sub">cuotas sin cobrar</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <i class="fa fa-dollar-sign kpi-icon"></i>
        <div class="kpi-label">Total a cobrar hoy</div>
        <div class="kpi-value" style="font-size:1.2rem">
            <?= formato_pesos(array_sum(array_map(fn($c) => $c['total_a_cobrar'], $del_dia))) ?>
        </div>
        <div class="kpi-sub">capital + mora</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <i class="fa fa-plus-circle kpi-icon"></i>
        <div class="kpi-label">Mora vencidas</div>
        <div class="kpi-value" style="font-size:1.2rem;color:var(--danger)">
            <?= formato_pesos(array_sum(array_map(fn($c) => $c['mora_calc'], $vencidas))) ?>
        </div>
        <div class="kpi-sub">mora acumulada</div>
    </div>
</div>

<?php
// Helper: fecha relativa
function fecha_relativa(string $fecha_str): string {
    $hoy  = new DateTime('today');
    $venc = new DateTime($fecha_str);
    $diff = (int) $hoy->diff($venc)->days;
    $fut  = $venc >= $hoy;
    if ($diff === 0) return '<span style="color:var(--warning)">hoy</span>';
    if (!$fut)      return '<span style="color:var(--danger)">hace ' . $diff . ' día' . ($diff > 1 ? 's' : '') . '</span>';
    if ($diff === 1) return '<span style="color:var(--success)">mañana</span>';
    if ($diff < 7)  return '<span style="color:var(--success)">en ' . $diff . ' días</span>';
    $sem = (int) ceil($diff / 7);
    return '<span style="color:var(--text-muted)">en ' . $sem . ' semana' . ($sem > 1 ? 's' : '') . '</span>';
}

// Función helper para renderizar lista de cuotas
function render_tabla_cuotas(array $cuotas, string $titulo, string $color): string
{
    if (empty($cuotas))
        return "<p class='text-muted text-center' style='padding:20px'>Sin cuotas en esta sección.</p>";
    ob_start();
    foreach ($cuotas as $c):
        $mora_pos = $c['mora_calc'] > 0;
        $data     = htmlspecialchars(json_encode($c), ENT_QUOTES);
        $wa_msg   = 'Hola ' . $c['nombres'] . ', le recordamos su cuota #' . $c['numero_cuota'] . ' vencida el ' . date('d/m/Y', strtotime($c['fecha_vencimiento'])) . '. Total: ' . formato_pesos($c['total_a_cobrar']);
?>
    <div class="agenda-row <?= $mora_pos ? 'agenda-row--vencida' : '' ?>" id="row-<?= $c['id'] ?>">

        <!-- Cliente -->
        <div class="agenda-col agenda-col--cliente">
            <button class="agenda-cliente-btn" onclick="toggleArticulo(<?= $c['id'] ?>)" title="Ver artículo">
                <span class="agenda-nombre"><?= e(strtoupper($c['apellidos'] . ' ' . $c['nombres'])) ?></span>
                <span class="agenda-zona">Zona: <?= e($c['zona'] ?: '—') ?></span>
            </button>
            <div class="agenda-articulo" id="art-<?= $c['id'] ?>" style="display:none">
                <i class="fa fa-box-open"></i> <?= e($c['articulo']) ?>
            </div>
        </div>

        <!-- Importe -->
        <div class="agenda-col agenda-col--importe">
            <span class="agenda-monto"><?= formato_pesos($c['monto_cuota']) ?></span>
            <span class="agenda-cuota-num">Cuota <?= $c['numero_cuota'] ?>/<?= $c['cant_cuotas'] ?></span>
            <?php if ($mora_pos): ?>
                <span class="agenda-mora">+Mora: <?= formato_pesos($c['mora_calc']) ?></span>
            <?php endif; ?>
        </div>

        <!-- Cobro inline -->
        <div class="agenda-col agenda-col--cobro">
            <?php if ($c['pago_pen'] == 0): ?>
                <div class="agenda-cobro-wrap">
                    <input type="number" class="agenda-cobro-input" id="inp-<?= $c['id'] ?>"
                        value="<?= number_format($c['total_a_cobrar'], 2, '.', '') ?>"
                        step="0.01" min="0" placeholder="0.00">
                    <button class="agenda-cobro-btn" onclick="abrirPagoDesdeRow(<?= $data ?>)"
                        title="Registrar pago">
                        <i class="fa fa-check"></i>
                    </button>
                </div>
            <?php else: ?>
                <span class="badge-ic badge-warning" style="font-size:.72rem">Registrado</span>
            <?php endif; ?>
        </div>

        <!-- Vencimiento -->
        <div class="agenda-col agenda-col--vencim">
            <span class="agenda-fecha"><?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></span>
            <span class="agenda-relativa"><?= fecha_relativa($c['fecha_vencimiento']) ?></span>
        </div>

        <!-- Acciones -->
        <div class="agenda-col agenda-col--acciones">
            <a href="<?= whatsapp_url($c['telefono'], $wa_msg) ?>" target="_blank"
               class="btn-ic btn-ghost btn-sm btn-icon agenda-action" title="WhatsApp">
                <i class="fa-brands fa-whatsapp"></i>
            </a>
            <?php if ($c['coordenadas']): ?>
                <a href="<?= maps_url($c['coordenadas']) ?>" target="_blank"
                   class="btn-ic btn-accent btn-sm btn-icon agenda-action" title="Google Maps">
                    <i class="fa fa-map-marker-alt"></i>
                </a>
            <?php endif; ?>
        </div>

    </div>
<?php endforeach;
    return ob_get_clean();
}
?>

<!-- CUOTAS DEL DÍA -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-calendar-check" style="color:var(--success)"></i> Cuotas de Hoy
            <span class="badge-ic badge-success" style="margin-left:8px">
                <?= count($del_dia) ?>
            </span>
        </span>
    </div>
    <div class="agenda-header">
        <span>Cliente</span><span>Importe</span><span>Cobro</span><span>Vencimiento</span><span>Acciones</span>
    </div>
    <?= render_tabla_cuotas($del_dia, 'Hoy', 'success') ?>
</div>

<!-- CUOTAS VENCIDAS -->
<?php if (!empty($vencidas)): ?>
    <div class="card-ic mb-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-fire" style="color:var(--danger)"></i> Cuotas Vencidas
                <span class="badge-ic badge-danger" style="margin-left:8px">
                    <?= count($vencidas) ?>
                </span>
            </span>
        </div>
        <div class="agenda-header">
            <span>Cliente</span><span>Importe</span><span>Cobro</span><span>Vencimiento</span><span>Acciones</span>
        </div>
        <?= render_tabla_cuotas($vencidas, 'Vencidas', 'danger') ?>
    </div>
<?php endif; ?>

<!-- ── VISTA SEMANAL ──────────────────────────────────────────── -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-calendar-week"></i> Vista Semanal</span>
        <span class="text-muted" style="font-size:.82rem">
            <?= count($semana_rows) ?> cuota(s) pendientes esta semana
        </span>
    </div>

    <!-- Tabs de días -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 16px 0;border-bottom:1px solid rgba(255,255,255,.08)">
        <?php foreach ($nombres_dia as $n => $nombre): ?>
            <?php $cnt = count($por_dia[$n]); ?>
            <button
                onclick="switchDia(<?= $n ?>)"
                id="tab-dia-<?= $n ?>"
                class="btn-ic btn-sm <?= $n === $dia_semana ? 'btn-primary' : 'btn-ghost' ?>"
                style="position:relative">
                <?= $nombre ?>
                <?php if ($cnt > 0): ?>
                    <span style="
                        position:absolute;top:-6px;right:-6px;
                        background:var(--primary);color:#fff;
                        border-radius:50%;width:18px;height:18px;
                        font-size:.65rem;display:flex;align-items:center;justify-content:center;
                        font-weight:700">
                        <?= $cnt ?>
                    </span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Paneles de cada día -->
    <?php foreach ($nombres_dia as $n => $nombre): ?>
        <div id="panel-dia-<?= $n ?>" style="display:<?= $n === $dia_semana ? 'block' : 'none' ?>">
            <?php if (empty($por_dia[$n])): ?>
                <p class="text-muted text-center" style="padding:24px">
                    Sin cuotas asignadas para <?= $nombre ?>.
                </p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="table-ic">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th class="text-center">N° Cuota</th>
                                <th class="text-center">Vencim.</th>
                                <th class="text-right">Capital</th>
                                <th class="text-right">Mora</th>
                                <th class="text-right">Total</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($por_dia[$n] as $r): ?>
                                <tr <?= $r['dias_atraso_calc'] > 0 ? 'style="background:rgba(239,68,68,.05)"' : '' ?>>
                                    <td>
                                        <div class="fw-bold"><?= e($r['apellidos'] . ', ' . $r['nombres']) ?></div>
                                        <div class="text-muted" style="font-size:.75rem"><?= e($r['telefono']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-ic badge-muted">#<?= $r['numero_cuota'] ?></span>
                                    </td>
                                    <td class="text-center nowrap <?= $r['dias_atraso_calc'] > 0 ? 'text-danger' : '' ?>">
                                        <?= date('d/m/Y', strtotime($r['fecha_vencimiento'])) ?>
                                        <?php if ($r['dias_atraso_calc'] > 0): ?>
                                            <div style="font-size:.7rem"><?= $r['dias_atraso_calc'] ?> días háb.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right nowrap"><?= formato_pesos($r['monto_cuota']) ?></td>
                                    <td class="text-right nowrap <?= $r['mora_calc'] > 0 ? 'text-danger fw-bold' : '' ?>">
                                        <?= $r['mora_calc'] > 0 ? formato_pesos($r['mora_calc']) : '—' ?>
                                    </td>
                                    <td class="text-right nowrap fw-bold" style="color:var(--success)">
                                        <?= formato_pesos($r['total_a_cobrar']) ?>
                                    </td>
                                    <td class="nowrap">
                                        <div class="d-flex gap-2">
                                            <?php if ($r['pago_pen'] == 0): ?>
                                                <button onclick="abrirPago(<?= htmlspecialchars(json_encode($r)) ?>)"
                                                    class="btn-ic btn-success btn-sm" title="Registrar pago">
                                                    <i class="fa fa-dollar-sign"></i> Cobrar
                                                </button>
                                            <?php else: ?>
                                                <span class="badge-ic badge-warning">Registrado</span>
                                            <?php endif; ?>
                                            <a href="<?= whatsapp_url($r['telefono'], 'Hola ' . $r['nombres'] . ', le recordamos su cuota #' . $r['numero_cuota'] . ' vencida el ' . date('d/m/Y', strtotime($r['fecha_vencimiento'])) . '. Total: ' . formato_pesos($r['total_a_cobrar'])) ?>"
                                                target="_blank" class="btn-ic btn-ghost btn-sm btn-icon" title="WhatsApp">
                                                <i class="fa-brands fa-whatsapp"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align:right;font-size:.8rem;color:var(--text-muted)">
                                    Total <?= $nombre ?>:
                                </td>
                                <td class="text-right fw-bold" style="color:var(--success)">
                                    <?= formato_pesos(array_sum(array_column($por_dia[$n], 'total_a_cobrar'))) ?>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- MODAL REGISTRAR PAGO -->
<div class="modal-overlay" id="modal-pago">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-dollar-sign"></i> Registrar Pago</div>
            <button class="modal-close" onclick="closeModal('modal-pago')">✕</button>
        </div>
        <div id="modal-info"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="registrar_pago.php" class="form-ic" id="form-pago">
            <input type="hidden" name="cuota_id" id="input_cuota_id">
            <div class="form-grid">
                <div class="form-group">
                    <label>Monto Efectivo $</label>
                    <input type="number" name="monto_efectivo" id="inp_efectivo" step="0.01" min="0" value="0"
                        oninput="actualizarTotal()">
                </div>
                <div class="form-group">
                    <label>Monto Transferencia $</label>
                    <input type="number" name="monto_transferencia" id="inp_transfer" step="0.01" min="0" value="0"
                        oninput="actualizarTotal()">
                </div>
            </div>
            <div
                style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <span class="text-muted">Total Registrado:</span>
                <span id="total_display" style="font-size:1.2rem;font-weight:800;color:var(--success)">$ 0,00</span>
            </div>
            <input type="hidden" name="monto_mora_cobrada" id="inp_mora_cobrada" value="0">
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-success w-100" style="justify-content:center">
                    <i class="fa fa-save"></i> Confirmar Pago
                </button>
                <button type="button" onclick="closeModal('modal-pago')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<?php
$page_scripts = <<<'JS'
<style>
/* ── Agenda filas ────────────────────────────────────────────── */
.agenda-row {
    display: grid;
    grid-template-columns: 1fr auto auto auto auto;
    align-items: center;
    gap: 0 16px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    transition: background .15s;
}
.agenda-row:hover            { background: rgba(255,255,255,.04); }
.agenda-row--vencida         { border-left: 3px solid var(--danger); }

/* Cliente */
.agenda-cliente-btn {
    background: none; border: none; cursor: pointer;
    text-align: left; padding: 0; color: inherit;
    display: flex; flex-direction: column; gap: 2px;
}
.agenda-nombre {
    font-weight: 700; font-size: .9rem;
    color: var(--primary); text-decoration: underline dotted;
    text-underline-offset: 2px;
}
.agenda-zona  { font-size: .72rem; color: var(--text-muted); }
.agenda-articulo {
    margin-top: 6px; font-size: .8rem;
    color: var(--warning); display: flex; align-items: center; gap: 6px;
    padding: 4px 8px; background: rgba(255,255,255,.05);
    border-radius: 6px; width: fit-content;
}

/* Importe */
.agenda-col--importe  { text-align: right; white-space: nowrap; }
.agenda-monto         { font-weight: 700; font-size: .95rem; display: block; }
.agenda-cuota-num     { font-size: .72rem; color: var(--text-muted); display: block; }
.agenda-mora          { font-size: .72rem; color: var(--danger); display: block; font-weight: 600; }

/* Cobro inline */
.agenda-cobro-wrap {
    display: flex; align-items: center; gap: 4px;
}
.agenda-cobro-input {
    width: 90px; padding: 5px 8px; border-radius: 6px;
    border: 1px solid rgba(255,255,255,.15);
    background: rgba(255,255,255,.07); color: inherit;
    font-size: .82rem; text-align: right;
}
.agenda-cobro-input:focus { outline: none; border-color: var(--primary); }
.agenda-cobro-btn {
    width: 30px; height: 30px; border-radius: 6px;
    border: none; background: var(--success); color: #fff;
    cursor: pointer; font-size: .85rem; display: flex;
    align-items: center; justify-content: center;
    transition: opacity .15s;
}
.agenda-cobro-btn:hover { opacity: .85; }

/* Vencimiento */
.agenda-col--vencim { text-align: right; white-space: nowrap; }
.agenda-fecha       { font-size: .85rem; display: block; }
.agenda-relativa    { font-size: .72rem; display: block; }

/* Acciones */
.agenda-col--acciones { display: flex; gap: 6px; }
.agenda-action        { font-size: .9rem !important; }

/* Header de columnas */
.agenda-header {
    display: grid;
    grid-template-columns: 1fr auto auto auto auto;
    gap: 0 16px;
    padding: 8px 16px;
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: var(--text-muted);
    border-bottom: 1px solid rgba(255,255,255,.1);
}

/* ── Responsive agenda ≤768px ─────────────────────────────── */
@media (max-width: 768px) {
    .agenda-header { display: none; }
    .agenda-row {
        grid-template-columns: 1fr auto;
        grid-template-rows: auto auto auto;
        row-gap: 6px;
        padding: 10px 12px;
    }
    .agenda-col--cliente  { grid-column: 1; grid-row: 1; }
    .agenda-col--importe  { grid-column: 2; grid-row: 1; text-align: right; }
    .agenda-col--cobro    { grid-column: 1; grid-row: 2; }
    .agenda-col--vencim   { grid-column: 2; grid-row: 2; text-align: right; }
    .agenda-col--acciones { grid-column: 1 / 3; grid-row: 3; }
}
</style>

<script>
let cuota_mora = 0;

// Abre modal de pago (desde lista diaria — sin input inline)
function abrirPago(c) {
  _rellenarModal(c, c.total_a_cobrar);
}

// Abre modal desde la vista semanal o inline — lee el input del row
function abrirPagoDesdeRow(c) {
  const inp = document.getElementById('inp-' + c.id);
  const monto = inp ? parseFloat(inp.value) || c.total_a_cobrar : c.total_a_cobrar;
  _rellenarModal(c, monto);
}

function _rellenarModal(c, montoInicial) {
  cuota_mora = c.mora_calc || 0;
  document.getElementById('input_cuota_id').value = c.id;
  document.getElementById('modal-info').innerHTML =
    '<strong>' + c.apellidos + ', ' + c.nombres + '</strong><br>' +
    'Cuota #' + c.numero_cuota + (c.cant_cuotas ? '/' + c.cant_cuotas : '') +
    ' — Capital: ' + formatPesos(c.monto_cuota) +
    (cuota_mora > 0 ? ' + Mora: ' + formatPesos(cuota_mora) : '') +
    (c.articulo ? '<br><small style="color:var(--warning)">📦 ' + c.articulo + '</small>' : '') +
    '<br><strong>Total sugerido: ' + formatPesos(c.total_a_cobrar) + '</strong>';
  document.getElementById('inp_efectivo').value  = montoInicial.toFixed(2);
  document.getElementById('inp_transfer').value  = '0';
  document.getElementById('inp_mora_cobrada').value = cuota_mora.toFixed(2);
  actualizarTotal();
  openModal('modal-pago');
}

function actualizarTotal() {
  const ef = parseFloat(document.getElementById('inp_efectivo').value) || 0;
  const tr = parseFloat(document.getElementById('inp_transfer').value) || 0;
  document.getElementById('total_display').textContent = formatPesos(ef + tr);
}

function toggleArticulo(id) {
  const el = document.getElementById('art-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'flex' : 'none';
}

function switchDia(n) {
  for (let i = 1; i <= 6; i++) {
    const panel = document.getElementById('panel-dia-' + i);
    const tab   = document.getElementById('tab-dia-' + i);
    if (panel) panel.style.display = 'none';
    if (tab)   { tab.classList.remove('btn-primary'); tab.classList.add('btn-ghost'); }
  }
  const selPanel = document.getElementById('panel-dia-' + n);
  const selTab   = document.getElementById('tab-dia-' + n);
  if (selPanel) selPanel.style.display = 'block';
  if (selTab)   { selTab.classList.remove('btn-ghost'); selTab.classList.add('btn-primary'); }
}
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>