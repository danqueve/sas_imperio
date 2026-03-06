<?php
// ============================================================
// ventas/nueva.php — Registrar nueva venta
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_ventas');

$pdo = obtener_conexion();

// Resolver vendedor_id según rol
$vendedor_id_actual = null;
if (es_vendedor()) {
    $stmt = $pdo->prepare("SELECT id FROM ic_vendedores WHERE usuario_id=? AND activo=1 LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $vendedor_id_actual = $stmt->fetchColumn() ?: null;
    if (!$vendedor_id_actual) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Tu usuario no tiene un perfil de vendedor activo. Contactá al administrador.'];
        header('Location: index');
        exit;
    }
}

// Listado de vendedores para admin/supervisor
$vendedores = [];
if (!es_vendedor()) {
    $vendedores = $pdo->query("SELECT id, nombre, apellido FROM ic_vendedores WHERE activo=1 ORDER BY nombre")->fetchAll();
}

// Artículos activos con stock
$articulos_raw = $pdo->query("
    SELECT id, descripcion, precio_venta, precio_contado, precio_tarjeta, sku, stock
    FROM ic_articulos WHERE activo=1 ORDER BY descripcion
")->fetchAll();

$articulos_map = [];
$articulos_search_map = [];
foreach ($articulos_raw as $art) {
    $label = $art['descripcion'] . ($art['sku'] ? ' [' . $art['sku'] . ']' : '');
    $articulos_map[(int)$art['id']] = [
        'precio'   => (float)$art['precio_venta'],
        'contado'  => (float)$art['precio_contado'],
        'tarjeta'  => (float)$art['precio_tarjeta'],
        'desc'     => $art['descripcion'],
        'stock'    => (int)$art['stock'],
        'label'    => $label,
    ];
    $articulos_search_map[$label] = ['id' => (int)$art['id'], 'desc' => $art['descripcion']];
}

$error = '';
$v = ['cantidad' => 1, 'forma_pago' => 'efectivo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;
    $articulo_id   = (int)($v['articulo_id'] ?? 0);
    $cantidad      = max(1, (int)($v['cantidad'] ?? 1));
    $precio_unit   = (float)($v['precio_unitario'] ?? 0);
    $forma_pago    = in_array($v['forma_pago'] ?? '', ['efectivo', 'tarjeta']) ? $v['forma_pago'] : 'efectivo';
    $obs           = trim($v['observaciones'] ?? '');

    // Determinar vendedor_id final
    if (es_vendedor()) {
        $vend_id_final = $vendedor_id_actual;
    } else {
        $vend_id_final = (int)($v['vendedor_id'] ?? 0);
    }

    if (!$articulo_id || $precio_unit <= 0) {
        $error = 'Seleccioná un artículo y verificá el precio.';
    } elseif (!$vend_id_final) {
        $error = 'Seleccioná un vendedor.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock de fila
            $art_stmt = $pdo->prepare("SELECT stock, descripcion FROM ic_articulos WHERE id=? FOR UPDATE");
            $art_stmt->execute([$articulo_id]);
            $art_row = $art_stmt->fetch();

            if (!$art_row || $art_row['stock'] < $cantidad) {
                $pdo->rollBack();
                $error = 'Stock insuficiente. Disponible: ' . ($art_row['stock'] ?? 0) . ' unidades.';
            } else {
                $pdo->prepare("
                    INSERT INTO ic_ventas
                      (articulo_id, articulo_desc, vendedor_id, cantidad, precio_venta,
                       forma_pago, observaciones, created_by, fecha_venta)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $articulo_id,
                    $art_row['descripcion'],
                    $vend_id_final,
                    $cantidad,
                    $precio_unit,
                    $forma_pago,
                    $obs,
                    $_SESSION['user_id'],
                    date('Y-m-d'),
                ]);
                $venta_id = (int)$pdo->lastInsertId();

                $pdo->prepare("UPDATE ic_articulos SET stock = stock - ? WHERE id=?")
                    ->execute([$cantidad, $articulo_id]);

                $pdo->commit();
                registrar_log($pdo, $_SESSION['user_id'], 'VENTA_REGISTRADA', 'venta', $venta_id,
                    $art_row['descripcion'] . ' x' . $cantidad . ' | ' . $forma_pago);
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'msg'  => 'Venta registrada. Total: ' . formato_pesos($precio_unit * $cantidad),
                ];
                header('Location: index');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error al registrar: ' . $e->getMessage();
        }
    }
}

$page_title    = 'Nueva Venta';
$page_current  = 'ventas_nueva';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:660px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-cart-plus"></i> Datos de la Venta</span>
            </div>
            <div class="form-grid">

                <!-- Artículo -->
                <div class="form-group" style="grid-column:span 2">
                    <label>Artículo *</label>
                    <input type="text" id="art_search_v" list="art_list_v"
                           value="<?php
                               $ai = (int)($v['articulo_id'] ?? 0);
                               if ($ai && isset($articulos_map[$ai])) echo e($articulos_map[$ai]['label']);
                           ?>"
                           placeholder="Buscar por nombre o SKU..."
                           autocomplete="off" required style="width:100%">
                    <datalist id="art_list_v">
                        <?php foreach ($articulos_raw as $art): ?>
                            <option value="<?= e($art['descripcion'] . ($art['sku'] ? ' [' . $art['sku'] . ']' : '')) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="articulo_id" id="articulo_id_v" value="<?= (int)($v['articulo_id'] ?? 0) ?>">
                    <small id="stock_info_v" class="text-muted">
                        <?php
                        $ai = (int)($v['articulo_id'] ?? 0);
                        if ($ai && isset($articulos_map[$ai])) {
                            echo 'Stock disponible: ' . $articulos_map[$ai]['stock'];
                        }
                        ?>
                    </small>
                </div>

                <!-- Cantidad -->
                <div class="form-group">
                    <label>Cantidad *</label>
                    <input type="number" name="cantidad" id="cantidad_v"
                           value="<?= (int)($v['cantidad'] ?? 1) ?>"
                           min="1" required oninput="calcTotal()">
                </div>

                <!-- Precio unitario -->
                <div class="form-group">
                    <label>Precio Unitario $</label>
                    <input type="number" name="precio_unitario" id="precio_v"
                           value="<?= (float)($v['precio_unitario'] ?? 0) ?: '' ?>"
                           step="0.01" min="0" required placeholder="0.00" oninput="calcTotal()">
                    <small class="text-muted">Pre-llenado desde el catálogo, editable.</small>
                </div>

                <!-- Forma de pago -->
                <div class="form-group" style="grid-column:span 2">
                    <label>Forma de Pago *</label>
                    <div style="display:flex;gap:16px;margin-top:4px">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                            <input type="radio" name="forma_pago" value="efectivo"
                                   <?= ($v['forma_pago'] ?? 'efectivo') === 'efectivo' ? 'checked' : '' ?>>
                            <i class="fa fa-money-bill" style="color:var(--success)"></i> Efectivo
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                            <input type="radio" name="forma_pago" value="tarjeta"
                                   <?= ($v['forma_pago'] ?? '') === 'tarjeta' ? 'checked' : '' ?>>
                            <i class="fa fa-credit-card" style="color:var(--warning)"></i> Tarjeta
                        </label>
                    </div>
                </div>

                <?php if (!es_vendedor() && !empty($vendedores)): ?>
                <!-- Vendedor (solo admin/supervisor) -->
                <div class="form-group" style="grid-column:span 2">
                    <label>Vendedor *</label>
                    <select name="vendedor_id" required>
                        <option value="">— Seleccionar vendedor —</option>
                        <?php foreach ($vendedores as $vend): ?>
                            <option value="<?= $vend['id'] ?>" <?= ($v['vendedor_id'] ?? '') == $vend['id'] ? 'selected' : '' ?>>
                                <?= e($vend['nombre'] . ' ' . $vend['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Observaciones -->
                <div class="form-group" style="grid-column:span 2">
                    <label>Observaciones</label>
                    <input type="text" name="observaciones" value="<?= e($v['observaciones'] ?? '') ?>"
                           placeholder="Opcional...">
                </div>

            </div>

            <!-- Total calculado -->
            <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:18px;margin-top:16px">
                <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Total de la Venta</div>
                <div id="total_display" style="font-size:1.8rem;font-weight:800;color:var(--primary-light);margin-top:6px">
                    $ 0,00
                </div>
            </div>
        </div>

        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary">
                <i class="fa fa-check"></i> Registrar Venta
            </button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$art_map_json    = json_encode($articulos_map,        JSON_UNESCAPED_UNICODE);
$art_search_json = json_encode($articulos_search_map, JSON_UNESCAPED_UNICODE);
$page_scripts = <<<JS
<script>
const artMapV    = $art_map_json;
const artSrchV   = $art_search_json;

document.getElementById('art_search_v').addEventListener('change', function() {
    const val  = this.value.trim();
    const item = artSrchV[val];
    if (item) {
        document.getElementById('articulo_id_v').value = item.id;
        const info = artMapV[item.id];
        if (info) {
            document.getElementById('precio_v').value = info.precio.toFixed(2);
            document.getElementById('stock_info_v').textContent = 'Stock disponible: ' + info.stock;
            calcTotal();
        }
    } else if (val === '') {
        document.getElementById('articulo_id_v').value = '';
        document.getElementById('stock_info_v').textContent = '';
    }
});

// Actualizar precio según forma_pago seleccionada
document.querySelectorAll('[name=forma_pago]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        const artId = parseInt(document.getElementById('articulo_id_v').value);
        if (!artId || !artMapV[artId]) return;
        const info = artMapV[artId];
        if (this.value === 'tarjeta' && info.tarjeta > 0) {
            document.getElementById('precio_v').value = info.tarjeta.toFixed(2);
        } else if (this.value === 'efectivo' && info.contado > 0) {
            document.getElementById('precio_v').value = info.contado.toFixed(2);
        } else {
            document.getElementById('precio_v').value = info.precio.toFixed(2);
        }
        calcTotal();
    });
});

function calcTotal() {
    const precio = parseFloat(document.getElementById('precio_v').value) || 0;
    const cant   = parseInt(document.getElementById('cantidad_v').value) || 1;
    const total  = precio * cant;
    document.getElementById('total_display').textContent =
        '\$ ' + total.toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}

document.addEventListener('DOMContentLoaded', calcTotal);
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
