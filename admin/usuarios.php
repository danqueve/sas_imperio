<?php
// ============================================================
// admin/usuarios.php — CRUD de cobradores y supervisores
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_usuarios');

$pdo = obtener_conexion();
$error = '';
$modo = $_GET['modo'] ?? 'lista'; // lista | nuevo | editar
$id = (int) ($_GET['id'] ?? 0);
$u = [];

if ($modo === 'editar' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM ic_usuarios WHERE id=?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) {
        header('Location: usuarios');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'toggle_activo') {
        $uid = (int) $_POST['uid'];
        $pdo->prepare("UPDATE ic_usuarios SET activo = 1 - activo WHERE id=?")->execute([$uid]);
        header('Location: usuarios');
        exit;
    }

    if ($accion === 'guardar') {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $rol = $_POST['rol'] ?? 'cobrador';
        $telefono = trim($_POST['telefono'] ?? '');
        $zona = trim($_POST['zona'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$nombre || !$apellido || !$usuario) {
            $error = 'Nombre, apellido y usuario son obligatorios.';
        } elseif ($id === 0 && strlen($password) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } else {
            if ($id === 0) {
                // Nuevo
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO ic_usuarios (nombre,apellido,usuario,password_hash,rol,telefono,zona) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$nombre, $apellido, $usuario, $hash, $rol, $telefono, $zona]);
                    $nuevo_id = (int) $pdo->lastInsertId();
                    // Si es vendedor, crear registro en ic_vendedores vinculado
                    if ($rol === 'vendedor') {
                        $pdo->prepare("INSERT INTO ic_vendedores (usuario_id, nombre, apellido, telefono) VALUES (?,?,?,?)")
                            ->execute([$nuevo_id, $nombre, $apellido, $telefono]);
                    }
                    $pdo->commit();
                    registrar_log($pdo, $_SESSION['user_id'], 'USUARIO_CREADO', 'usuario', $nuevo_id,
                        $rol . ': ' . $nombre . ' ' . $apellido);
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Usuario creado.'];
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = 'El nombre de usuario ya existe.';
                }
            } else {
                // Editar
                if ($password !== '') {
                    if (strlen($password) < 8) {
                        $error = 'La contraseña debe tener al menos 8 caracteres.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE ic_usuarios SET nombre=?,apellido=?,usuario=?,password_hash=?,rol=?,telefono=?,zona=? WHERE id=?")
                            ->execute([$nombre, $apellido, $usuario, $hash, $rol, $telefono, $zona, $id]);
                    }
                } else {
                    $pdo->prepare("UPDATE ic_usuarios SET nombre=?,apellido=?,usuario=?,rol=?,telefono=?,zona=? WHERE id=?")
                        ->execute([$nombre, $apellido, $usuario, $rol, $telefono, $zona, $id]);
                }
                if (!$error) {
                    registrar_log($pdo, $_SESSION['user_id'], 'USUARIO_EDITADO', 'usuario', $id,
                        $nombre . ' ' . $apellido);
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Usuario actualizado.'];
                }
            }
            if (!$error) {
                header('Location: usuarios');
                exit;
            }
        }
    }
}

$usuarios = $pdo->query("SELECT * FROM ic_usuarios ORDER BY rol, apellido, nombre")->fetchAll();
$page_title = 'Usuarios';
$page_current = 'usuarios';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

    <!-- LISTADO -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-user-cog"></i> Usuarios del Sistema</span>
            <a href="?modo=nuevo" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nuevo Usuario</a>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nombre</th>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Teléfono</th>
                        <th>Zona</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usr): ?>
                        <tr>
                            <td class="text-muted">
                                <?= $usr['id'] ?>
                            </td>
                            <td class="fw-bold">
                                <?= e($usr['apellido'] . ', ' . $usr['nombre']) ?>
                            </td>
                            <td class="text-muted">
                                <?= e($usr['usuario']) ?>
                            </td>
                            <td>
                                <?php $rolBadge = ['admin' => 'badge-danger', 'supervisor' => 'badge-primary', 'cobrador' => 'badge-success', 'vendedor' => 'badge-warning']; ?>
                                <span class="badge-ic <?= $rolBadge[$usr['rol']] ?? 'badge-muted' ?>">
                                    <?= strtoupper($usr['rol']) ?>
                                </span>
                            </td>
                            <td>
                                <?= e($usr['telefono'] ?: '—') ?>
                            </td>
                            <td>
                                <?= e($usr['zona'] ?: '—') ?>
                            </td>
                            <td>
                                <?php if ($usr['activo']): ?>
                                    <span class="badge-ic badge-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge-ic badge-muted">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a href="?modo=editar&id=<?= $usr['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon"
                                    title="Editar">
                                    <i class="fa fa-pencil"></i>
                                </a>
                                <?php if ($usr['id'] !== (int) $_SESSION['user_id']): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="accion" value="toggle_activo">
                                        <input type="hidden" name="uid" value="<?= $usr['id'] ?>">
                                        <button type="submit"
                                            class="btn-ic <?= $usr['activo'] ? 'btn-warning' : 'btn-success' ?> btn-sm btn-icon"
                                            title="<?= $usr['activo'] ? 'Desactivar' : 'Activar' ?>"
                                            data-confirm="<?= $usr['activo'] ? '¿Desactivar' : '¿Activar' ?> al usuario <?= e($usr['nombre']) ?>?">
                                            <i class="fa fa-<?= $usr['activo'] ? 'ban' : 'check' ?>"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- FORMULARIO -->
    <?php if ($modo === 'nuevo' || $modo === 'editar'): ?>
        <div class="card-ic">
            <div class="card-ic-header">
                <span class="card-title">
                    <?= $modo === 'nuevo' ? 'Nuevo Usuario' : 'Editar Usuario' ?>
                </span>
                <a href="usuarios" class="btn-ic btn-ghost btn-sm">✕</a>
            </div>
            <?php if ($error): ?>
                <div class="alert-ic alert-danger">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="form-ic">
                <input type="hidden" name="accion" value="guardar">
                <div class="form-group mb-3"><label>Apellido *</label>
                    <input type="text" name="apellido" value="<?= e($u['apellido'] ?? '') ?>" required>
                </div>
                <div class="form-group mb-3"><label>Nombre *</label>
                    <input type="text" name="nombre" value="<?= e($u['nombre'] ?? '') ?>" required>
                </div>
                <div class="form-group mb-3"><label>Usuario (login) *</label>
                    <input type="text" name="usuario" value="<?= e($u['usuario'] ?? '') ?>" required autocomplete="off">
                </div>
                <div class="form-group mb-3"><label>Contraseña
                        <?= $modo !== 'nuevo' ? ' (dejar vacío para no cambiar)' : ' *' ?>
                    </label>
                    <input type="password" name="password" autocomplete="new-password" placeholder="••••••••"
                        <?= $modo === 'nuevo' ? 'required' : '' ?> minlength="8">
                </div>
                <div class="form-group mb-3"><label>Rol</label>
                    <select name="rol">
                        <?php foreach (['cobrador' => 'Cobrador', 'vendedor' => 'Vendedor', 'supervisor' => 'Supervisor', 'admin' => 'Admin'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= ($u['rol'] ?? 'cobrador') === $k ? 'selected' : '' ?>>
                                <?= $l ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-3"><label>Teléfono</label>
                    <input type="text" name="telefono" value="<?= e($u['telefono'] ?? '') ?>">
                </div>
                <div class="form-group mb-3"><label>Zona</label>
                    <input type="text" name="zona" value="<?= e($u['zona'] ?? '') ?>" placeholder="Ej: Zona 1">
                </div>
                <div class="d-flex gap-3">
                    <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar</button>
                    <a href="usuarios" class="btn-ic btn-ghost">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>