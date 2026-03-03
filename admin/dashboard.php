<?php
// ============================================================
// admin/dashboard.php — Panel Principal
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Stats principales ─────────────────────────────────────────
$clientes_activos  = (int)$pdo->query("SELECT COUNT(*) FROM ic_clientes WHERE estado='ACTIVO'")->fetchColumn();
$total_clientes    = (int)$pdo->query("SELECT COUNT(*) FROM ic_clientes")->fetchColumn();
$creditos_en_curso = (int)$pdo->query("SELECT COUNT(*) FROM ic_creditos WHERE estado='EN_CURSO'")->fetchColumn();
$total_creditos    = (int)$pdo->query("SELECT COUNT(*) FROM ic_creditos")->fetchColumn();
$cobrado_hoy       = (float)$pdo->query("SELECT COALESCE(SUM(monto_total),0) FROM ic_pagos_confirmados WHERE fecha_pago=CURDATE()")->fetchColumn();
$pagos_hoy         = (int)$pdo->query("SELECT COUNT(*) FROM ic_pagos_confirmados WHERE fecha_pago=CURDATE()")->fetchColumn();
$cobrado_mes       = (float)$pdo->query("SELECT COALESCE(SUM(monto_total),0) FROM ic_pagos_confirmados WHERE fecha_pago>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
$rend_pend         = (int)$pdo->query("SELECT COUNT(DISTINCT cobrador_id) FROM ic_pagos_temporales WHERE estado='PENDIENTE'")->fetchColumn();

// ── Solicitudes de anulación pendientes (solo admin) ──────────
$sol_baja_items = [];
if (es_admin()) {
    // Pagos temporales con solicitud de baja
    $stmt_sol = $pdo->query("
        SELECT 'temporal' AS tipo, pt.id, pt.motivo_baja, pt.fecha_registro AS fecha_cobro,
               cu.numero_cuota, cr.id AS credito_id,
               CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
               CONCAT(u.nombre, ' ', u.apellido) AS supervisor
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_usuarios u ON pt.cobrador_id = u.id
        WHERE pt.solicitud_baja = 1
        ORDER BY pt.id DESC LIMIT 10
    ");
    $sol_baja_items = array_merge($sol_baja_items, $stmt_sol->fetchAll());

    // Pagos confirmados con solicitud de reversa
    $stmt_sol2 = $pdo->query("
        SELECT 'confirmado' AS tipo, pc.id, pc.motivo_baja, pc.fecha_pago AS fecha_cobro,
               cu.numero_cuota, cr.id AS credito_id,
               CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
               CONCAT(u.nombre, ' ', u.apellido) AS supervisor
        FROM ic_pagos_confirmados pc
        JOIN ic_cuotas cu ON pc.cuota_id = cu.id
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_usuarios u ON pc.cobrador_id = u.id
        WHERE pc.solicitud_baja = 1
        ORDER BY pc.id DESC LIMIT 10
    ");
    $sol_baja_items = array_merge($sol_baja_items, $stmt_sol2->fetchAll());
}

// ── Cartera ───────────────────────────────────────────────────
$cartera = $pdo->query("
    SELECT
        COUNT(CASE WHEN cu.estado='PAGADA' THEN 1 END) AS pagadas,
        COUNT(CASE WHEN cu.estado IN('PENDIENTE','PARCIAL') AND cu.fecha_vencimiento>=CURDATE() THEN 1 END) AS vigentes,
        COUNT(CASE WHEN cu.estado IN('PENDIENTE','VENCIDA') AND cu.fecha_vencimiento<CURDATE() THEN 1 END)  AS vencidas,
        COALESCE(SUM(CASE WHEN cu.estado!='PAGADA' THEN cu.monto_cuota END),0) AS deuda_total
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id=cr.id
    WHERE cr.estado='EN_CURSO'
")->fetch();

// ── Gauges (porcentaje para los indicadores circulares) ───────
$g_clientes = $total_clientes  > 0 ? min(99,(int)round($clientes_activos  / $total_clientes  * 100)) : 0;
$g_creditos = $total_creditos  > 0 ? min(99,(int)round($creditos_en_curso / $total_creditos  * 100)) : 0;
$total_pend = $cartera['vigentes'] + $cartera['vencidas'];
$g_al_dia   = $total_pend > 0 ? min(99,(int)round($cartera['vigentes'] / $total_pend * 100)) : 100;
$g_cobro    = $cobrado_mes > 0 ? min(99,(int)round($cobrado_hoy / $cobrado_mes * 100)) : 0;

// ── Semana de cobros: Lunes → Sábado ──────────────────────────
$dow_hoy       = (int)date('N');                          // 1=Lun … 6=Sáb, 7=Dom
$dias_al_lunes = ($dow_hoy === 7) ? 6 : ($dow_hoy - 1);  // Dom retrocede a sem. anterior
$semana_inicio = date('Y-m-d', strtotime("-{$dias_al_lunes} days"));
$semana_fin    = date('Y-m-d', strtotime($semana_inicio . ' +5 days'));

// ── Cobros gráfico: 6 días Lun–Sáb de la semana actual ────────
$dias_es    = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
$chart_labels = [];
$chart_days   = [];
for ($i = 0; $i < 6; $i++) {
    $d      = date('Y-m-d', strtotime($semana_inicio . " +$i days"));
    $dow_d  = (int)date('N', strtotime($d));
    $chart_labels[] = $dias_es[$dow_d] . ' ' . date('d/m', strtotime($d));
    $chart_days[]   = $d;
}

// Trae totales agrupados por día y cobrador
$stmt_cob = $pdo->prepare("
    SELECT DATE(pc.fecha_pago) AS d,
           pc.cobrador_id,
           CONCAT(u.nombre, ' ', u.apellido) AS nombre_cob,
           COALESCE(SUM(pc.monto_total),0) AS total
    FROM ic_pagos_confirmados pc
    JOIN ic_usuarios u ON pc.cobrador_id = u.id
    WHERE pc.fecha_pago BETWEEN ? AND ?
    GROUP BY DATE(pc.fecha_pago), pc.cobrador_id
    ORDER BY pc.cobrador_id
");
$stmt_cob->execute([$semana_inicio, $semana_fin]);
$rows_cob = $stmt_cob->fetchAll();

// Construir estructura: cobrador_id → [nombre, mapa de días]
$cob_data  = [];
foreach ($rows_cob as $r) {
    $cid = $r['cobrador_id'];
    if (!isset($cob_data[$cid])) {
        $cob_data[$cid] = ['nombre' => $r['nombre_cob'], 'dias' => []];
    }
    $cob_data[$cid]['dias'][$r['d']] = (float)$r['total'];
}

// Armar datasets por cobrador
$chart_datasets_php = [];
foreach ($cob_data as $cid => $info) {
    $vals = [];
    foreach ($chart_days as $d) {
        $vals[] = $info['dias'][$d] ?? 0;
    }
    $chart_datasets_php[] = ['label' => $info['nombre'], 'data' => $vals];
}

// Total por día
$chart_map = array_fill_keys($chart_days, 0);
foreach ($rows_cob as $r) {
    if (array_key_exists($r['d'], $chart_map)) $chart_map[$r['d']] += (float)$r['total'];
}
$chart_vals = array_values($chart_map);

// Total cobrado en la semana (para encabezado del gráfico)
$stmt_sem = $pdo->prepare("SELECT COALESCE(SUM(monto_total),0) FROM ic_pagos_confirmados WHERE fecha_pago BETWEEN ? AND ?");
$stmt_sem->execute([$semana_inicio, $semana_fin]);
$cobrado_semana = (float)$stmt_sem->fetchColumn();

// ── Ranking cobradores de la semana (Lun–Sáb) ────────────────
$stmt_rank = $pdo->prepare("
    SELECT u.nombre, u.apellido, COUNT(*) AS pagos, COALESCE(SUM(pc.monto_total),0) AS total
    FROM ic_pagos_confirmados pc
    JOIN ic_usuarios u ON pc.cobrador_id = u.id
    WHERE pc.fecha_pago BETWEEN ? AND ?
    GROUP BY pc.cobrador_id ORDER BY total DESC LIMIT 5
");
$stmt_rank->execute([$semana_inicio, $semana_fin]);
$cobradores_mes = $stmt_rank->fetchAll();
$max_cob = count($cobradores_mes) ? (float)$cobradores_mes[0]['total'] : 1;

// ── Últimos pagos aprobados ───────────────────────────────────
$ultimos_pagos = $pdo->query("
    SELECT pc.monto_total, pc.monto_efectivo, pc.monto_transferencia,
           pc.fecha_aprobacion, cl.nombres, cl.apellidos, u.nombre cobrador_n
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu ON pc.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_usuarios u ON pc.cobrador_id = u.id
    ORDER BY pc.fecha_aprobacion DESC LIMIT 6
")->fetchAll();

$page_title   = 'Dashboard';
$page_current = 'dashboard';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($sol_baja_items)): ?>
<div class="alert-ic alert-danger mb-4" style="flex-direction:column;align-items:flex-start;gap:14px">
    <div style="display:flex;align-items:center;gap:10px;width:100%">
        <i class="fa fa-triangle-exclamation fa-lg"></i>
        <strong>
            <?= count($sol_baja_items) ?> solicitud<?= count($sol_baja_items) !== 1 ? 'es' : '' ?> de anulación de pago pendiente<?= count($sol_baja_items) !== 1 ? 's' : '' ?> de revisión.
        </strong>
        <a href="rendiciones" style="color:inherit;text-decoration:underline;margin-left:auto">Ir a Rendiciones →</a>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.8rem">
        <thead>
            <tr style="opacity:.7;border-bottom:1px solid rgba(255,255,255,.15)">
                <th style="padding:4px 8px 4px 0;font-weight:600;text-align:left">Cliente</th>
                <th style="padding:4px 8px;font-weight:600;text-align:center">Cuota</th>
                <th style="padding:4px 8px;font-weight:600;text-align:center">Tipo</th>
                <th style="padding:4px 8px;font-weight:600;text-align:left">Motivo</th>
                <th style="padding:4px 8px;font-weight:600;text-align:center">Fecha</th>
                <th style="padding:4px 0 4px 8px;font-weight:600;text-align:center">Acción</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sol_baja_items as $s): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
                <td style="padding:5px 8px 5px 0">
                    <a href="<?= BASE_URL ?>creditos/ver?id=<?= (int)$s['credito_id'] ?>" style="color:inherit;text-decoration:underline">
                        <?= e($s['cliente']) ?>
                    </a>
                </td>
                <td style="padding:5px 8px;text-align:center">#<?= (int)$s['numero_cuota'] ?></td>
                <td style="padding:5px 8px;text-align:center">
                    <?php if ($s['tipo'] === 'confirmado'): ?>
                        <span class="badge-ic badge-danger">Confirmado</span>
                    <?php else: ?>
                        <span class="badge-ic badge-warning">Temporal</span>
                    <?php endif; ?>
                </td>
                <td style="padding:5px 8px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= e($s['motivo_baja']) ?>">
                    <?= e($s['motivo_baja']) ?>
                </td>
                <td style="padding:5px 8px;text-align:center;white-space:nowrap">
                    <?= date('d/m/Y', strtotime($s['fecha_cobro'])) ?>
                </td>
                <td style="padding:5px 0 5px 8px;text-align:center">
                    <a href="<?= BASE_URL ?>creditos/ver?id=<?= (int)$s['credito_id'] ?>"
                       class="btn-ic btn-danger btn-sm">Revisar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($rend_pend > 0): ?>
<div class="alert-ic alert-warning mb-4">
    <i class="fa fa-bell"></i>
    <strong><?= $rend_pend ?> cobrador<?= $rend_pend !== 1 ? 'es tienen' : ' tiene' ?> rendiciones pendientes de aprobación.</strong>
    <a href="rendiciones" style="color:inherit;text-decoration:underline;margin-left:12px">Ir a Rendiciones →</a>
</div>
<?php endif; ?>

<!-- ── KPI Cards estilo TailPanel ─────────────────────────────── -->
<div class="db-kpi-grid mb-4">

    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981">
            <i class="fa fa-users"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Clientes Activos</div>
            <div class="kpi-value"><?= number_format($clientes_activos) ?></div>
            <div class="kpi-sub">
                de <?= number_format($total_clientes) ?> registrados
                <span class="kpi-pct" style="color:var(--success)">↑ <?= $g_clientes ?>%</span>
            </div>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--primary-light)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(144,153,232,.15);--icon-color:#9099e8">
            <i class="fa fa-file-invoice-dollar"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Créditos en Curso</div>
            <div class="kpi-value"><?= number_format($creditos_en_curso) ?></div>
            <div class="kpi-sub">
                de <?= number_format($total_creditos) ?> totales
                <span class="kpi-pct" style="color:var(--primary-light)">↑ <?= $g_creditos ?>%</span>
            </div>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(6,182,212,.15);--icon-color:#06b6d4">
            <i class="fa fa-dollar-sign"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cobrado Hoy</div>
            <div class="kpi-value" style="font-size:1.45rem"><?= formato_pesos($cobrado_hoy) ?></div>
            <div class="kpi-sub">
                <?= $pagos_hoy ?> pago<?= $pagos_hoy !== 1 ? 's' : '' ?> aprobado<?= $pagos_hoy !== 1 ? 's' : '' ?>
                <?php if ($g_cobro > 0): ?>
                    <span class="kpi-pct" style="color:var(--accent)">↑ <?= $g_cobro ?>% del mes</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(245,158,11,.15);--icon-color:#f59e0b">
            <i class="fa fa-clock"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cuotas al Día</div>
            <div class="kpi-value"><?= number_format($cartera['vigentes']) ?></div>
            <div class="kpi-sub">
                <span style="color:var(--danger)"><?= number_format($cartera['vencidas']) ?> vencidas</span>
                <span class="kpi-pct" style="color:var(--warning)">· <?= $g_al_dia ?>% vigente</span>
            </div>
        </div>
    </div>

</div>

<!-- ── Gráficos ───────────────────────────────────────────────── -->
<div class="db-charts-grid mb-4">

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-bar"></i> Cobros — Semana Actual</span>
            <span class="text-muted" style="font-size:.8rem">
                <?= date('d/m', strtotime($semana_inicio)) ?> – <?= date('d/m', strtotime($semana_fin)) ?>:
                <strong style="color:var(--primary-light)"><?= formato_pesos($cobrado_semana) ?></strong>
            </span>
        </div>
        <div style="position:relative;height:260px;padding:6px 0">
            <canvas id="chart-cobros"></canvas>
        </div>
    </div>

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-pie"></i> Estado Cartera</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;padding:8px 0 14px">
            <div style="position:relative;height:190px;width:190px">
                <canvas id="chart-cartera"></canvas>
                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;text-align:center">
                    <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Deuda</div>
                    <div style="font-size:.88rem;font-weight:800;color:var(--primary-light);line-height:1.2"><?= formato_pesos($cartera['deuda_total']) ?></div>
                </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:14px;margin-top:14px;font-size:.75rem">
                <span style="display:flex;align-items:center;gap:5px">
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#10b981;flex-shrink:0"></span>
                    Vigentes (<?= number_format($cartera['vigentes']) ?>)
                </span>
                <span style="display:flex;align-items:center;gap:5px">
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#ef4444;flex-shrink:0"></span>
                    Vencidas (<?= number_format($cartera['vencidas']) ?>)
                </span>
                <span style="display:flex;align-items:center;gap:5px">
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:rgba(255,255,255,.2);flex-shrink:0"></span>
                    Pagadas (<?= number_format($cartera['pagadas']) ?>)
                </span>
            </div>
        </div>
    </div>

</div>

<!-- ── Panel inferior ─────────────────────────────────────────── -->
<div class="db-bottom-grid mb-4">

    <!-- Ranking cobradores con barras de progreso -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-trophy"></i> Ranking de la Semana</span>
            <span class="text-muted" style="font-size:.78rem">
                <?= date('d/m', strtotime($semana_inicio)) ?> – <?= date('d/m', strtotime($semana_fin)) ?>
            </span>
        </div>
        <?php if (empty($cobradores_mes)): ?>
            <p class="text-muted text-center" style="padding:24px">Sin cobros esta semana.</p>
        <?php else: ?>
            <?php
            $medals = ['🥇', '🥈', '🥉', '4°', '5°'];
            foreach ($cobradores_mes as $i => $cob):
                $pct = $max_cob > 0 ? round($cob['total'] / $max_cob * 100) : 0;
            ?>
            <div style="padding:12px 0;<?= $i < count($cobradores_mes) - 1 ? 'border-bottom:1px solid var(--dark-border)' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px">
                    <span style="font-weight:600;font-size:.87rem">
                        <?= $medals[$i] ?? ($i + 1) . '°' ?> <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                    </span>
                    <span style="display:flex;align-items:center;gap:8px">
                        <span class="text-muted" style="font-size:.72rem"><?= $cob['pagos'] ?> pago<?= $cob['pagos'] !== 1 ? 's' : '' ?></span>
                        <strong style="color:var(--primary-light);font-size:.87rem"><?= formato_pesos($cob['total']) ?></strong>
                    </span>
                </div>
                <div style="height:4px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--primary),var(--primary-light));border-radius:2px;transition:width .8s ease"></div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Últimos pagos como lista -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-check-double"></i> Últimos Pagos</span>
            <a href="rendiciones" class="btn-ic btn-ghost btn-sm">Ver Rendiciones</a>
        </div>
        <?php if (empty($ultimos_pagos)): ?>
            <p class="text-muted text-center" style="padding:24px">Sin pagos aprobados.</p>
        <?php else: ?>
            <?php foreach ($ultimos_pagos as $idx => $p): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 0;<?= $idx < count($ultimos_pagos) - 1 ? 'border-bottom:1px solid var(--dark-border)' : '' ?>">
                <div style="min-width:0;flex:1;margin-right:12px">
                    <div class="fw-bold" style="font-size:.87rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= e($p['apellidos'] . ', ' . $p['nombres']) ?>
                    </div>
                    <div class="text-muted" style="font-size:.73rem">
                        <?= e($p['cobrador_n']) ?> · <?= date('d/m H:i', strtotime($p['fecha_aprobacion'])) ?>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;flex-shrink:0">
                    <strong style="color:var(--success);font-size:.95rem"><?= formato_pesos($p['monto_total']) ?></strong>
                    <?php if ($p['monto_transferencia'] > 0): ?>
                        <span class="text-muted" style="font-size:.7rem">transf. <?= formato_pesos($p['monto_transferencia']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php
$js_labels    = json_encode($chart_labels);
$js_vals      = json_encode($chart_vals);
$js_datasets  = json_encode($chart_datasets_php);
$js_vigentes  = (int)$cartera['vigentes'];
$js_vencidas  = (int)$cartera['vencidas'];
$js_pagadas   = (int)$cartera['pagadas'];

$page_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color       = 'rgba(255,255,255,.6)';
Chart.defaults.borderColor = 'rgba(255,255,255,.07)';
Chart.defaults.font.family = "'Sarabun', sans-serif";
Chart.defaults.font.size   = 13;

// ── Paleta de colores para cobradores ─────────────────────────
const COB_COLORS = [
    { bg: 'rgba(103,112,210,.75)', border: '#6770d2' },
    { bg: 'rgba(16,185,129,.75)',  border: '#10b981' },
    { bg: 'rgba(6,182,212,.75)',   border: '#06b6d4' },
    { bg: 'rgba(245,158,11,.75)',  border: '#f59e0b' },
    { bg: 'rgba(239,68,68,.75)',   border: '#ef4444' },
    { bg: 'rgba(168,85,247,.75)',  border: '#a855f7' },
    { bg: 'rgba(249,115,22,.75)',  border: '#f97316' },
    { bg: 'rgba(236,72,153,.75)',  border: '#ec4899' },
];

// ── Barras apiladas: Cobros por cobrador últimos 7 días ────────
const rawDatasets = $js_datasets;
const labels      = $js_labels;
const totales     = $js_vals;

const datasets = rawDatasets.map((ds, i) => {
    const c = COB_COLORS[i % COB_COLORS.length];
    return {
        label: ds.label,
        data: ds.data,
        backgroundColor: c.bg,
        borderColor: c.border,
        borderWidth: 1,
        borderRadius: 4,
        borderSkipped: false,
        stack: 'cobros',
    };
});

// Línea de total diario
datasets.push({
    type: 'line',
    label: 'Total',
    data: totales,
    borderColor: 'rgba(255,255,255,.5)',
    borderWidth: 2,
    borderDash: [4,3],
    pointBackgroundColor: 'rgba(255,255,255,.8)',
    pointRadius: 4,
    tension: 0.35,
    fill: false,
    stack: undefined,
    order: 0,
});

new Chart(document.getElementById('chart-cobros'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: rawDatasets.length > 0,
                position: 'bottom',
                labels: {
                    boxWidth: 12,
                    boxHeight: 12,
                    borderRadius: 3,
                    useBorderRadius: true,
                    padding: 14,
                    font: { size: 12 },
                    filter: item => item.text !== 'Total',
                }
            },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        if (ctx.raw === 0) return null;
                        return ' ' + ctx.dataset.label + ': \$ ' +
                            ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false }, stacked: true },
            y: {
                stacked: true,
                grid: { color: 'rgba(255,255,255,.06)' },
                ticks: {
                    font: { size: 12 },
                    callback: v => v >= 1000 ? '\$' + (v/1000).toFixed(0)+'k' : '\$'+v
                }
            }
        }
    }
});

// ── Doughnut: Estado cartera ──────────────────────────────────
new Chart(document.getElementById('chart-cartera'), {
    type: 'doughnut',
    data: {
        labels: ['Vigentes', 'Vencidas', 'Pagadas'],
        datasets: [{
            data: [$js_vigentes, $js_vencidas, $js_pagadas],
            backgroundColor: ['#10b981', '#ef4444', 'rgba(255,255,255,.1)'],
            borderColor: 'transparent',
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '73%',
        plugins: { legend: { display: false } }
    }
});
</script>
HTML;
require_once __DIR__ . '/../views/layout_footer.php';
?>
