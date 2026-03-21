<?php
// ============================================================
// admin/estadisticas_cobranza.php — Dashboard de Efectividad
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_estadisticas');

$pdo = obtener_conexion();

// ── Rango de la semana seleccionada ──────────────────────────
$hoy = new DateTimeImmutable('today');
$dow = (int) $hoy->format('N'); // 1=Lun … 7=Dom
$lunes_actual = $hoy->modify('-' . ($dow - 1) . ' days');

if (!empty($_GET['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana'])) {
    try {
        $lunes_sel = new DateTimeImmutable($_GET['semana']);
        $dow_sel   = (int) $lunes_sel->format('N');
        $lunes_sel = $lunes_sel->modify('-' . ($dow_sel - 1) . ' days');
    } catch (Exception $e) {
        $lunes_sel = $lunes_actual;
    }
} else {
    $lunes_sel = $lunes_actual;
}

$sabado_sel      = $lunes_sel->modify('+5 days');
$inicio_str      = $lunes_sel->format('Y-m-d');
$fin_str         = $sabado_sel->format('Y-m-d');
$semana_ant_str  = $lunes_sel->modify('-7 days')->format('Y-m-d');
$semana_sig_str  = $lunes_sel->modify('+7 days')->format('Y-m-d');
$es_semana_actual = ($lunes_sel->format('Y-m-d') === $lunes_actual->format('Y-m-d'));

$nombres_dia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

// Generar array de fechas Lun→Sab
$dias_semana = []; // 'Y-m-d' => int (1-6)
for ($i = 0; $i < 6; $i++) {
    $d = $lunes_sel->modify("+{$i} days");
    $dias_semana[$d->format('Y-m-d')] = (int) $d->format('N');
}

$frecuencias = ['semanal', 'quincenal', 'mensual'];

// ── Query 1: Cobradores activos ───────────────────────────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios
     WHERE rol = 'cobrador' AND activo = 1
     ORDER BY apellido, nombre"
)->fetchAll();

// ── Query 2: Cobros registrados en el período ────────────────
// Incluye PENDIENTE (esperando rendición) + APROBADO (ya rendido)
$stmt_cobros = $pdo->prepare("
    SELECT
        pt.cobrador_id,
        pt.fecha_jornada,
        cr.frecuencia,
        COUNT(DISTINCT pt.cuota_id)  AS cuotas_cobradas,
        SUM(pt.monto_total)          AS monto_cobrado,
        SUM(pt.monto_efectivo)       AS efectivo,
        SUM(pt.monto_transferencia)  AS transferencia,
        SUM(pt.monto_mora_cobrada)   AS mora_cobrada
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas   cu ON pt.cuota_id    = cu.id
    JOIN ic_creditos cr ON cu.credito_id  = cr.id
    WHERE pt.fecha_jornada BETWEEN ? AND ?
      AND pt.estado IN ('PENDIENTE', 'APROBADO')
    GROUP BY pt.cobrador_id, pt.fecha_jornada, cr.frecuencia
");
$stmt_cobros->execute([$inicio_str, $fin_str]);
$cobros_raw = $stmt_cobros->fetchAll();

// ── Query 3: Cuotas agendadas (por fecha_vencimiento) ────────
$stmt_agenda = $pdo->prepare("
    SELECT
        cr.cobrador_id,
        cu.fecha_vencimiento,
        cr.frecuencia,
        COUNT(*)             AS cuotas_agendadas,
        SUM(cu.monto_cuota)  AS monto_estimado
    FROM ic_cuotas   cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cu.fecha_vencimiento BETWEEN ? AND ?
      AND cr.estado = 'EN_CURSO'
    GROUP BY cr.cobrador_id, cu.fecha_vencimiento, cr.frecuencia
");
$stmt_agenda->execute([$inicio_str, $fin_str]);
$agenda_raw = $stmt_agenda->fetchAll();

// ── Estructura de datos vacía por cobrador ────────────────────
function dia_vacio(array $frecuencias): array
{
    $por_tipo = array_fill_keys($frecuencias, [
        'agendados' => 0, 'cobrados' => 0,
        'monto_estimado' => 0.0, 'monto_cobrado' => 0.0,
    ]);
    return [
        'agendados'      => 0,
        'cobrados'       => 0,
        'monto_estimado' => 0.0,
        'monto_cobrado'  => 0.0,
        'efectivo'       => 0.0,
        'transferencia'  => 0.0,
        'mora'           => 0.0,
        'por_tipo'       => $por_tipo,
    ];
}

$data = [];
foreach ($cobradores as $cob) {
    $dias = [];
    foreach (array_keys($dias_semana) as $fecha) {
        $dias[$fecha] = dia_vacio($frecuencias);
    }
    $data[$cob['id']] = [
        'nombre'   => $cob['nombre'],
        'apellido' => $cob['apellido'],
        'dias'     => $dias,
        'totales'  => array_merge(dia_vacio($frecuencias), ['mora' => 0.0]),
    ];
}

// Poblar agenda
foreach ($agenda_raw as $row) {
    $cid   = (int) $row['cobrador_id'];
    $fecha = $row['fecha_vencimiento'];
    $freq  = $row['frecuencia'];
    if (!isset($data[$cid]['dias'][$fecha])) continue;

    $ag = (int)   $row['cuotas_agendadas'];
    $me = (float) $row['monto_estimado'];

    $data[$cid]['dias'][$fecha]['agendados']                      += $ag;
    $data[$cid]['dias'][$fecha]['monto_estimado']                 += $me;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['agendados']   += $ag;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['monto_estimado'] += $me;

    $data[$cid]['totales']['agendados']                           += $ag;
    $data[$cid]['totales']['monto_estimado']                      += $me;
    $data[$cid]['totales']['por_tipo'][$freq]['agendados']        += $ag;
    $data[$cid]['totales']['por_tipo'][$freq]['monto_estimado']   += $me;
}

// Poblar cobros
foreach ($cobros_raw as $row) {
    $cid   = (int) $row['cobrador_id'];
    $fecha = $row['fecha_jornada'];
    $freq  = $row['frecuencia'];
    if (!isset($data[$cid]['dias'][$fecha])) continue;

    $co  = (int)   $row['cuotas_cobradas'];
    $mc  = (float) $row['monto_cobrado'];
    $ef  = (float) $row['efectivo'];
    $tr  = (float) $row['transferencia'];
    $mor = (float) $row['mora_cobrada'];

    $data[$cid]['dias'][$fecha]['cobrados']                          += $co;
    $data[$cid]['dias'][$fecha]['monto_cobrado']                     += $mc;
    $data[$cid]['dias'][$fecha]['efectivo']                          += $ef;
    $data[$cid]['dias'][$fecha]['transferencia']                     += $tr;
    $data[$cid]['dias'][$fecha]['mora']                              += $mor;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['cobrados']       += $co;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['monto_cobrado']  += $mc;

    $data[$cid]['totales']['cobrados']                               += $co;
    $data[$cid]['totales']['monto_cobrado']                          += $mc;
    $data[$cid]['totales']['efectivo']                               += $ef;
    $data[$cid]['totales']['transferencia']                          += $tr;
    $data[$cid]['totales']['mora']                                   += $mor;
    $data[$cid]['totales']['por_tipo'][$freq]['cobrados']            += $co;
    $data[$cid]['totales']['por_tipo'][$freq]['monto_cobrado']       += $mc;
}

// ── KPIs globales ─────────────────────────────────────────────
$g_cobrado   = 0.0;
$g_estimado  = 0.0;
$g_cobrados  = 0;
$g_agendados = 0;
foreach ($data as $cob) {
    $g_cobrado   += $cob['totales']['monto_cobrado'];
    $g_estimado  += $cob['totales']['monto_estimado'];
    $g_cobrados  += $cob['totales']['cobrados'];
    $g_agendados += $cob['totales']['agendados'];
}
$g_efectividad = $g_agendados > 0 ? round($g_cobrados / $g_agendados * 100) : 0;
$g_recaudacion = $g_estimado  > 0 ? round($g_cobrado  / $g_estimado  * 100) : 0;

$page_title   = 'Estadísticas de Cobranza';
$page_current = 'estadisticas';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── SELECTOR DE SEMANA ─────────────────────────────────────── -->
<div class="card-ic mb-4">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <a href="?semana=<?= urlencode($semana_ant_str) ?>"
           class="btn-ic btn-ghost btn-sm" title="Semana anterior">
            <i class="fa fa-chevron-left"></i>
        </a>
        <div style="flex:1;text-align:center">
            <div style="font-size:1rem;font-weight:700;color:var(--text)">
                <?= $lunes_sel->format('d/m/Y') ?> — <?= $sabado_sel->format('d/m/Y') ?>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                <?php if ($es_semana_actual): ?>
                    <span style="color:var(--success)"><i class="fa fa-circle" style="font-size:.5rem;vertical-align:middle"></i> Semana actual</span>
                <?php else: ?>
                    Semana <?= $lunes_sel->format('W') ?> de <?= $lunes_sel->format('Y') ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$es_semana_actual): ?>
        <a href="?semana=<?= urlencode($semana_sig_str) ?>"
           class="btn-ic btn-ghost btn-sm" title="Semana siguiente">
            <i class="fa fa-chevron-right"></i>
        </a>
        <?php else: ?>
        <span class="btn-ic btn-ghost btn-sm" style="opacity:.3;cursor:default;pointer-events:none">
            <i class="fa fa-chevron-right"></i>
        </span>
        <?php endif; ?>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <input type="date" name="semana" value="<?= e($inicio_str) ?>"
                   style="min-width:150px" max="<?= $lunes_actual->format('Y-m-d') ?>">
            <button type="submit" class="btn-ic btn-ghost btn-sm">
                <i class="fa fa-search"></i> Ir
            </button>
        </form>
        <?php if (!$es_semana_actual): ?>
        <a href="estadisticas_cobranza" class="btn-ic btn-primary btn-sm">
            <i class="fa fa-calendar-check"></i> Semana actual
        </a>
        <?php endif; ?>
        <a href="estadisticas_pdf?semana=<?= urlencode($inicio_str) ?>"
           class="btn-ic btn-ghost btn-sm" target="_blank" title="Exportar estadísticas a PDF">
            <i class="fa fa-file-pdf"></i> Exportar PDF
        </a>
    </div>
</div>

<!-- ── KPIs GLOBALES ─────────────────────────────────────────── -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <i class="fa fa-sack-dollar kpi-icon"></i>
        <div class="kpi-label">Total Cobrado</div>
        <div class="kpi-value" style="color:var(--success);font-size:1.15rem"><?= formato_pesos($g_cobrado) ?></div>
        <div class="kpi-sub">Efc: <?= formato_pesos(array_sum(array_column(array_column($data, 'totales'), 'efectivo'))) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <i class="fa fa-dollar-sign kpi-icon"></i>
        <div class="kpi-label">Estimado</div>
        <div class="kpi-value" style="font-size:1.15rem"><?= formato_pesos($g_estimado) ?></div>
        <div class="kpi-sub">
            <span style="color:<?= $g_recaudacion >= 80 ? 'var(--success)' : ($g_recaudacion >= 50 ? 'var(--warning)' : 'var(--danger)') ?>">
                <?= $g_recaudacion ?>% recaudado
            </span>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <i class="fa fa-receipt kpi-icon"></i>
        <div class="kpi-label">Efectividad</div>
        <div class="kpi-value" style="color:<?= $g_efectividad >= 80 ? 'var(--success)' : ($g_efectividad >= 50 ? 'var(--warning)' : 'var(--danger)') ?>">
            <?= $g_efectividad ?>%
        </div>
        <div class="kpi-sub"><?= $g_cobrados ?> de <?= $g_agendados ?> cuotas cobradas</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <i class="fa fa-users kpi-icon"></i>
        <div class="kpi-label">Cobradores</div>
        <div class="kpi-value"><?= count($cobradores) ?></div>
        <div class="kpi-sub">activos en el sistema</div>
    </div>
</div>

<!-- ── TARJETAS POR COBRADOR ─────────────────────────────────── -->
<?php if (empty($cobradores)): ?>
    <div class="card-ic"><p class="text-muted text-center" style="padding:30px">No hay cobradores activos.</p></div>
<?php endif; ?>

<?php foreach ($data as $cob_id => $cob): ?>
<?php
    $tot   = $cob['totales'];
    $efect = $tot['agendados'] > 0 ? round($tot['cobrados']      / $tot['agendados']      * 100) : 0;
    $recau = $tot['monto_estimado'] > 0 ? round($tot['monto_cobrado'] / $tot['monto_estimado'] * 100) : 0;
    $sin_actividad = ($tot['cobrados'] === 0 && $tot['agendados'] === 0);
    $color_efect = $efect >= 80 ? 'var(--success)' : ($efect >= 50 ? 'var(--warning)' : 'var(--danger)');
    $cob_slug = 'cob-' . $cob_id;
?>
<div class="card-ic mb-4" style="<?= $sin_actividad ? 'opacity:.55' : '' ?>">

    <!-- HEADER DEL COBRADOR -->
    <div class="card-ic-header" style="cursor:pointer" onclick="toggleCollapse('det-<?= $cob_slug ?>','ico-<?= $cob_slug ?>')">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;flex:1">
            <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;flex-shrink:0">
                <?= strtoupper(mb_substr($cob['nombre'], 0, 1) . mb_substr($cob['apellido'], 0, 1)) ?>
            </div>
            <div>
                <div style="font-weight:700;font-size:1rem"><?= e($cob['apellido'] . ', ' . $cob['nombre']) ?></div>
                <?php if ($sin_actividad): ?>
                    <div style="font-size:.75rem;color:var(--text-muted)">Sin actividad esta semana</div>
                <?php else: ?>
                    <div style="font-size:.75rem;color:var(--text-muted)">
                        <?= $tot['cobrados'] ?> cuota(s) cobrada(s) de <?= $tot['agendados'] ?> agendada(s)
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!$sin_actividad): ?>
            <!-- Mini barra de efectividad -->
            <div style="flex:1;min-width:120px;max-width:200px">
                <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-muted);margin-bottom:3px">
                    <span>Efectividad</span>
                    <span style="color:<?= $color_efect ?>;font-weight:700"><?= $efect ?>%</span>
                </div>
                <div style="background:rgba(255,255,255,.1);border-radius:99px;height:6px;overflow:hidden">
                    <div style="width:<?= $efect ?>%;height:100%;background:<?= $color_efect ?>;border-radius:99px;transition:width .4s"></div>
                </div>
            </div>
            <!-- Totales rápidos -->
            <div style="margin-left:auto;text-align:right;display:flex;gap:20px;align-items:center">
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted)">Cobrado</div>
                    <div style="font-weight:800;color:var(--success);font-size:.95rem"><?= formato_pesos($tot['monto_cobrado']) ?></div>
                </div>
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted)">Estimado</div>
                    <div style="font-weight:700;font-size:.9rem"><?= formato_pesos($tot['monto_estimado']) ?></div>
                </div>
                <?php if ($tot['mora'] > 0): ?>
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted)">Mora</div>
                    <div style="font-weight:700;color:var(--warning);font-size:.9rem"><?= formato_pesos($tot['mora']) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <i class="fa fa-chevron-down" id="ico-<?= $cob_slug ?>"
           style="margin-left:16px;transition:transform .2s;color:var(--text-muted)"></i>
    </div>

    <!-- DETALLE EXPANDIBLE -->
    <div id="det-<?= $cob_slug ?>" style="<?= $sin_actividad ? 'display:none' : 'display:block' ?>">
        <div style="overflow-x:auto">
            <table class="table-ic" style="min-width:680px">
                <thead>
                    <tr>
                        <th style="width:110px">Día</th>
                        <th style="text-align:center">Agenda</th>
                        <th style="text-align:center">Cobradas</th>
                        <th style="min-width:160px">Efectividad</th>
                        <th style="text-align:right">Estimado</th>
                        <th style="text-align:right">Cobrado</th>
                        <th style="text-align:right">Efectivo</th>
                        <th style="text-align:right">Transf.</th>
                        <th style="text-align:right">Mora</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dias_semana as $fecha => $dow_num): ?>
                <?php
                    $d = $cob['dias'][$fecha];
                    $pct = $d['agendados'] > 0 ? round($d['cobrados'] / $d['agendados'] * 100) : 0;
                    $c_bar = $pct >= 80 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : ($d['cobrados'] > 0 ? 'var(--danger)' : 'rgba(255,255,255,.12)'));
                    $es_hoy = ($fecha === $hoy->format('Y-m-d'));
                    $en_futuro = ($fecha > $hoy->format('Y-m-d'));
                ?>
                <tr style="<?= $es_hoy ? 'background:rgba(79,70,229,.1)' : ($en_futuro ? 'opacity:.45' : '') ?>">
                    <td>
                        <div style="font-weight:<?= $es_hoy ? '800' : '600' ?>;font-size:.88rem">
                            <?= $nombres_dia[$dow_num] ?>
                            <?php if ($es_hoy): ?>
                                <span style="font-size:.65rem;color:var(--primary);vertical-align:middle;margin-left:4px">HOY</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:.72rem;color:var(--text-muted)"><?= date('d/m', strtotime($fecha)) ?></div>
                    </td>
                    <td style="text-align:center;font-weight:700"><?= $d['agendados'] ?: '—' ?></td>
                    <td style="text-align:center">
                        <?php if ($d['cobrados'] > 0): ?>
                            <span style="font-weight:800;color:var(--success)"><?= $d['cobrados'] ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($d['agendados'] > 0): ?>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;background:rgba(255,255,255,.1);border-radius:99px;height:8px;overflow:hidden;min-width:60px">
                                <div style="width:<?= $pct ?>%;height:100%;background:<?= $c_bar ?>;border-radius:99px;transition:width .4s"></div>
                            </div>
                            <span style="font-size:.78rem;font-weight:700;color:<?= $c_bar ?>;min-width:34px;text-align:right"><?= $pct ?>%</span>
                        </div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:.78rem">Sin agenda</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-size:.85rem"><?= $d['monto_estimado'] > 0 ? formato_pesos($d['monto_estimado']) : '—' ?></td>
                    <td style="text-align:right;font-weight:700;color:<?= $d['monto_cobrado'] > 0 ? 'var(--success)' : 'inherit' ?>">
                        <?= $d['monto_cobrado'] > 0 ? formato_pesos($d['monto_cobrado']) : '—' ?>
                    </td>
                    <td style="text-align:right;font-size:.82rem;color:var(--text-muted)"><?= $d['efectivo'] > 0 ? formato_pesos($d['efectivo']) : '—' ?></td>
                    <td style="text-align:right;font-size:.82rem;color:var(--text-muted)"><?= $d['transferencia'] > 0 ? formato_pesos($d['transferencia']) : '—' ?></td>
                    <td style="text-align:right;font-size:.82rem;color:<?= $d['mora'] > 0 ? 'var(--warning)' : 'inherit' ?>">
                        <?= $d['mora'] > 0 ? formato_pesos($d['mora']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <!-- TOTALES DE LA SEMANA -->
                <tfoot>
                    <tr style="background:rgba(79,70,229,.15);font-weight:800">
                        <td colspan="3" style="font-size:.8rem;letter-spacing:.04em;text-align:right;padding-right:10px">SEMANA</td>
                        <td>
                            <?php if ($tot['agendados'] > 0): ?>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;background:rgba(255,255,255,.12);border-radius:99px;height:8px;overflow:hidden">
                                    <div style="width:<?= $efect ?>%;height:100%;background:<?= $color_efect ?>;border-radius:99px"></div>
                                </div>
                                <span style="font-size:.78rem;font-weight:800;color:<?= $color_efect ?>;min-width:34px;text-align:right"><?= $efect ?>%</span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right"><?= formato_pesos($tot['monto_estimado']) ?></td>
                        <td style="text-align:right;color:var(--success)"><?= formato_pesos($tot['monto_cobrado']) ?></td>
                        <td style="text-align:right;font-size:.85rem"><?= formato_pesos($tot['efectivo']) ?></td>
                        <td style="text-align:right;font-size:.85rem"><?= formato_pesos($tot['transferencia']) ?></td>
                        <td style="text-align:right;color:var(--warning)"><?= $tot['mora'] > 0 ? formato_pesos($tot['mora']) : '—' ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- DESGLOSE POR TIPO DE CUOTA -->
        <?php
        $tipos_activos = array_filter($frecuencias, fn($f) => $tot['por_tipo'][$f]['agendados'] > 0 || $tot['por_tipo'][$f]['cobrados'] > 0);
        ?>
        <?php if (!empty($tipos_activos)): ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;padding:14px 16px 16px;border-top:1px solid rgba(255,255,255,.06)">
            <div style="font-size:.75rem;color:var(--text-muted);width:100%;margin-bottom:2px">
                <i class="fa fa-layer-group"></i> Desglose por tipo de cuota
            </div>
            <?php foreach ($tipos_activos as $freq): ?>
            <?php
                $tf     = $tot['por_tipo'][$freq];
                $pct_f  = $tf['agendados'] > 0 ? round($tf['cobrados'] / $tf['agendados'] * 100) : 0;
                $c_f    = $pct_f >= 80 ? 'var(--success)' : ($pct_f >= 50 ? 'var(--warning)' : 'var(--danger)');
                $label  = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'][$freq];
                $icon   = ['semanal' => 'fa-calendar-week', 'quincenal' => 'fa-calendar-days', 'mensual' => 'fa-calendar'][$freq];
            ?>
            <div style="flex:1;min-width:170px;background:rgba(0,0,0,.2);border-radius:10px;padding:12px 14px;border:1px solid rgba(255,255,255,.07)">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px">
                    <i class="fa <?= $icon ?>" style="color:var(--primary);font-size:.85rem"></i>
                    <span style="font-size:.8rem;font-weight:700;color:var(--text)"><?= $label ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:6px">
                    <div style="font-size:.75rem;color:var(--text-muted)">
                        <?= $tf['cobrados'] ?> / <?= $tf['agendados'] ?> cuotas
                    </div>
                    <div style="font-size:.82rem;font-weight:800;color:<?= $c_f ?>"><?= $pct_f ?>%</div>
                </div>
                <div style="background:rgba(255,255,255,.1);border-radius:99px;height:5px;overflow:hidden;margin-bottom:8px">
                    <div style="width:<?= $pct_f ?>%;height:100%;background:<?= $c_f ?>;border-radius:99px"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.75rem">
                    <span style="color:var(--text-muted)">Estimado: <?= formato_pesos($tf['monto_estimado']) ?></span>
                    <span style="color:var(--success);font-weight:700"><?= formato_pesos($tf['monto_cobrado']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- /det-cob -->
</div><!-- /card-ic -->
<?php endforeach; ?>

<?php
$page_scripts = <<<'JS'
<script>
function toggleCollapse(colId, iconId) {
    const col  = document.getElementById(colId);
    const icon = document.getElementById(iconId);
    if (!col) return;
    const abierto = col.style.display !== 'none';
    col.style.display = abierto ? 'none' : 'block';
    if (icon) {
        icon.style.transform = abierto ? 'rotate(-90deg)' : '';
    }
}
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
