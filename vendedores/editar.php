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
    header('Location: index');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM ic_vendedores WHERE id = ?");
$stmt->execute([$id]);
$vendedor = $stmt->fetch();

if (!$vendedor) {
    header('Location: index');
    exit;
}

// Usuarios con rol=vendedor disponibles para vincular:
// - sin vendedor asignado (usuario_id IS NULL en ic_vendedores)
// - O el que ya tiene este vendedor
$usuarios_disponibles = $pdo->prepare("
    SELECT u.id, u.nombre, u.apellido, u.usuario
    FROM ic_usuarios u
    WHERE u.rol = 'vendedor' AND u.activo = 1
      AND (
          u.id = :usuario_actual
          OR u.id NOT IN (SELECT usuario_id FROM ic_vendedores WHERE usuario_id IS NOT NULL)
      )
    ORDER BY u.apellido, u.nombre
");
$usuarios_disponibles->execute([':usuario_actual' => $vendedor['usuario_id'] ?? 0]);
$lista_usuarios = $usuarios_disponibles->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellido  = trim($_POST['apellido'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $nuevo_uid = (int)($_POST['usuario_id'] ?? 0) ?: null;

    if ($nombre === '' || $apellido === '') {
        $error = 'Nombre y Apellido son obligatorios.';
    } else {
        $pdo->beginTransaction();
        try {
            // Si el usuario seleccionado ya estaba vinculado a otro ic_vendedores (registro vacío
            // creado automáticamente al crear el usuario), desvincularlo de allí primero.
            if ($nuevo_uid !== null) {
                $pdo->prepare("
                    UPDATE ic_vendedores SET usuario_id = NULL
                    WHERE usuario_id = ? AND id != ?
                ")->execute([$nuevo_uid, $id]);
            }

            $pdo->prepare("UPDATE ic_vendedores SET nombre=?, apellido=?, telefono=?, usuario_id=? WHERE id=?")
                ->execute([$nombre, $apellido, $telefono, $nuevo_uid, $id]);

            $pdo->commit();
            registrar_log($pdo, $_SESSION['user_id'], 'VENDEDOR_MODIFICADO', 'vendedores', $id,
                'usuario_id: ' . ($nuevo_uid ?? 'sin usuario'));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Vendedor actualizado.'];
            header('Location: index');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Error al guardar: ' . $e->getMessage();
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
        <div class="form-group mb-3">
            <label>Nombre *</label>
            <input type="text" name="nombre" class="form-control" required value="<?= e($vendedor['nombre']) ?>">
        </div>

        <div class="form-group mb-3">
            <label>Apellido *</label>
            <input type="text" name="apellido" class="form-control" required value="<?= e($vendedor['apellido']) ?>">
        </div>

        <div class="form-group mb-3">
            <label>Teléfono</label>
            <input type="text" name="telefono" class="form-control" value="<?= e($vendedor['telefono']) ?>">
        </div>

        <div class="form-group mb-3">
            <label>
                <i class="fa fa-user-circle"></i> Usuario del sistema
                <span style="font-size:.75rem;color:var(--text-muted);font-weight:400;margin-left:4px">
                    (login para acceder a "Mis Clientes")
                </span>
            </label>
            <select name="usuario_id" class="form-control">
                <option value="">— Sin acceso al sistema —</option>
                <?php foreach ($lista_usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>"
                        <?= (int)($vendedor['usuario_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['apellido'] . ', ' . $u['nombre']) ?> (<?= e($u['usuario']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($lista_usuarios)): ?>
                <div style="font-size:.78rem;color:var(--warning);margin-top:6px">
                    <i class="fa fa-circle-exclamation"></i>
                    No hay usuarios con rol <strong>Vendedor</strong> disponibles.
                    Creá uno primero en <a href="<?= BASE_URL ?>admin/usuarios">Administración → Usuarios</a>.
                </div>
            <?php endif; ?>
        </div>

        <div style="display:flex;gap:12px;margin-top:8px">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar Cambios</button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
