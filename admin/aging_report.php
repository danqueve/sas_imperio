<?php
// ============================================================
// admin/aging_report.php — Reporte de Antigüedad de Deuda
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros ────────────────────────────────────────────────
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$zona_sel    = trim($_GET['zona'] ?? '');

$where  = ["cr.estado IN ('EN_CURSO','MOROSO')"];
$params = [];

if ($cobrador_id > 0) {
    $where[]  = 'cr.cobrador_id = ?';
    $params[] = $cobrador_id;
}
if ($zona_sel !== '') {
    $where[]  = 'cl.zona = ?';
    $params[] = $zona_sel;
}
$whereStr = implode(' AND ', $where);

// ── Query principal: cuotas vencidas agrupadas por bucket ──
$bucketSql = "
    CASE
        WHEN cu.fecha_vencimiento >= CURDATE() THEN 'al_dia'
        WHEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) BETWEEN 1  AND 14 THEN '1_14'
        WHEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) BETWEEN 15 AND 30 THEN '15_30'
        WHEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) BETWEEN 31 AND 60 THEN '31_60'
        ELSE '60_plus'
    END
";

// KPI totales por bucket
$stmtKpi = $pdo->prepare("
    SELECT
        $bucketSql AS bucket,
        COUNT(*)                              AS cant_cuotas,
        SUM(cu.monto_cuota - cu.saldo_pagado) AS monto
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE $whereStr
      AND cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
      AND (cu.monto_cuota - cu.saldo_pagado) > 0
    GROUP BY bucket
");
$stmtKpi->execute($params);
$kpi_raw = [];
foreach ($stmtKpi->fetchAll() as $_kr) {
    $kpi_raw[$_kr['bucket']] = $_kr;
}

$buckets_meta = [
    'al_dia'  => ['label' => 'Al Día',    'color' => 'var(--success)', 'icon' => 'fa-circle-check'],
    '1_14'    => ['label' => '1-14 días',  'color' => '#eab308',       'icon' => 'fa-clock'],
    '15_30'   => ['label' => '15-30 días', 'color' => '#f97316',       'icon' => 'fa-exclamation-triangle'],
    '31_60'   => ['label' => '31-60 días', 'color' => 'var(--danger)',  'icon' => 'fa-fire'],
    '60_plus' => ['label' => '60+ días',   'color' => '#991b1b',       'icon' => 'fa-skull-crossbones'],
];

$kpi = [];
foreach ($buckets_meta as $key => $meta) {
    $row = $kpi_raw[$key] ?? null;
    $kpi[$key] = [
        'cant'  => $row ? (int)$row['cant_cuotas'] : 0,
        'monto' => $row ? (float)$row['monto'] : 0.0,
    ];
}

// ── Detalle por cobrador × bucket ──────────────────────────
$stmtDet = $pdo->prepare("
    SELECT
        u.id AS cob_id, u.apellido, u.nombre,
        cl.zona,
        $bucketSql AS bucket,
        COUNT(*)                              AS cant_cuotas,
        SUM(cu.monto_cuota - cu.saldo_pagado) AS monto
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_usuarios u  ON cr.cobrador_id = u.id
    WHERE $whereStr
      AND cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
      AND (cu.monto_cuota - cu.saldo_pagado) > 0
    GROUP BY u.id, u.apellido, u.nombre, cl.zona, bucket
    ORDER BY u.apellido, u.nombre, cl.zona
");
$stmtDet->execute($params);
$det_raw = $stmtDet->fetchAll();

// Agrupar por cobrador
$por_cobrador = [];
foreach ($det_raw as $r) {
    $cid = (int) $r['cob_id'];
    if (!isset($por_cobrador[$cid])) {
        $por_cobrador[$cid] = [
            'nombre'  => $r['nombre'],
            'apellido'=> $r['apellido'],
            'zonas'   => [],
            'totales' => array_fill_keys(array_keys($buckets_meta), ['cant' => 0, 'monto' => 0.0]),
            'total_general' => 0.0,
        ];
    }
    $zona = $r['zona'] ?: 'Sin zona';
    if (!isset($por_cobrador[$cid]['zonas'][$zona])) {
        $por_cobrador[$cid]['zonas'][$zona] = array_fill_keys(array_keys($buckets_meta), ['cant' => 0, 'monto' => 0.0]);
    }
    $por_cobrador[$cid]['zonas'][$zona][$r['bucket']] = [
        'cant'  => (int) $r['cant_cuotas'],
        'monto' => (float) $r['monto'],
    ];
    $por_cobrador[$cid]['totales'][$r['bucket']]['cant']  += (int) $r['cant_cuotas'];
    $por_cobrador[$cid]['totales'][$r['bucket']]['monto'] += (float) $r['monto'];
    $por_cobrador[$cid]['total_general']                  += (float) $r['monto'];
}

// Ordenar por total general DESC
uasort($por_cobrador, fn($a, $b) => $b['total_general'] <=> $a['total_general']);

// ── Export CSV ─────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aging_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Cobrador', 'Zona', 'Al Día', 'Monto Al Día', '1-14 días', 'Monto 1-14', '15-30 días', 'Monto 15-30', '31-60 días', 'Monto 31-60', '60+ días', 'Monto 60+', 'Total'], ';');
    foreach ($por_cobrador as $cob) {
        foreach ($cob['zonas'] as $zona => $bks) {
            $row_total = 0.0;
            $row = [$cob['apellido'] . ', ' . $cob['nombre'], $zona];
            foreach (array_keys($buckets_meta) as $bk) {
                $row[] = $bks[$bk]['cant'];
                $row[] = number_format($bks[$bk]['monto'], 2, ',', '.');
                $row_total += $bks[$bk]['monto'];
            }
            $row[] = number_format($row_total, 2, ',', '.');
            fputcsv($out, $row, ';');
        }
    }
    fclose($out);
    exit;
}

// ── Selectores de filtro ─────────────────────────────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' ORDER BY nombre"
)->fetchAll();
$zonas = $pdo->query(
    "SELECT DISTINCT zona FROM ic_clientes WHERE zona IS NOT NULL AND zona != '' ORDER BY zona"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Layout ─────────────────────────────────────────────────
$qs_csv = http_build_query(array_filter([
    'cobrador_id' => $cobrador_id ?: null,
    'zona'        => $zona_sel ?: null,
    'export'      => 'csv',
]));
$topbar_actions = '<a href="aging_report?' . e($qs_csv) . '"
    class="btn-ic btn-success btn-sm"><i class="fa fa-file-csv"></i> Exportar CSV</a>';

$page_title   = 'Antigüedad de Deuda';
$page_current = 'aging_report';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- FILTROS -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <select name="cobrador_id">
            <option value="">Todos los cobradores</option>
            <?php foreach ($cobradores as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $cobrador_id === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= e($c['nombre'] . ' ' . $c['apellido']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="zona">
            <option value="">Todas las zonas</option>
            <?php foreach ($zonas as $z): ?>
                <option value="<?= e($z) ?>" <?= $zona_sel === $z ? 'selected' : '' ?>>
                    <?= e($z) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="aging_report" class="btn-ic btn-ghost">Limpiar</a>
    </form>
</div>

<!-- KPI CARDS — 5 buckets -->
<div class="kpi-grid mb-4" style="grid-template-columns:repeat(5,1fr)">
    <?php foreach ($buckets_meta as $key => $meta): ?>
    <div class="kpi-card" style="--kpi-color:<?= $meta['color'] ?>">
        <i class="fa <?= $meta['icon'] ?> kpi-icon"></i>
        <div class="kpi-label"><?= $meta['label'] ?></div>
        <div class="kpi-value" style="font-size:1.15rem"><?= $kpi[$key]['cant'] ?></div>
        <div class="kpi-sub" style="color:<?= $meta['color'] ?>;font-weight:700">
            <?= formato_pesos($kpi[$key]['monto']) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- DETALLE POR COBRADOR -->
<?php if (empty($por_cobrador)): ?>
    <div class="card-ic"><p class="text-muted text-center" style="padding:30px">No hay datos con los filtros seleccionados.</p></div>
<?php endif; ?>

<?php foreach ($por_cobrador as $cob_id => $cob): ?>
<div class="card-ic mb-4">
    <div class="card-ic-header" style="cursor:pointer" onclick="toggleAging('ag-<?= $cob_id ?>','ico-ag-<?= $cob_id ?>')">
        <div style="display:flex;align-items:center;gap:14px;flex:1;flex-wrap:wrap">
            <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;flex-shrink:0">
                <?= strtoupper(mb_substr($cob['nombre'], 0, 1) . mb_substr($cob['apellido'], 0, 1)) ?>
            </div>
            <div>
                <div style="font-weight:700;font-size:1rem"><?= e($cob['apellido'] . ', ' . $cob['nombre']) ?></div>
                <div style="font-size:.75rem;color:var(--text-muted)">
                    Total: <strong style="color:var(--danger)"><?= formato_pesos($cob['total_general']) ?></strong>
                    · <?= count($cob['zonas']) ?> zona(s)
                </div>
            </div>
            <!-- Mini buckets resumen -->
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach ($buckets_meta as $bk => $meta): ?>
                    <?php if ($cob['totales'][$bk]['cant'] > 0): ?>
                    <span style="padding:3px 8px;border-radius:6px;font-size:.72rem;font-weight:700;background:<?= $meta['color'] ?>;color:#fff">
                        <?= $cob['totales'][$bk]['cant'] ?>
                    </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <i class="fa fa-chevron-down" id="ico-ag-<?= $cob_id ?>"
           style="margin-left:16px;transition:transform .2s;color:var(--text-muted)"></i>
    </div>

    <div id="ag-<?= $cob_id ?>" style="display:none">
        <div style="overflow-x:auto">
            <table class="table-ic" style="min-width:700px">
                <thead>
                    <tr>
                        <th>Zona</th>
                        <?php foreach ($buckets_meta as $meta): ?>
                            <th class="text-center" style="font-size:.78rem"><?= $meta['label'] ?></th>
                        <?php endforeach; ?>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cob['zonas'] as $zona => $bks): ?>
                    <?php $zona_total = array_sum(array_column($bks, 'monto')); ?>
                    <tr>
                        <td>
                            <span class="badge" style="background:var(--info,#0ea5e9);color:#fff;font-size:.75rem">
                                <?= e($zona) ?>
                            </span>
                        </td>
                        <?php foreach (array_keys($buckets_meta) as $bk): ?>
                            <td class="text-center" style="font-size:.85rem">
                                <?php if ($bks[$bk]['cant'] > 0): ?>
                                    <div style="font-weight:700"><?= $bks[$bk]['cant'] ?></div>
                                    <div style="font-size:.72rem;color:var(--text-muted)"><?= formato_pesos($bks[$bk]['monto']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-right fw-bold" style="color:var(--danger)">
                            <?= formato_pesos($zona_total) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:rgba(79,70,229,.1);font-weight:800">
                        <td>TOTAL</td>
                        <?php foreach (array_keys($buckets_meta) as $bk): ?>
                            <td class="text-center">
                                <div><?= $cob['totales'][$bk]['cant'] ?></div>
                                <div style="font-size:.72rem;font-weight:600"><?= formato_pesos($cob['totales'][$bk]['monto']) ?></div>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-right" style="color:var(--danger);font-size:1.05rem">
                            <?= formato_pesos($cob['total_general']) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
$page_scripts = <<<'JS'
<script>
function toggleAging(colId, iconId) {
    const col  = document.getElementById(colId);
    const icon = document.getElementById(iconId);
    if (!col) return;
    const abierto = col.style.display !== 'none';
    col.style.display = abierto ? 'none' : 'block';
    if (icon) icon.style.transform = abierto ? 'rotate(-90deg)' : '';
}
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
