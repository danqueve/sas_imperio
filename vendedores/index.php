<?php
// vendedores/index.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── POST: crear acceso o cambiar contraseña ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    $accion      = $_POST['accion'] ?? '';
    $vendedor_id = (int)($_POST['vendedor_id'] ?? 0);

    // Cargar vendedor
    $vend = $pdo->prepare("SELECT * FROM ic_vendedores WHERE id=?");
    $vend->execute([$vendedor_id]);
    $vend_row = $vend->fetch();

    if (!$vend_row) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Vendedor no encontrado.'];
        header('Location: index');
        exit;
    }

    if ($accion === 'crear_acceso') {
        $usuario   = trim($_POST['usuario'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (!$usuario || strlen($password) < 8) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Usuario y contraseña (mín. 8 caracteres) son obligatorios.'];
        } elseif ($password !== $password2) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Las contraseñas no coinciden.'];
        } else {
            // Verificar que el nombre de usuario no exista ya
            $dup = $pdo->prepare("SELECT id FROM ic_usuarios WHERE usuario = ?");
            $dup->execute([$usuario]);
            if ($dup->fetchColumn()) {
                $_SESSION['flash'] = ['type' => 'danger', 'msg' => "El usuario «{$usuario}» ya existe en el sistema."];
            } else {
                $pdo->beginTransaction();
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("
                        INSERT INTO ic_usuarios (nombre, apellido, usuario, password_hash, rol, telefono)
                        VALUES (?, ?, ?, ?, 'vendedor', ?)
                    ")->execute([$vend_row['nombre'], $vend_row['apellido'], $usuario, $hash, $vend_row['telefono']]);
                    $nuevo_uid = (int)$pdo->lastInsertId();

                    // Si ya existía un ic_vendedores vacío vinculado a ese usuario, desvincular
                    $pdo->prepare("UPDATE ic_vendedores SET usuario_id = NULL WHERE usuario_id = ? AND id != ?")
                        ->execute([$nuevo_uid, $vendedor_id]);

                    $pdo->prepare("UPDATE ic_vendedores SET usuario_id = ? WHERE id = ?")
                        ->execute([$nuevo_uid, $vendedor_id]);

                    $pdo->commit();
                    registrar_log($pdo, $_SESSION['user_id'], 'USUARIO_CREADO', 'usuario', $nuevo_uid,
                        'vendedor: ' . $vend_row['apellido'] . ' ' . $vend_row['nombre']);
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Acceso creado. El vendedor ya puede iniciar sesión.'];
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al crear el acceso.'];
                }
            }
        }

    } elseif ($accion === 'cambiar_password') {
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $uid       = (int)$vend_row['usuario_id'];

        if (strlen($password) < 8) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'La contraseña debe tener al menos 8 caracteres.'];
        } elseif ($password !== $password2) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Las contraseñas no coinciden.'];
        } elseif (!$uid) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Este vendedor no tiene usuario asignado.'];
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE ic_usuarios SET password_hash = ? WHERE id = ?")->execute([$hash, $uid]);
            registrar_log($pdo, $_SESSION['user_id'], 'USUARIO_EDITADO', 'usuario', $uid, 'cambio de contraseña');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Contraseña actualizada.'];
        }

    } elseif ($accion === 'quitar_acceso') {
        $uid = (int)$vend_row['usuario_id'];
        if ($uid) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE ic_vendedores SET usuario_id = NULL WHERE id = ?")->execute([$vendedor_id]);
            $pdo->prepare("DELETE FROM ic_usuarios WHERE id = ? AND rol = 'vendedor'")->execute([$uid]);
            $pdo->commit();
            registrar_log($pdo, $_SESSION['user_id'], 'USUARIO_ELIMINADO', 'usuario', $uid, 'acceso vendedor removido');
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Acceso eliminado.'];
        }
    }

    header('Location: index');
    exit;
}

// ── Listado ───────────────────────────────────────────────────
$b = trim($_GET['b'] ?? '');
$where  = "1=1";
$params = [];
if ($b !== '') {
    $where .= " AND (v.nombre LIKE ? OR v.apellido LIKE ?)";
    $params[] = "%$b%";
    $params[] = "%$b%";
}

$stmt = $pdo->prepare("
    SELECT v.*, u.usuario, u.activo AS user_activo
    FROM ic_vendedores v
    LEFT JOIN ic_usuarios u ON u.id = v.usuario_id
    WHERE $where
    ORDER BY v.activo DESC, v.apellido, v.nombre
");
$stmt->execute($params);
$vendedores = $stmt->fetchAll();

$page_title = 'Vendedores';
$page_current = 'vendedores';
$topbar_actions = '<a href="estadisticas" class="btn-ic btn-ghost btn-sm"><i class="fa fa-chart-bar"></i> Estadísticas</a>';
if (es_admin()) {
    $topbar_actions .= ' <a href="nuevo" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nuevo Vendedor</a>';
}
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>" style="margin-bottom:16px">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="card-ic mb-4">
    <div class="card-ic-header">
        <form method="GET" style="display:flex;gap:10px;align-items:center;width:100%;max-width:400px">
            <input type="text" name="b" class="form-control mb-0" placeholder="Buscar vendedor..." value="<?= e($b) ?>">
            <button type="submit" class="btn-ic btn-secondary">Buscar</button>
            <?php if ($b !== ''): ?>
                <a href="index" class="btn-ic btn-ghost">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>

    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Apellido y Nombre</th>
                    <th>Teléfono</th>
                    <th>Acceso al sistema</th>
                    <th>Estado</th>
                    <?php if (es_admin()): ?>
                    <th>Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendedores)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:40px">No hay vendedores registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendedores as $v): ?>
                        <tr>
                            <td style="font-weight:600"><?= e($v['apellido'] . ', ' . $v['nombre']) ?></td>
                            <td><?= e($v['telefono'] ?? '—') ?></td>
                            <td>
                                <?php if ($v['usuario_id'] && $v['usuario']): ?>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <span style="display:inline-flex;align-items:center;gap:5px;
                                                     background:rgba(33,150,83,.15);color:var(--success);
                                                     border-radius:6px;padding:3px 10px;font-size:.78rem;font-weight:600">
                                            <i class="fa fa-circle-check"></i> <?= e($v['usuario']) ?>
                                        </span>
                                        <?php if (es_admin()): ?>
                                        <button type="button"
                                                class="btn-ic btn-ghost btn-sm btn-icon"
                                                title="Cambiar contraseña"
                                                onclick="abrirCambiarClave(<?= $v['id'] ?>, '<?= e(addslashes($v['apellido'] . ', ' . $v['nombre'])) ?>', '<?= e($v['usuario']) ?>')">
                                            <i class="fa fa-key"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);font-size:.8rem">
                                        <i class="fa fa-circle-xmark"></i> Sin acceso
                                    </span>
                                    <?php if (es_admin()): ?>
                                    <button type="button"
                                            class="btn-ic btn-primary btn-sm"
                                            style="margin-left:8px"
                                            onclick="abrirCrearAcceso(<?= $v['id'] ?>, '<?= e(addslashes($v['apellido'] . ', ' . $v['nombre'])) ?>')">
                                        <i class="fa fa-plus"></i> Dar acceso
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($v['activo']): ?>
                                    <span class="badge" style="background:var(--success);color:#fff">ACTIVO</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--danger);color:#fff">INACTIVO</span>
                                <?php endif; ?>
                            </td>
                            <?php if (es_admin()): ?>
                            <td style="white-space:nowrap">
                                <a href="estadisticas?id=<?= $v['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver estadísticas"><i class="fa fa-chart-line"></i></a>
                                <a href="editar?id=<?= $v['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Editar"><i class="fa fa-edit"></i></a>
                                <?php if ($v['usuario_id']): ?>
                                    <button type="button"
                                            class="btn-ic btn-danger btn-sm btn-icon"
                                            title="Quitar acceso"
                                            onclick="confirmarQuitarAcceso(<?= $v['id'] ?>, '<?= e(addslashes($v['apellido'] . ', ' . $v['nombre'])) ?>', '<?= e($v['usuario']) ?>')">
                                        <i class="fa fa-user-xmark"></i>
                                    </button>
                                <?php elseif ($v['activo']): ?>
                                    <a href="eliminar?id=<?= $v['id'] ?>&accion=baja" class="btn-ic btn-danger btn-sm btn-icon" title="Dar de baja"
                                       onclick="return confirm('¿Seguro que deseas dar de baja a este vendedor?')">
                                        <i class="fa fa-arrow-down"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="eliminar?id=<?= $v['id'] ?>&accion=alta" class="btn-ic btn-success btn-sm btn-icon" title="Dar de alta"
                                       onclick="return confirm('¿Seguro que deseas reactivar a este vendedor?')">
                                        <i class="fa fa-arrow-up"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (es_admin()): ?>
<!-- ── Modal: Dar acceso ───────────────────────────────────── -->
<div class="modal-overlay" id="modal-crear-acceso">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa fa-user-plus" style="color:var(--primary-light)"></i>
                Dar acceso al sistema
            </div>
            <button class="modal-close" onclick="closeModal('modal-crear-acceso')">✕</button>
        </div>
        <form method="POST">
            <?php csrf_input(); ?>
            <input type="hidden" name="accion" value="crear_acceso">
            <input type="hidden" name="vendedor_id" id="crear-vendedor-id">
            <div style="padding:20px">
                <div style="background:var(--dark-2);border-radius:8px;padding:10px 14px;
                            margin-bottom:16px;font-size:.83rem;color:var(--text-muted)">
                    <i class="fa fa-user" style="color:var(--primary-light)"></i>
                    <span id="crear-nombre-vendedor" style="color:var(--text-main);font-weight:600;margin-left:4px"></span>
                </div>
                <div class="form-group mb-3">
                    <label style="font-size:.83rem;color:var(--text-muted);margin-bottom:4px;display:block">
                        Usuario (nombre de login) *
                    </label>
                    <input type="text" name="usuario" class="form-control" required autocomplete="off"
                           placeholder="Ej: jperez">
                </div>
                <div class="form-group mb-3">
                    <label style="font-size:.83rem;color:var(--text-muted);margin-bottom:4px;display:block">
                        Contraseña * <span style="font-size:.75rem">(mín. 8 caracteres)</span>
                    </label>
                    <div style="position:relative">
                        <input type="password" name="password" id="crear-pwd" class="form-control" required
                               minlength="8" autocomplete="new-password" placeholder="••••••••">
                        <button type="button"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                       background:none;border:none;color:var(--text-muted);cursor:pointer"
                                onclick="togglePwd('crear-pwd',this)">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label style="font-size:.83rem;color:var(--text-muted);margin-bottom:4px;display:block">
                        Repetir contraseña *
                    </label>
                    <div style="position:relative">
                        <input type="password" name="password2" id="crear-pwd2" class="form-control" required
                               minlength="8" autocomplete="new-password" placeholder="••••••••">
                        <button type="button"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                       background:none;border:none;color:var(--text-muted);cursor:pointer"
                                onclick="togglePwd('crear-pwd2',this)">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div style="border-top:1px solid var(--dark-border);padding:14px 20px;
                        display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn-ic btn-ghost" onclick="closeModal('modal-crear-acceso')">Cancelar</button>
                <button type="submit" class="btn-ic btn-primary">
                    <i class="fa fa-user-plus"></i> Crear acceso
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal: Cambiar contraseña ──────────────────────────── -->
<div class="modal-overlay" id="modal-cambiar-clave">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa fa-key" style="color:var(--warning)"></i>
                Cambiar contraseña
            </div>
            <button class="modal-close" onclick="closeModal('modal-cambiar-clave')">✕</button>
        </div>
        <form method="POST">
            <?php csrf_input(); ?>
            <input type="hidden" name="accion" value="cambiar_password">
            <input type="hidden" name="vendedor_id" id="clave-vendedor-id">
            <div style="padding:20px">
                <div style="background:var(--dark-2);border-radius:8px;padding:10px 14px;
                            margin-bottom:16px;font-size:.83rem;color:var(--text-muted)">
                    <i class="fa fa-user" style="color:var(--warning)"></i>
                    <span id="clave-nombre-vendedor" style="color:var(--text-main);font-weight:600;margin-left:4px"></span>
                    &nbsp;·&nbsp;
                    <span id="clave-usuario" style="font-family:monospace;color:var(--primary-light)"></span>
                </div>
                <div class="form-group mb-3">
                    <label style="font-size:.83rem;color:var(--text-muted);margin-bottom:4px;display:block">
                        Nueva contraseña * <span style="font-size:.75rem">(mín. 8 caracteres)</span>
                    </label>
                    <div style="position:relative">
                        <input type="password" name="password" id="clave-pwd" class="form-control" required
                               minlength="8" autocomplete="new-password" placeholder="••••••••">
                        <button type="button"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                       background:none;border:none;color:var(--text-muted);cursor:pointer"
                                onclick="togglePwd('clave-pwd',this)">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label style="font-size:.83rem;color:var(--text-muted);margin-bottom:4px;display:block">
                        Repetir nueva contraseña *
                    </label>
                    <div style="position:relative">
                        <input type="password" name="password2" id="clave-pwd2" class="form-control" required
                               minlength="8" autocomplete="new-password" placeholder="••••••••">
                        <button type="button"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                       background:none;border:none;color:var(--text-muted);cursor:pointer"
                                onclick="togglePwd('clave-pwd2',this)">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div style="border-top:1px solid var(--dark-border);padding:14px 20px;
                        display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn-ic btn-ghost" onclick="closeModal('modal-cambiar-clave')">Cancelar</button>
                <button type="submit" class="btn-ic btn-warning" style="color:#000">
                    <i class="fa fa-key"></i> Guardar contraseña
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal: Quitar acceso ───────────────────────────────── -->
<div class="modal-overlay" id="modal-quitar-acceso">
    <div class="modal-box" style="max-width:380px">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--danger)">
                <i class="fa fa-user-xmark"></i>
                Quitar acceso
            </div>
            <button class="modal-close" onclick="closeModal('modal-quitar-acceso')">✕</button>
        </div>
        <form method="POST">
            <?php csrf_input(); ?>
            <input type="hidden" name="accion" value="quitar_acceso">
            <input type="hidden" name="vendedor_id" id="quitar-vendedor-id">
            <div style="padding:20px;font-size:.9rem">
                <p style="color:var(--text-body)">
                    ¿Eliminás el acceso de
                    <strong id="quitar-nombre-vendedor" style="color:var(--text-main)"></strong>?
                </p>
                <p style="color:var(--text-muted);font-size:.82rem">
                    El usuario <code id="quitar-usuario" style="color:var(--danger)"></code>
                    será eliminado del sistema. Esta acción no se puede deshacer.
                </p>
            </div>
            <div style="border-top:1px solid var(--dark-border);padding:14px 20px;
                        display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn-ic btn-ghost" onclick="closeModal('modal-quitar-acceso')">Cancelar</button>
                <button type="submit" class="btn-ic btn-danger">
                    <i class="fa fa-trash"></i> Eliminar acceso
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirCrearAcceso(vendedorId, nombre) {
    document.getElementById('crear-vendedor-id').value = vendedorId;
    document.getElementById('crear-nombre-vendedor').textContent = nombre;
    document.querySelector('#modal-crear-acceso form').reset();
    document.getElementById('crear-vendedor-id').value = vendedorId;
    openModal('modal-crear-acceso');
}
function abrirCambiarClave(vendedorId, nombre, usuario) {
    document.getElementById('clave-vendedor-id').value = vendedorId;
    document.getElementById('clave-nombre-vendedor').textContent = nombre;
    document.getElementById('clave-usuario').textContent = usuario;
    document.querySelector('#modal-cambiar-clave form').reset();
    document.getElementById('clave-vendedor-id').value = vendedorId;
    openModal('modal-cambiar-clave');
}
function confirmarQuitarAcceso(vendedorId, nombre, usuario) {
    document.getElementById('quitar-vendedor-id').value = vendedorId;
    document.getElementById('quitar-nombre-vendedor').textContent = nombre;
    document.getElementById('quitar-usuario').textContent = usuario;
    openModal('modal-quitar-acceso');
}
function togglePwd(inputId, btn) {
    const inp = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fa fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fa fa-eye';
    }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
