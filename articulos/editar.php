<?php
// articulos/nuevo.php + editar.php (combinado con $id)
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
$a = [];
$esEdicion = false;

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM ic_articulos WHERE id=?");
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    if (!$a) {
        header('Location: index');
        exit;
    }
    $esEdicion = true;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $desc = trim($_POST['descripcion'] ?? '');
    $cat = trim($_POST['categoria'] ?? '');
    $costo = $_POST['precio_costo'] !== '' ? (float) $_POST['precio_costo'] : null;
    $venta = $_POST['precio_venta'] !== '' ? (float) $_POST['precio_venta'] : null;
    $stock = (int) ($_POST['stock'] ?? 0);
    $activo = isset($_POST['activo']) ? 1 : 0;

    if (empty($desc)) {
        $error = 'La descripción es obligatoria.';
    } else {
        if ($esEdicion) {
            $pdo->prepare("UPDATE ic_articulos SET descripcion=?,categoria=?,precio_costo=?,precio_venta=?,stock=?,activo=? WHERE id=?")
                ->execute([$desc, $cat, $costo, $venta, $stock, $activo, $id]);
            registrar_log($pdo, $_SESSION['user_id'], 'ARTICULO_EDITADO', 'articulo', $id, $desc);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artículo actualizado.'];
        } else {
            $pdo->prepare("INSERT INTO ic_articulos (descripcion,categoria,precio_costo,precio_venta,stock,activo) VALUES (?,?,?,?,?,?)")
                ->execute([$desc, $cat, $costo, $venta, $stock, 1]);
            $art_id = (int) $pdo->lastInsertId();
            registrar_log($pdo, $_SESSION['user_id'], 'ARTICULO_CREADO', 'articulo', $art_id, $desc);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artículo agregado.'];
        }
        header('Location: index');
        exit;
    }
}

$page_title = $esEdicion ? 'Editar Artículo' : 'Nuevo Artículo';
$page_current = 'articulos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:600px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title"><i class="fa fa-box-open"></i> Artículo</span></div>
            <div class="form-grid">
                <div class="form-group" style="grid-column:span 2">
                    <label>Descripción *</label>
                    <input type="text" name="descripcion" value="<?= e($a['descripcion'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Categoría</label>
                    <input type="text" name="categoria" value="<?= e($a['categoria'] ?? '') ?>"
                        placeholder="Electrodomésticos, Ropa...">
                </div>
                <div class="form-group">
                    <label>Stock</label>
                    <input type="number" name="stock" value="<?= $a['stock'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group">
                    <label>Precio de Costo $</label>
                    <input type="number" name="precio_costo" value="<?= $a['precio_costo'] ?? '' ?>" step="0.01" min="0"
                        placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Precio de Venta $</label>
                    <input type="number" name="precio_venta" id="precio_venta" value="<?= $a['precio_venta'] ?? '' ?>"
                        step="0.01" min="0" placeholder="0.00">
                </div>
                <?php if ($esEdicion): ?>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px">
                            <input type="checkbox" name="activo" <?= ($a['activo'] ?? 1) ? 'checked' : '' ?>>
                            Artículo activo
                        </label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar</button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>