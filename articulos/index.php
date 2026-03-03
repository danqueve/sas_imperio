<?php
// ============================================================
// articulos/index.php — Gestión de Artículos
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$q = trim($_GET['q'] ?? '');

$where = '1=1';
$params = [];
if ($q !== '') {
    $where = 'descripcion LIKE ?';
    $params[] = "%$q%";
}

$articulos = $pdo->prepare("SELECT * FROM ic_articulos WHERE $where ORDER BY descripcion");
$articulos->execute($params);
$lista = $articulos->fetchAll();

$page_title = 'Artículos';
$page_current = 'articulos';
$topbar_actions = '<a href="nuevo" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nuevo Artículo</a>';
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
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Buscar artículo...">
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Buscar</button>
        <a href="?" class="btn-ic btn-ghost">Limpiar</a>
        <a href="nuevo" class="btn-ic btn-primary" style="margin-left:auto">
            <i class="fa fa-plus"></i> Nuevo Artículo
        </a>
    </form>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-box-open"></i> Catálogo de Artículos</span>
        <span class="text-muted" style="font-size:.82rem">
            <?= count($lista) ?> artículo
            <?= count($lista) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descripción</th>
                    <th>Categoría</th>
                    <th>Precio Costo</th>
                    <th>Precio Venta</th>
                    <th>Stock</th>
                    <th>Activo</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lista)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted" style="padding:40px">Sin artículos.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lista as $a): ?>
                        <tr>
                            <td class="text-muted">#
                                <?= $a['id'] ?>
                            </td>
                            <td class="fw-bold">
                                <?= e($a['descripcion']) ?>
                            </td>
                            <td>
                                <?= e($a['categoria'] ?: '—') ?>
                            </td>
                            <td class="nowrap">
                                <?= $a['precio_costo'] ? formato_pesos($a['precio_costo']) : '—' ?>
                            </td>
                            <td class="nowrap fw-bold">
                                <?= $a['precio_venta'] ? formato_pesos($a['precio_venta']) : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php $st = (int) $a['stock']; ?>
                                <span class="badge-ic <?= $st > 0 ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $st ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?= $a['activo'] ? '<span class="badge-ic badge-success">Sí</span>' : '<span class="badge-ic badge-muted">No</span>' ?>
                            </td>
                            <td class="nowrap">
                                <a href="editar?id=<?= $a['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Editar">
                                    <i class="fa fa-pencil"></i>
                                </a>
                                <a href="eliminar?id=<?= $a['id'] ?>" class="btn-ic btn-danger btn-sm btn-icon"
                                    title="Eliminar" data-confirm="¿Eliminar el artículo «<?= e($a['descripcion']) ?>»?">
                                    <i class="fa fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>