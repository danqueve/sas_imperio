<?php
// vendedores/editar.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM ic_vendedores WHERE id = ?");
$stmt->execute([$id]);
$vendedor = $stmt->fetch();

if (!$vendedor) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    
    if ($nombre === '' || $apellido === '') {
        $error = 'Nombre y Apellido son obligatorios.';
    } else {
        $upd = $pdo->prepare("UPDATE ic_vendedores SET nombre=?, apellido=?, telefono=? WHERE id=?");
        if ($upd->execute([$nombre, $apellido, $telefono, $id])) {
            registrar_log($pdo, $_SESSION['user_id'], 'VENDEDOR_MODIFICADO', 'vendedores', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Vendedor actualizado.'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Error al modificar.';
        }
    }
}

$page_title = 'Editar Vendedor';
$page_current = 'vendedores';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:600px; margin:0 auto">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-triangle"></i> <?= e($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form-ic card-ic">
        <label>Nombre *</label>
        <input type="text" name="nombre" class="form-control" required value="<?= e($vendedor['nombre']) ?>">
        
        <label>Apellido *</label>
        <input type="text" name="apellido" class="form-control" required value="<?= e($vendedor['apellido']) ?>">
        
        <label>Teléfono</label>
        <input type="text" name="telefono" class="form-control" value="<?= e($vendedor['telefono']) ?>">
        
        <button type="submit" class="btn-ic btn-primary mt-3">Guardar Cambios</button>
        <a href="index.php" class="btn-ic btn-ghost mt-3">Cancelar</a>
    </form>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
