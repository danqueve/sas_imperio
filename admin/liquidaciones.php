<?php
// ============================================================
// admin/liquidaciones.php — Listado de Liquidaciones
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();

$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$estado      = trim($_GET['estado'] ?? '');
$page        = max(1, (int) ($_GET['page'] ?? 1));
$limit       = 25;
$offset      = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];

if ($cobrador_id) {
    $where[]  = 'liq.cobrador_id = ?';
    $params[] = $cobrador_id;
}
if ($estado !== '') {
    $where[]  = 'liq.estado = ?';
    $params[] = $estado;
}
$whereStr = implode(' AND ', $where);

$total = (int) $pdo->prepare("
    SELECT COUNT(*) FROM ic_liquidaciones liq WHERE $whereStr
")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM ic_liquidaciones liq WHERE $whereStr")->execute($params) : 0;

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM ic_liquidaciones liq WHERE $whereStr");
$cntStmt->execute($params);
$total     = (int) $cntStmt->fetchColumn();
$totalPags = max(1, (int) ceil($total / $limit));

$stmt = $pdo->prepare("
    SELECT liq.*,
           u.nombre AS cob_nombre, u.apellido AS cob_apellido,
           a.nombre AS apr_nombre, a.apellido AS apr_apellido
    FROM ic_liquidaciones liq
    JOIN ic_usuarios u ON liq.cobrador_id = u.id
    LEFT JOIN ic_usuarios a ON liq.aprobado_by = a.id
    WHERE $whereStr
    ORDER BY liq.fecha_desde DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$liquidaciones = $stmt->fetchAll();

$cobradores = $pdo->query("SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

$page_title   = 'Liquidaciones';
$page_current = 'liquidaciones';
$topbar_actions = '<a href="liquidacion_nueva" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nueva Liquidación</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

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
        <select name="estado">
            <option value="">Todos los estados</option>
            <?php foreach (['BORRADOR' => 'Borrador', 'APROBADA' => 'Aprobada', 'PAGADA' => 'Pagada'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="?" class="btn-ic btn-ghost">Limpiar</a>
    </form>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-money-bill-wave"></i> Liquidaciones de Cobradores</span>
        <span class="text-muted" style="font-size:.82rem"><?= number_format($total) ?> registro<?= $total !== 1 ? 's' : '' ?></span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cobrador</th>
                    <th>Período</th>
                    <th>Total Cobrado</th>
                    <th>Comisión</th>
                    <th>Extras</th>
                    <th>Descuentos</th>
                    <th>Neto a Pagar</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($liquidaciones)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted" style="padding:40px">Sin liquidaciones registradas.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($liquidaciones as $liq): ?>
                        <tr>
                            <td class="text-muted nowrap">#<?= $liq['id'] ?></td>
                            <td class="fw-bold"><?= e($liq['cob_nombre'] . ' ' . $liq['cob_apellido']) ?></td>
                            <td class="nowrap text-muted">
                                <?= date('d/m/Y', strtotime($liq['fecha_desde'])) ?>
                                <span style="color:var(--dark-border)">→</span>
                                <?= date('d/m/Y', strtotime($liq['fecha_hasta'])) ?>
                            </td>
                            <td class="nowrap fw-bold"><?= formato_pesos($liq['total_cobrado']) ?></td>
                            <td class="nowrap" style="color:var(--primary-light)">
                                <?= formato_pesos($liq['comision_monto']) ?>
                                <span class="text-muted" style="font-size:.72rem">(<?= $liq['comision_pct'] ?>%)</span>
                            </td>
                            <td class="nowrap" style="color:var(--success)"><?= $liq['total_extras'] > 0 ? '+' . formato_pesos($liq['total_extras']) : '—' ?></td>
                            <td class="nowrap" style="color:var(--danger)"><?= $liq['total_descuentos'] > 0 ? '−' . formato_pesos($liq['total_descuentos']) : '—' ?></td>
                            <td class="nowrap fw-bold" style="font-size:1.05rem;color:var(--accent)"><?= formato_pesos($liq['total_neto']) ?></td>
                            <td>
                                <?php
                                $badge_map = ['BORRADOR' => 'warning', 'APROBADA' => 'primary', 'PAGADA' => 'success'];
                                $badge_color = $badge_map[$liq['estado']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $badge_color ?>"><?= $liq['estado'] ?></span>
                            </td>
                            <td class="nowrap">
                                <a href="liquidacion_ver?id=<?= $liq['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver detalle">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <?php if ($liq['estado'] === 'BORRADOR'): ?>
                                    <a href="liquidacion_editar?id=<?= $liq['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Editar">
                                        <i class="fa fa-pen"></i>
                                    </a>
                                <?php endif; ?>
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
                   class="page-item <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
