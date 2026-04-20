<?php
// admin/cohortes.php — Análisis de cohortes de clientes por mes de primer crédito
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// Cohortes: agrupar clientes por mes de su primer crédito
// Para cada cohorte calcular: total clientes, finalizados con pago, morosos actuales, promedio puntaje
$stmt = $pdo->query("
    SELECT
        DATE_FORMAT(primer_cr.fecha_alta, '%Y-%m') AS cohort,
        COUNT(DISTINCT primer_cr.cliente_id) AS total_clientes,
        SUM(CASE WHEN cr_fin.estado = 'FINALIZADO'
                  AND cr_fin.motivo_finalizacion IN ('PAGO_COMPLETO','PAGO_COMPLETO_CON_MORA')
                 THEN 1 ELSE 0 END) AS finalizados_ok,
        SUM(CASE WHEN cr_act.estado = 'MOROSO' THEN 1 ELSE 0 END) AS morosos_activos,
        SUM(CASE WHEN cr_act.estado = 'EN_CURSO' THEN 1 ELSE 0 END) AS en_curso,
        ROUND(AVG(NULLIF(cl.puntaje_pago, 0)), 1) AS puntaje_prom
    FROM (
        SELECT cliente_id, MIN(fecha_alta) AS fecha_alta
        FROM ic_creditos
        GROUP BY cliente_id
    ) primer_cr
    JOIN ic_clientes cl ON cl.id = primer_cr.cliente_id
    LEFT JOIN ic_creditos cr_fin ON cr_fin.cliente_id = primer_cr.cliente_id
    LEFT JOIN ic_creditos cr_act ON cr_act.cliente_id = primer_cr.cliente_id
                                 AND cr_act.estado IN ('EN_CURSO','MOROSO')
    GROUP BY cohort
    ORDER BY cohort DESC
    LIMIT 24
");
$cohortes = $stmt->fetchAll();

$page_title   = 'Análisis de Cohortes';
$page_current = 'cohortes';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-layer-group"></i> Cohortes de Clientes por Mes de Ingreso</span>
        <span class="text-muted" style="font-size:.78rem">Últimos 24 meses</span>
    </div>
    <p class="text-muted" style="font-size:.82rem;margin-bottom:16px">
        Cada cohorte agrupa clientes según el mes en que registraron su <strong>primer crédito</strong>.
        Permite identificar qué generaciones de clientes pagan mejor.
    </p>

    <div style="overflow-x:auto">
    <table class="table-ic">
        <thead>
            <tr>
                <th>Cohorte</th>
                <th style="text-align:right">Clientes</th>
                <th style="text-align:right">Finalizados OK</th>
                <th style="text-align:right">% Éxito</th>
                <th style="text-align:right">En Curso</th>
                <th style="text-align:right">Morosos</th>
                <th style="text-align:right">% Mora</th>
                <th style="text-align:center">Puntaje Prom.</th>
                <th style="text-align:center">Calidad</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cohortes as $row):
            $total   = max(1, (int)$row['total_clientes']);
            $fin_ok  = (int)$row['finalizados_ok'];
            $morosos = (int)$row['morosos_activos'];
            $curso   = (int)$row['en_curso'];
            $pct_ok  = round($fin_ok / $total * 100, 1);
            $pct_mora = $curso + $morosos > 0 ? round($morosos / ($curso + $morosos) * 100, 1) : 0;
            $punt    = (float)$row['puntaje_prom'];
            // Calidad: basada en pct_ok y pct_mora
            if ($pct_ok >= 60 && $pct_mora <= 10) { $cal_lbl = 'Excelente'; $cal_color = 'var(--success)'; }
            elseif ($pct_ok >= 40 && $pct_mora <= 25) { $cal_lbl = 'Buena'; $cal_color = 'var(--primary-light)'; }
            elseif ($pct_mora >= 40) { $cal_lbl = 'Riesgo'; $cal_color = 'var(--danger)'; }
            else { $cal_lbl = 'Regular'; $cal_color = 'var(--warning)'; }
            $mes_label = date('M Y', strtotime($row['cohort'] . '-01'));
        ?>
        <tr>
            <td class="fw-bold nowrap"><?= $mes_label ?></td>
            <td style="text-align:right"><?= $total ?></td>
            <td style="text-align:right"><?= $fin_ok ?></td>
            <td style="text-align:right">
                <span style="color:<?= $pct_ok >= 50 ? 'var(--success)' : 'var(--text-muted)' ?>;font-weight:600">
                    <?= $pct_ok ?>%
                </span>
            </td>
            <td style="text-align:right"><?= $curso ?></td>
            <td style="text-align:right"><?= $morosos ?></td>
            <td style="text-align:right">
                <span style="color:<?= $pct_mora >= 30 ? 'var(--danger)' : ($pct_mora >= 15 ? 'var(--warning)' : 'var(--text-muted)') ?>;font-weight:600">
                    <?= $pct_mora ?>%
                </span>
            </td>
            <td style="text-align:center">
                <?php if ($punt > 0): ?>
                <div style="display:flex;align-items:center;gap:6px;justify-content:center">
                    <div style="width:50px;height:5px;background:var(--dark-border);border-radius:3px">
                        <div style="height:100%;width:<?= min(100,round((4-$punt)/3*100)) ?>%;background:var(--success);border-radius:3px"></div>
                    </div>
                    <span style="font-size:.78rem"><?= number_format($punt,1) ?></span>
                </div>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center">
                <span style="padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:700;background:color-mix(in srgb,<?= $cal_color ?> 15%,transparent);color:<?= $cal_color ?>">
                    <?= $cal_lbl ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Gráfico de barras de morosos por cohorte -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-chart-bar"></i> % Morosidad por Cohorte</span>
    </div>
    <canvas id="chart-cohortes" height="100"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const datos = <?= json_encode(array_map(fn($r) => [
    'label' => date('M Y', strtotime($r['cohort'] . '-01')),
    'total' => (int)$r['total_clientes'],
    'finOk' => (int)$r['finalizados_ok'],
    'morosos' => (int)$r['morosos_activos'],
    'curso' => (int)$r['en_curso'],
], array_reverse($cohortes)), JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('chart-cohortes'), {
    type: 'bar',
    data: {
        labels: datos.map(d => d.label),
        datasets: [
            {
                label: 'Finalizados OK',
                data: datos.map(d => d.finOk),
                backgroundColor: 'rgba(16,185,129,.7)',
            },
            {
                label: 'En Curso',
                data: datos.map(d => d.curso),
                backgroundColor: 'rgba(60,80,224,.5)',
            },
            {
                label: 'Morosos',
                data: datos.map(d => d.morosos),
                backgroundColor: 'rgba(239,68,68,.7)',
            },
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { labels: { color: '#CBD5E1' } } },
        scales: {
            x: { stacked: true, ticks: { color: '#8A99AF' }, grid: { color: 'rgba(255,255,255,.05)' } },
            y: { stacked: true, ticks: { color: '#8A99AF' }, grid: { color: 'rgba(255,255,255,.05)' } },
        }
    }
});
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
