<?php
// ============================================================
// clientes/index.php — Listado de Clientes
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();

// Filtros
$q = trim($_GET['q'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$zona = trim($_GET['zona'] ?? '');
$cobrId = (int) ($_GET['cobrador_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Construir WHERE
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(c.nombres LIKE ? OR c.apellidos LIKE ? OR c.dni LIKE ? OR c.telefono LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($estado !== '') {
    $where[] = 'c.estado = ?';
    $params[] = $estado;
}
if ($zona !== '') {
    $where[] = 'c.zona = ?';
    $params[] = $zona;
}
if ($cobrId > 0) {
    $where[] = 'c.cobrador_id = ?';
    $params[] = $cobrId;
}
$whereStr = implode(' AND ', $where);

// Para cobrador: solo sus clientes
if (es_cobrador()) {
    $whereStr .= ' AND c.cobrador_id = ?';
    $params[] = $_SESSION['user_id'];
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM ic_clientes c WHERE $whereStr");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();
$totalPags = (int) ceil($total / $limit);

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido,
           (SELECT COUNT(*) FROM ic_creditos WHERE cliente_id = c.id AND estado = 'EN_CURSO') AS creditos_activos
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE $whereStr
    ORDER BY c.apellidos, c.nombres
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

// Opciones
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();
$zonas = $pdo->query("SELECT DISTINCT zona FROM ic_clientes WHERE zona IS NOT NULL AND zona<>'' ORDER BY zona")->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Clientes';
$page_current = 'clientes';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- MENSAJE FLASH -->
<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- TOOLBAR -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Buscar nombre, DNI, tel...">
        <select name="estado">
            <option value="">Todos los estados</option>
            <?php foreach (['ACTIVO', 'INACTIVO', 'MOROSO'] as $est): ?>
                <option value="<?= $est ?>" <?= $estado === $est ? 'selected' : '' ?>>
                    <?= $est ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="zona">
            <option value="">Todas las zonas</option>
            <?php foreach ($zonas as $z): ?>
                <option value="<?= e($z) ?>" <?= $zona === $z ? 'selected' : '' ?>>
                    <?= e($z) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!es_cobrador()): ?>
            <select name="cobrador_id">
                <option value="">Todos los cobradores</option>
                <?php foreach ($cobradores as $cob): ?>
                    <option value="<?= $cob['id'] ?>" <?= $cobrId === $cob['id'] ? 'selected' : '' ?>>
                        <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="?" class="btn-ic btn-ghost"><i class="fa fa-times"></i> Limpiar</a>
        <?php if (!es_cobrador()): ?>
            <a href="importar" class="btn-ic btn-ghost" style="margin-left:auto; margin-right:8px">
                <i class="fa fa-file-import"></i> Importar
            </a>
            <a href="nuevo" class="btn-ic btn-primary">
                <i class="fa fa-plus"></i> Nuevo Cliente
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- TABLA -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-users"></i> Clientes</span>
        <span class="text-muted" style="font-size:.82rem">
            <?= number_format($total) ?> registro
            <?= $total !== 1 ? 's' : '' ?>
        </span>
    </div>

    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Apellido y Nombre</th>
                    <th>DNI</th>
                    <th>Teléfono</th>
                    <th>Zona</th>
                    <th>Cobrador</th>
                    <th>Créditos</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clientes)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted" style="padding:40px">
                            Sin resultados para los filtros aplicados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td class="text-muted nowrap">#
                                <?= $c['id'] ?>
                            </td>
                            <td>
                                <div class="fw-bold">
                                    <?= e($c['apellidos']) ?>,
                                    <?= e($c['nombres']) ?>
                                </div>
                                <?php if ($c['cuil']): ?>
                                    <div class="text-muted" style="font-size:.75rem">CUIL:
                                        <?= e($c['cuil']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?= e($c['dni'] ?: '—') ?>
                            </td>
                            <td class="nowrap">
                                <?= e($c['telefono']) ?>
                                <a href="<?= whatsapp_url($c['telefono']) ?>" target="_blank"
                                    class="btn-ic btn-success btn-icon btn-sm" style="display:inline-flex;margin-left:4px"
                                    title="WhatsApp">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                            </td>
                            <td>
                                <?= e($c['zona'] ?: '—') ?>
                            </td>
                            <td class="text-muted">
                                <?= $c['cobrador_nombre'] ? e($c['cobrador_nombre'] . ' ' . $c['cobrador_apellido']) : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($c['creditos_activos'] > 0): ?>
                                    <span class="badge-ic badge-success">
                                        <?= $c['creditos_activos'] ?> activo
                                        <?= $c['creditos_activos'] !== 1 ? 's' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= badge_estado_cliente($c['estado']) ?>
                            </td>
                            <td class="nowrap">
                                <div class="d-flex gap-2">
                                    <a href="ver?id=<?= $c['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon"
                                        title="Ver ficha">
                                        <i class="fa fa-eye"></i>
                                    </a>
                                    <?php if (!es_cobrador()): ?>
                                        <a href="editar?id=<?= $c['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon"
                                            title="Editar">
                                            <i class="fa fa-pencil"></i>
                                        </a>
                                        <?php if (es_admin()): ?>
                                            <a href="eliminar?id=<?= $c['id'] ?>" class="btn-ic btn-danger btn-sm btn-icon"
                                                title="Eliminar"
                                                data-confirm="¿Eliminar al cliente <?= e($c['nombres'] . ' ' . $c['apellidos']) ?>? Esta acción no se puede deshacer.">
                                                <i class="fa fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($c['coordenadas']): ?>
                                        <a href="<?= maps_url($c['coordenadas']) ?>" target="_blank"
                                            class="btn-ic btn-accent btn-sm btn-icon" title="Google Maps">
                                            <i class="fa fa-map-marker-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalPags > 1): ?>
        <div class="pagination mt-3">
            <?php for ($p = 1; $p <= $totalPags; $p++): ?>
                <?php
                $params_url = array_merge($_GET, ['page' => $p]);
                $href = '?' . http_build_query($params_url);
                ?>
                <a href="<?= $href ?>" class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>