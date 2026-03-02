<?php
// ============================================================
// creditos/cambiar_vendedor.php — Cambiar el vendedor de un crédito
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

// Obtener el crédito y validar estado
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, a.descripcion AS articulo
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();

if (!$cr || !in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'El crédito no existe o no se puede modificar su vendedor en su estado actual.'];
    header("Location: ver.php?id=$id");
    exit;
}

// Obtener lista de vendedores
$vendedores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='vendedor' AND activo=1 ORDER BY nombre")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendedor_id = !empty($_POST['vendedor_id']) ? (int) $_POST['vendedor_id'] : null;
    
    if (!$vendedor_id) {
        $error = 'Debe seleccionar un vendedor válido.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Actualizar vendedor del crédito
            $upd = $pdo->prepare("UPDATE ic_creditos SET vendedor_id = ? WHERE id = ?");
            $upd->execute([$vendedor_id, $id]);
            
            // Log de actividad
            registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_CAMBIO_VENDEDOR', 'credito', $id, "Nuevo vendedor ID: $vendedor_id");
            
            $pdo->commit();
            
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Vendedor asignado actualizado correctamente.'];
            header("Location: ver.php?id=$id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Ocurrió un error al intentar cambiar el vendedor: ' . $e->getMessage();
        }
    }
}

$page_title = 'Cambiar Vendedor | Crédito #' . $id;
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:600px; margin: 0 auto;">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title text-primary"><i class="fa fa-user-tag"></i> Seleccionar Vendedor</span>
            </div>
            
            <div class="mb-4" style="background:var(--dark-bg); padding:15px; border-radius:8px; border:1px solid var(--dark-border);">
                <div style="margin-bottom:8px;">
                    <strong class="text-muted">Cliente:</strong> <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                </div>
                <div>
                    <strong class="text-muted">Artículo:</strong> <?= e($cr['articulo']) ?>
                </div>
            </div>

            <div class="form-group mb-4">
                <label>Vendedores activos *</label>
                <select name="vendedor_id" required class="form-control mb-2" style="padding: 10px; font-size: 1rem;">
                    <option value="">— Seleccionar vendedor —</option>
                    <?php foreach ($vendedores as $ven): ?>
                        <option value="<?= $ven['id'] ?>" <?= ($cr['vendedor_id'] == $ven['id']) ? 'selected' : '' ?>>
                            <?= e($ven['nombre'] . ' ' . $ven['apellido']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><i class="fa fa-info-circle"></i> Se reasignará la venta de este crédito a este vendedor para los seguimientos de ventas.</small>
            </div>

            <div class="d-flex gap-3 mt-4">
                <button type="submit" class="btn-ic btn-primary">
                    <i class="fa fa-save"></i> Guardar Cambios
                </button>
                <a href="ver.php?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
