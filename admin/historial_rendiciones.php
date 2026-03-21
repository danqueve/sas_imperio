<?php
// ============================================================
// admin/historial_rendiciones.php — Historial de rendiciones
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones'); // Mismo permiso que para aprobar

$pdo = obtener_conexion();

// Paginación
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

// Filtros opcionales
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$origen_sel  = in_array($_GET['origen'] ?? '', ['cobrador', 'manual']) ? $_GET['origen'] : '';

$where  = ['1=1'];
$params = [];

if ($cobrador_id > 0) {
    $where[]  = 'p.cobrador_id = ?';
    $params[] = $cobrador_id;
}
if ($fecha_desde) {
    $where[]  = 'DATE(p.fecha_aprobacion) >= ?';
    $params[] = $fecha_desde;
}
if ($fecha_hasta) {
    $where[]  = 'DATE(p.fecha_aprobacion) <= ?';
    $params[] = $fecha_hasta;
}
if ($origen_sel) {
    $where[]  = "IFNULL(pt.origen, 'cobrador') = ?";
    $params[] = $origen_sel;
}

$whereStr = implode(' AND ', $where);

// Contar el total de grupos (rendiciones) para la paginación
// Una rendición se define como un conjunto de pagos de mismo día + cobrador + origen
$countSql = "
    SELECT COUNT(DISTINCT CONCAT(DATE(p.fecha_aprobacion), '_', p.cobrador_id, '_', IFNULL(pt.origen, 'cobrador')))
    FROM ic_pagos_confirmados p
    LEFT JOIN ic_pagos_temporales pt ON pt.id = p.pago_temp_id
    WHERE $whereStr
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$total = (int) $stmtCount->fetchColumn();
$totalPags = max(1, (int) ceil($total / $limit));

// Consultar los datos agrupados
$sql = "
    SELECT
        DATE(p.fecha_aprobacion) AS fecha_rendicion,
        p.cobrador_id,
        IFNULL(pt.origen, 'cobrador') AS origen,
        u.nombre AS cob_nombre, u.apellido AS cob_apellido,
        MAX(a.nombre)  AS apr_nombre,
        MAX(a.apellido) AS apr_apellido,
        COUNT(p.id) AS cantidad_cuotas,
        SUM(p.monto_efectivo) AS total_efectivo,
        SUM(p.monto_transferencia) AS total_transferencia,
        SUM(p.monto_total) AS total_rendido
    FROM ic_pagos_confirmados p
    JOIN ic_usuarios u ON p.cobrador_id = u.id
    LEFT JOIN ic_usuarios a ON p.aprobador_id = a.id
    LEFT JOIN ic_pagos_temporales pt ON pt.id = p.pago_temp_id
    WHERE $whereStr
    GROUP BY DATE(p.fecha_aprobacion), p.cobrador_id, u.nombre, u.apellido, IFNULL(pt.origen, 'cobrador')
    ORDER BY fecha_rendicion DESC, u.apellido ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historial = $stmt->fetchAll();

// Cobradores para el filtro
$cobradores = $pdo->query("SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' ORDER BY nombre")->fetchAll();

$page_title   = 'Historial de Rendiciones';
$page_current = 'rendiciones';
$topbar_actions = '<a href="rendiciones" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver a Pendientes</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <select name="cobrador_id">
            <option value="">Todos los cobradores</option>
            <?php foreach ($cobradores as $cob): ?>
                <option value="<?= $cob['id'] ?>" <?= $cobrador_id === (int)$cob['id'] ? 'selected' : '' ?>>
                    <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <input type="date" name="fecha_desde" value="<?= e($fecha_desde) ?>" title="Fecha desde">
        <span class="text-muted" style="align-self:center">hasta</span>
        <input type="date" name="fecha_hasta" value="<?= e($fecha_hasta) ?>" title="Fecha hasta">

        <select name="origen">
            <option value="">Todos los origenes</option>
            <option value="cobrador" <?= $origen_sel === 'cobrador' ? 'selected' : '' ?>>Cobrador</option>
            <option value="manual" <?= $origen_sel === 'manual' ? 'selected' : '' ?>>Manual (Admin)</option>
        </select>

        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="historial_rendiciones" class="btn-ic btn-ghost">Limpiar</a>
    </form>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-history"></i> Rendiciones Aprobadas</span>
        <span class="text-muted" style="font-size:.82rem"><?= number_format($total) ?> rendición<?= $total !== 1 ? 'es' : '' ?></span>
    </div>
    
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th class="text-center">Fecha Aprobación</th>
                    <th>Cobrador</th>
                    <th class="text-center">Origen</th>
                    <th class="text-center">Cuotas Rendidas</th>
                    <th class="text-right">Efectivo</th>
                    <th class="text-right">Transferencia</th>
                    <th class="text-right">Total Rendido</th>
                    <th>Aprobado Por</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historial)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted" style="padding:40px">
                            No se encontraron rendiciones en el historial.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historial as $r): ?>
                        <tr>
                            <td class="text-center fw-bold">
                                <?= date('d/m/Y', strtotime($r['fecha_rendicion'])) ?>
                            </td>
                            <td>
                                <?= e($r['cob_apellido'] . ', ' . $r['cob_nombre']) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($r['origen'] === 'manual'): ?>
                                    <span class="badge" style="background:#f59e0b;color:#fff">Manual</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#3b82f6;color:#fff">Cobrador</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= $r['cantidad_cuotas'] ?></span>
                            </td>
                            <td class="text-right">
                                <?= formato_pesos($r['total_efectivo']) ?>
                            </td>
                            <td class="text-right">
                                <?= $r['total_transferencia'] > 0 ? formato_pesos($r['total_transferencia']) : '—' ?>
                            </td>
                            <td class="text-right fw-bold" style="font-size:1.1rem; color:var(--success)">
                                <?= formato_pesos($r['total_rendido']) ?>
                            </td>
                            <td class="text-muted" style="font-size:0.85rem">
                                <?= $r['apr_nombre'] ? e($r['apr_nombre'] . ' ' . $r['apr_apellido']) : '—' ?>
                            </td>
                            <td class="nowrap">
                                <a href="historial_rendiciones_ver?fecha=<?= urlencode($r['fecha_rendicion']) ?>&cobrador_id=<?= $r['cobrador_id'] ?>&origen=<?= urlencode($r['origen']) ?>"
                                   class="btn-ic btn-ghost btn-sm btn-icon" title="Ver Detalle de Rendición">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <a href="historial_rendiciones_pdf?fecha=<?= urlencode($r['fecha_rendicion']) ?>&cobrador_id=<?= $r['cobrador_id'] ?>&origen=<?= urlencode($r['origen']) ?>"
                                   target="_blank" class="btn-ic btn-danger btn-sm btn-icon" title="Exportar PDF">
                                    <i class="fa fa-file-pdf"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPags > 1): ?>
        <div class="pagination mt-3">
            <?php for ($p = 1; $p <= $totalPags; $p++): ?>
                <a href="<?= '?' . http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                   class="page-item <?= $p === $page ? 'active' : '' ?>">
                   <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
