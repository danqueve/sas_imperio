<?php
// vendedores/nuevo.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    
    if ($nombre === '' || $apellido === '') {
        $error = 'Nombre y Apellido son obligatorios.';
    } else {
        $pdo = obtener_conexion();
        $ins = $pdo->prepare("INSERT INTO ic_vendedores (nombre, apellido, telefono) VALUES (?, ?, ?)");
        if ($ins->execute([$nombre, $apellido, $telefono])) {
            $idNuevo = $pdo->lastInsertId();
            registrar_log($pdo, $_SESSION['user_id'], 'VENDEDOR_CREADO', 'vendedores', $idNuevo);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Vendedor agregado correctamente.'];
            header('Location: index');
            exit;
        } else {
            $error = 'Error al guardar el vendedor.';
        }
    }
}

$page_title = 'Nuevo Vendedor';
$page_current = 'vendedores';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:600px; margin:0 auto">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-triangle"></i> <?= e($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form-ic card-ic">
        <label>Nombre *</label>
        <input type="text" name="nombre" class="form-control" required value="<?= e($_POST['nombre'] ?? '') ?>">
        
        <label>Apellido *</label>
        <input type="text" name="apellido" class="form-control" required value="<?= e($_POST['apellido'] ?? '') ?>">
        
        <label>Teléfono</label>
        <input type="text" name="telefono" class="form-control" value="<?= e($_POST['telefono'] ?? '') ?>">
        
        <button type="submit" class="btn-ic btn-primary mt-3">Guardar Vendedor</button>
        <a href="index" class="btn-ic btn-ghost mt-3">Cancelar</a>
    </form>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
