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

// ── Cobros últimos 7 días (gráfico de barras) ─────────────────
$chart_labels = [];
$chart_map    = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d/m', strtotime($d));
    $chart_map[$d]  = 0;
}
foreach ($pdo->query("
    SELECT DATE(fecha_pago) d, COALESCE(SUM(monto_total),0) t
    FROM ic_pagos_confirmados
    WHERE fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(fecha_pago)
")->fetchAll() as $r) {
    if (array_key_exists($r['d'], $chart_map)) $chart_map[$r['d']] = (float)$r['t'];
}
$chart_vals = array_values($chart_map);

// ── Ranking cobradores del mes ────────────────────────────────
$cobradores_mes = $pdo->query("
    SELECT u.nombre, u.apellido, COUNT(*) AS pagos, COALESCE(SUM(pc.monto_total),0) AS total
    FROM ic_pagos_confirmados pc
    JOIN ic_usuarios u ON pc.cobrador_id = u.id
    WHERE pc.fecha_pago >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
    GROUP BY pc.cobrador_id ORDER BY total DESC LIMIT 5
")->fetchAll();
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

<?php if ($rend_pend > 0): ?>
<div class="alert-ic alert-warning mb-4">
    <i class="fa fa-bell"></i>
    <strong><?= $rend_pend ?> cobrador<?= $rend_pend !== 1 ? 'es tienen' : ' tiene' ?> rendiciones pendientes de aprobación.</strong>
    <a href="rendiciones.php" style="color:inherit;text-decoration:underline;margin-left:12px">Ir a Rendiciones →</a>
</div>
<?php endif; ?>

<!-- ── KPI Cards con indicadores circulares ──────────────────── -->
<div class="db-kpi-grid mb-4">

    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="db-gauge-wrap">
            <svg viewBox="0 0 36 36" width="56" height="56" style="transform:rotate(-90deg)">
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="2.5"/>
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#10b981" stroke-width="2.5"
                    stroke-dasharray="<?= $g_clientes ?> 100" stroke-linecap="round"/>
            </svg>
            <span class="db-gauge-num"><?= $g_clientes ?>%</span>
        </div>
        <div class="kpi-label"><i class="fa fa-users"></i> Clientes Activos</div>
        <div class="kpi-value"><?= number_format($clientes_activos) ?></div>
        <div class="kpi-sub">de <?= number_format($total_clientes) ?> registrados</div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--primary-light)">
        <div class="db-gauge-wrap">
            <svg viewBox="0 0 36 36" width="56" height="56" style="transform:rotate(-90deg)">
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="2.5"/>
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#818cf8" stroke-width="2.5"
                    stroke-dasharray="<?= $g_creditos ?> 100" stroke-linecap="round"/>
            </svg>
            <span class="db-gauge-num"><?= $g_creditos ?>%</span>
        </div>
        <div class="kpi-label"><i class="fa fa-file-invoice-dollar"></i> Créditos en Curso</div>
        <div class="kpi-value"><?= number_format($creditos_en_curso) ?></div>
        <div class="kpi-sub">de <?= number_format($total_creditos) ?> totales</div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="db-gauge-wrap">
            <svg viewBox="0 0 36 36" width="56" height="56" style="transform:rotate(-90deg)">
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="2.5"/>
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#06b6d4" stroke-width="2.5"
                    stroke-dasharray="<?= $g_cobro ?> 100" stroke-linecap="round"/>
            </svg>
            <span class="db-gauge-num"><?= $g_cobro ?>%</span>
        </div>
        <div class="kpi-label"><i class="fa fa-dollar-sign"></i> Cobrado Hoy</div>
        <div class="kpi-value" style="font-size:1.35rem"><?= formato_pesos($cobrado_hoy) ?></div>
        <div class="kpi-sub"><?= $pagos_hoy ?> pago<?= $pagos_hoy !== 1 ? 's' : '' ?> aprobado<?= $pagos_hoy !== 1 ? 's' : '' ?></div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <div class="db-gauge-wrap">
            <svg viewBox="0 0 36 36" width="56" height="56" style="transform:rotate(-90deg)">
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="2.5"/>
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#f59e0b" stroke-width="2.5"
                    stroke-dasharray="<?= $g_al_dia ?> 100" stroke-linecap="round"/>
            </svg>
            <span class="db-gauge-num"><?= $g_al_dia ?>%</span>
        </div>
        <div class="kpi-label"><i class="fa fa-clock"></i> Cuotas al Día</div>
        <div class="kpi-value"><?= number_format($cartera['vigentes']) ?></div>
        <div class="kpi-sub"><?= number_format($cartera['vencidas']) ?> vencidas</div>
    </div>

</div>

<!-- ── Gráficos ───────────────────────────────────────────────── -->
<div class="db-charts-grid mb-4">

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-bar"></i> Cobros — Últimos 7 Días</span>
            <span class="text-muted" style="font-size:.8rem">
                Mes: <strong style="color:var(--primary-light)"><?= formato_pesos($cobrado_mes) ?></strong>
            </span>
        </div>
        <div style="position:relative;height:220px;padding:6px 0">
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
            <span class="card-title"><i class="fa fa-trophy"></i> Ranking del Mes</span>
            <span class="text-muted" style="font-size:.78rem"><?= date('M Y') ?></span>
        </div>
        <?php if (empty($cobradores_mes)): ?>
            <p class="text-muted text-center" style="padding:24px">Sin cobros este mes.</p>
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
            <a href="rendiciones.php" class="btn-ic btn-ghost btn-sm">Ver Rendiciones</a>
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
$js_labels   = json_encode($chart_labels);
$js_vals     = json_encode($chart_vals);
$js_vigentes = (int)$cartera['vigentes'];
$js_vencidas = (int)$cartera['vencidas'];
$js_pagadas  = (int)$cartera['pagadas'];

$page_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color          = 'rgba(255,255,255,.55)';
Chart.defaults.borderColor    = 'rgba(255,255,255,.07)';
Chart.defaults.font.family    = "'Inter', sans-serif";
Chart.defaults.font.size      = 11;

// ── Barras: Cobros últimos 7 días ─────────────────────────────
new Chart(document.getElementById('chart-cobros'), {
    type: 'bar',
    data: {
        labels: $js_labels,
        datasets: [{
            label: 'Cobrado',
            data: $js_vals,
            backgroundColor: 'rgba(79,70,229,.55)',
            borderColor: '#4f46e5',
            borderWidth: 1,
            borderRadius: 5,
            borderSkipped: false,
        }, {
            type: 'line',
            label: 'Tendencia',
            data: $js_vals,
            borderColor: '#818cf8',
            borderWidth: 2,
            pointBackgroundColor: '#818cf8',
            pointRadius: 3,
            tension: 0.4,
            fill: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => ' \$ ' + ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2})
                }
            }
        },
        scales: {
            y: {
                grid: { color: 'rgba(255,255,255,.06)' },
                ticks: { callback: v => v >= 1000 ? '\$' + (v/1000).toFixed(0) + 'k' : '\$' + v }
            },
            x: { grid: { display: false } }
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
<style>
/* ── Dashboard layout ─────────────────────────────────────── */
.db-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.db-charts-grid {
    display: grid;
    grid-template-columns: 1fr 310px;
    gap: 16px;
}
.db-bottom-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
/* Gauge circular */
.db-gauge-wrap {
    position: absolute;
    right: 14px;
    top: 14px;
    width: 56px;
    height: 56px;
}
.db-gauge-num {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9.5px;
    font-weight: 700;
    color: var(--text-main);
    line-height: 1;
}
/* Responsive */
@media (max-width: 1200px) {
    .db-charts-grid { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .db-kpi-grid    { grid-template-columns: repeat(2, 1fr); }
    .db-bottom-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .db-kpi-grid    { grid-template-columns: 1fr; }
}
</style>
HTML;
require_once __DIR__ . '/../views/layout_footer.php';
?>
