<?php
// ============================================================
// admin/riesgo_cartera.php — Reporte de Riesgo de Cartera
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros ────────────────────────────────────────────────
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$riesgo_sel  = (int) ($_GET['riesgo'] ?? 0); // 0 = todos

$where  = ["cr.estado IN ('EN_CURSO','MOROSO')"];
$params = [];

if ($cobrador_id > 0) {
    $where[]  = 'cr.cobrador_id = ?';
    $params[] = $cobrador_id;
}
$whereStr = implode(' AND ', $where);

// ── Query: todos los créditos EN CURSO con datos agregados ─
$stmt = $pdo->prepare("
    SELECT
        cr.id AS credito_id,
        cl.apellidos, cl.nombres, cl.zona,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        CONCAT(u.nombre, ' ', u.apellido)              AS cobrador,
        COALESCE(cr.veces_refinanciado, 0)              AS refinanciado,
        COUNT(CASE WHEN cu.estado IN ('VENCIDA','PARCIAL') AND cu.fecha_vencimiento < CURDATE() THEN 1 END) AS cuotas_vencidas,
        COALESCE(AVG(CASE WHEN cu.fecha_vencimiento < CURDATE() AND cu.estado IN ('VENCIDA','PARCIAL')
                          THEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) END), 0) AS avg_atraso,
        COALESCE(MAX(CASE WHEN cu.fecha_vencimiento < CURDATE() AND cu.estado IN ('VENCIDA','PARCIAL')
                          THEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) END), 0) AS max_dias,
        COUNT(CASE WHEN cu.monto_mora > 0 THEN 1 END) AS con_mora,
        SUM(CASE WHEN cu.estado != 'PAGADA' THEN cu.monto_cuota - cu.saldo_pagado ELSE 0 END) AS saldo_pendiente
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_cuotas cu   ON cu.credito_id = cr.id
    JOIN ic_usuarios u  ON cr.cobrador_id = u.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE $whereStr
    GROUP BY cr.id, cl.apellidos, cl.nombres, cl.zona, cr.articulo_desc, a.descripcion,
             u.nombre, u.apellido, cr.veces_refinanciado
    ORDER BY max_dias DESC, cl.apellidos ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Calcular riesgo en PHP ─────────────────────────────────
$conteo_riesgo = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$saldo_riesgo  = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];

foreach ($rows as &$r) {
    $cv  = (int)   $r['cuotas_vencidas'];
    $avg = (float) $r['avg_atraso'];
    $cm  = (int)   $r['con_mora'];
    $ref = (int)   $r['refinanciado'];

    if ($ref >= 2 || $cv >= 4 || $avg > 30)             $r['riesgo'] = 4;
    elseif ($ref >= 1 || $cv >= 2 || $avg > 14 || $cm >= 3) $r['riesgo'] = 3;
    elseif ($cv >= 1 || $avg > 0 || $cm >= 1)            $r['riesgo'] = 2;
    else                                                  $r['riesgo'] = 1;

    $conteo_riesgo[$r['riesgo']]++;
    $saldo_riesgo[$r['riesgo']] += (float) $r['saldo_pendiente'];
}
unset($r);

// Filtrar por nivel de riesgo si se seleccionó
if ($riesgo_sel > 0) {
    $rows = array_filter($rows, fn($r) => $r['riesgo'] === $riesgo_sel);
}

// ── Selectores de filtro ─────────────────────────────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' ORDER BY nombre"
)->fetchAll();

// ── Layout ─────────────────────────────────────────────────
$topbar_actions = '';
$page_title   = 'Riesgo de Cartera';
$page_current = 'riesgo_cartera';
require_once __DIR__ . '/../views/layout.php';

$niveles_meta = [
    1 => ['label' => 'Bajo',     'icon' => 'fa-shield-halved',        'color' => 'var(--success)'],
    2 => ['label' => 'Moderado', 'icon' => 'fa-exclamation',          'color' => 'var(--info,#0ea5e9)'],
    3 => ['label' => 'Alto',     'icon' => 'fa-triangle-exclamation', 'color' => '#f97316'],
    4 => ['label' => 'Crítico',  'icon' => 'fa-fire',                 'color' => 'var(--danger)'],
];
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
        <select name="riesgo">
            <option value="0">Todos los niveles</option>
            <?php foreach ($niveles_meta as $nv => $meta): ?>
                <option value="<?= $nv ?>" <?= $riesgo_sel === $nv ? 'selected' : '' ?>>
                    <?= $meta['label'] ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="riesgo_cartera" class="btn-ic btn-ghost">Limpiar</a>
    </form>
</div>

<!-- KPI CARDS — 4 niveles de riesgo -->
<div class="kpi-grid mb-4">
    <?php foreach ($niveles_meta as $nv => $meta): ?>
    <div class="kpi-card" style="--kpi-color:<?= $meta['color'] ?>;<?= $riesgo_sel === $nv ? 'border:2px solid ' . $meta['color'] : '' ?>">
        <i class="fa <?= $meta['icon'] ?> kpi-icon"></i>
        <div class="kpi-label"><?= $meta['label'] ?></div>
        <div class="kpi-value" style="font-size:1.3rem"><?= $conteo_riesgo[$nv] ?></div>
        <div class="kpi-sub" style="color:<?= $meta['color'] ?>;font-weight:700">
            <?= formato_pesos($saldo_riesgo[$nv]) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- TABLA -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-shield-halved"></i> Créditos por Nivel de Riesgo</span>
        <span class="text-muted" style="font-size:.82rem"><?= count($rows) ?> créditos</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Artículo</th>
                    <th>Cobrador</th>
                    <th>Zona</th>
                    <th class="text-center">Cuotas Venc.</th>
                    <th class="text-center">Máx. Días</th>
                    <th class="text-right">Saldo</th>
                    <th class="text-center">Riesgo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:40px">
                        No se encontraron créditos con los filtros aplicados.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a href="../creditos/ver?id=<?= $r['credito_id'] ?>" style="font-weight:600">
                                <?= e($r['apellidos'] . ', ' . $r['nombres']) ?>
                                <i class="fa fa-arrow-up-right-from-square" style="font-size:.65rem;opacity:.5"></i>
                            </a>
                        </td>
                        <td class="text-muted" style="font-size:.88rem"><?= e($r['articulo']) ?></td>
                        <td style="font-size:.88rem"><?= e($r['cobrador']) ?></td>
                        <td>
                            <?php if ($r['zona']): ?>
                                <span class="badge" style="background:var(--info,#0ea5e9);color:#fff;font-size:.75rem"><?= e($r['zona']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($r['cuotas_vencidas'] > 0): ?>
                                <span class="badge" style="background:var(--danger);color:#fff"><?= $r['cuotas_vencidas'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($r['max_dias'] > 0): ?>
                                <span style="font-weight:700;color:<?= $r['max_dias'] > 30 ? 'var(--danger)' : ($r['max_dias'] > 14 ? '#f97316' : 'var(--warning)') ?>">
                                    <?= $r['max_dias'] ?> d.
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right fw-bold"><?= formato_pesos((float) $r['saldo_pendiente']) ?></td>
                        <td class="text-center"><?= badge_riesgo((int) $r['riesgo']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../views/layout_footer.php';
?>
