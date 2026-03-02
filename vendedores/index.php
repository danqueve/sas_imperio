<?php
// vendedores/index.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin'); // Solo admin gestiona vendedores

$pdo = obtener_conexion();
$b = trim($_GET['b'] ?? '');

$where = "1=1";
$params = [];
if ($b !== '') {
    $where .= " AND (nombre LIKE ? OR apellido LIKE ?)";
    $params[] = "%$b%";
    $params[] = "%$b%";
}

$stmt = $pdo->prepare("SELECT * FROM ic_vendedores WHERE $where ORDER BY activo DESC, apellido, nombre");
$stmt->execute($params);
$vendedores = $stmt->fetchAll();

$page_title = 'Vendedores';
$page_current = 'vendedores';
$topbar_actions = '<a href="nuevo.php" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nuevo Vendedor</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="card-ic mb-4">
    <div class="card-ic-header">
        <form method="GET" style="display:flex;gap:10px;align-items:center;width:100%;max-width:400px">
            <input type="text" name="b" class="form-control mb-0" placeholder="Buscar vendedor..." value="<?= e($b) ?>">
            <button type="submit" class="btn-ic btn-secondary">Buscar</button>
            <?php if ($b !== ''): ?>
                <a href="index.php" class="btn-ic btn-ghost">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
    
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Apellido y Nombre</th>
                    <th>Teléfono</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendedores)): ?>
                    <tr><td colspan="4" class="text-center text-muted" style="padding:40px">No hay vendedores registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendedores as $v): ?>
                        <tr>
                            <td class="fw-bold">
                                <?= e($v['apellido'] . ', ' . $v['nombre']) ?>
                            </td>
                            <td><?= e($v['telefono'] ?? '-') ?></td>
                            <td>
                                <?php if ($v['activo']): ?>
                                    <span class="badge" style="background:var(--success);color:#fff">ACTIVO</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--danger);color:#fff">INACTIVO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="editar.php?id=<?= $v['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Editar"><i class="fa fa-edit"></i></a>
                                <?php if ($v['activo']): ?>
                                    <a href="eliminar.php?id=<?= $v['id'] ?>&accion=baja" class="btn-ic btn-danger btn-sm btn-icon" title="Dar de baja" onclick="return confirm('¿Seguro que deseas dar de baja a este vendedor?')"><i class="fa fa-arrow-down"></i></a>
                                <?php else: ?>
                                    <a href="eliminar.php?id=<?= $v['id'] ?>&accion=alta" class="btn-ic btn-success btn-sm btn-icon" title="Dar de alta" onclick="return confirm('¿Seguro que deseas reactivar a este vendedor?')"><i class="fa fa-arrow-up"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
