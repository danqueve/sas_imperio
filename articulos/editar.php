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
    $desc           = trim($_POST['descripcion'] ?? '');
    $sku            = trim($_POST['sku'] ?? '') ?: null;
    $cat            = trim($_POST['categoria'] ?? '');
    $costo          = $_POST['precio_costo'] !== '' ? (float) $_POST['precio_costo'] : null;
    $venta          = $_POST['precio_venta'] !== '' ? (float) $_POST['precio_venta'] : null;
    $contado        = $_POST['precio_contado'] !== '' ? (float) $_POST['precio_contado'] : null;
    $tarjeta        = $_POST['precio_tarjeta'] !== '' ? (float) $_POST['precio_tarjeta'] : null;
    $stock          = (int) ($_POST['stock'] ?? 0);
    $activo         = isset($_POST['activo']) ? 1 : 0;

    if (empty($desc)) {
        $error = 'La descripción es obligatoria.';
    } else {
        try {
            if ($esEdicion) {
                // Registrar historial si cambiaron precios
                $prev = $pdo->prepare("SELECT precio_costo,precio_venta,precio_contado,precio_tarjeta FROM ic_articulos WHERE id=?");
                $prev->execute([$id]);
                $ant = $prev->fetch(PDO::FETCH_ASSOC);
                $campos_precio = ['precio_costo','precio_venta','precio_contado','precio_tarjeta'];
                $nuevos = [$costo, $venta, $contado, $tarjeta];
                $cambio = false;
                foreach ($campos_precio as $i => $campo) {
                    if ((float)($ant[$campo] ?? 0) !== (float)($nuevos[$i] ?? 0)) { $cambio = true; break; }
                }
                if ($cambio) {
                    $pdo->prepare("
                        INSERT INTO ic_precios_historial
                          (articulo_id, usuario_id,
                           precio_costo_ant, precio_venta_ant, precio_contado_ant, precio_tarjeta_ant,
                           precio_costo_nuevo, precio_venta_nuevo, precio_contado_nuevo, precio_tarjeta_nuevo)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$id, $_SESSION['user_id'],
                        $ant['precio_costo'], $ant['precio_venta'], $ant['precio_contado'], $ant['precio_tarjeta'],
                        $costo, $venta, $contado, $tarjeta]);
                }
                $pdo->prepare("UPDATE ic_articulos SET descripcion=?,sku=?,categoria=?,precio_costo=?,precio_venta=?,precio_contado=?,precio_tarjeta=?,stock=?,activo=? WHERE id=?")
                    ->execute([$desc, $sku, $cat, $costo, $venta, $contado, $tarjeta, $stock, $activo, $id]);
                registrar_log($pdo, $_SESSION['user_id'], 'ARTICULO_EDITADO', 'articulo', $id, $desc);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artículo actualizado.'];
            } else {
                $pdo->prepare("INSERT INTO ic_articulos (descripcion,sku,categoria,precio_costo,precio_venta,precio_contado,precio_tarjeta,stock,activo) VALUES (?,?,?,?,?,?,?,?,1)")
                    ->execute([$desc, $sku, $cat, $costo, $venta, $contado, $tarjeta, $stock]);
                $art_id = (int) $pdo->lastInsertId();
                registrar_log($pdo, $_SESSION['user_id'], 'ARTICULO_CREADO', 'articulo', $art_id, $desc);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artículo agregado.'];
            }
            header('Location: index');
            exit;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_sku') || str_contains($e->getMessage(), 'Duplicate entry')) {
                $error = 'El SKU ingresado ya existe. Usá uno diferente.';
            } else {
                $error = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }
}

$page_title = $esEdicion ? 'Editar Artículo' : 'Nuevo Artículo';
$page_current = 'articulos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:700px">
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
                    <label>SKU / Código interno</label>
                    <input type="text" name="sku" value="<?= e($a['sku'] ?? '') ?>"
                           placeholder="Ej: ELEC-001" maxlength="60">
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
                        step="0.01" min="0" placeholder="0.00" oninput="calcCostoFinal()">
                </div>

                <div class="form-group">
                    <label>Precio Contado $</label>
                    <input type="number" name="precio_contado" value="<?= $a['precio_contado'] ?? '' ?>"
                        step="0.01" min="0" placeholder="0.00">
                </div>

                <div class="form-group">
                    <label>Precio Tarjeta $</label>
                    <input type="number" name="precio_tarjeta" value="<?= $a['precio_tarjeta'] ?? '' ?>"
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

                <!-- Calculadora Costo Final -->
                <div class="form-group" style="grid-column:span 2">
                    <label><i class="fa fa-calculator" style="opacity:.6"></i> Calculadora — Precio Venta ÷ Semanas</label>
                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                        <input type="number" id="calc_semanas" min="1" max="104"
                               placeholder="N° de semanas" style="max-width:160px"
                               oninput="calcCostoFinal()">
                        <span class="text-muted">semanas</span>
                        <div style="background:rgba(0,0,0,.3);border-radius:8px;padding:10px 18px;min-width:160px">
                            <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.7px">Cuota semanal</div>
                            <div id="calc_resultado" style="font-size:1.15rem;font-weight:700;color:var(--success)">—</div>
                        </div>
                    </div>
                    <small class="text-muted">No se guarda. Orientativo para calcular el precio de cuota.</small>
                </div>

            </div>
        </div>

        <?php if ($esEdicion): ?>
        <!-- QR Preview -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-qrcode"></i> Código QR del Artículo</span>
            </div>
            <div style="display:flex;gap:20px;align-items:flex-start;padding:4px 0">
                <div id="qr-preview" style="background:#fff;padding:8px;border-radius:6px;display:inline-block;flex-shrink:0"></div>
                <div style="display:flex;flex-direction:column;gap:10px;padding-top:4px">
                    <div>
                        <span class="text-muted" style="font-size:.8rem">Artículo #<?= $id ?></span>
                        <?php if (!empty($a['sku'])): ?>
                            <span style="margin-left:8px;font-size:.85rem;font-weight:600">SKU: <?= e($a['sku']) ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="qr_label?id=<?= $id ?>" target="_blank" class="btn-ic btn-primary btn-sm" style="width:fit-content">
                        <i class="fa fa-print"></i> Imprimir Etiqueta PDF
                    </a>
                    <small class="text-muted">El QR contiene el SKU (o ID si no tiene SKU). Imprimí y pegalo en el producto.</small>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            new QRCode(document.getElementById('qr-preview'), {
                text: '<?= e(addslashes($a['sku'] ?: 'ART-' . $id)) ?>',
                width: 110, height: 110
            });
        });
        </script>
        <?php endif; ?>

        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar</button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>

    <?php if ($esEdicion):
        $hist = $pdo->prepare("
            SELECT h.*, CONCAT(u.nombre,' ',u.apellido) AS editor
            FROM ic_precios_historial h
            JOIN ic_usuarios u ON u.id = h.usuario_id
            WHERE h.articulo_id = ?
            ORDER BY h.created_at DESC LIMIT 20
        ");
        $hist->execute([$id]);
        $historial = $hist->fetchAll();
    ?>
    <?php if ($historial): ?>
    <div class="card-ic mt-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-clock-rotate-left"></i> Historial de precios</span>
        </div>
        <div style="overflow-x:auto">
        <table class="table-ic" style="font-size:.8rem">
            <thead><tr>
                <th>Fecha</th><th>Editor</th>
                <th>Costo ant.</th><th>Costo nuevo</th>
                <th>Venta ant.</th><th>Venta nueva</th>
                <th>Contado ant.</th><th>Contado nuevo</th>
            </tr></thead>
            <tbody>
            <?php foreach ($historial as $h): ?>
            <tr>
                <td class="text-muted nowrap"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                <td><?= e($h['editor']) ?></td>
                <td class="text-muted"><?= $h['precio_costo_ant'] !== null ? formato_pesos((float)$h['precio_costo_ant']) : '—' ?></td>
                <td><?= $h['precio_costo_nuevo'] !== null ? formato_pesos((float)$h['precio_costo_nuevo']) : '—' ?></td>
                <td class="text-muted"><?= $h['precio_venta_ant'] !== null ? formato_pesos((float)$h['precio_venta_ant']) : '—' ?></td>
                <td><?= $h['precio_venta_nuevo'] !== null ? formato_pesos((float)$h['precio_venta_nuevo']) : '—' ?></td>
                <td class="text-muted"><?= $h['precio_contado_ant'] !== null ? formato_pesos((float)$h['precio_contado_ant']) : '—' ?></td>
                <td><?= $h['precio_contado_nuevo'] !== null ? formato_pesos((float)$h['precio_contado_nuevo']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$page_scripts = <<<JS
<script>
function calcCostoFinal() {
    const pv  = parseFloat(document.getElementById('precio_venta').value) || 0;
    const sem = parseInt(document.getElementById('calc_semanas').value) || 0;
    const res = document.getElementById('calc_resultado');
    if (pv > 0 && sem > 0) {
        res.textContent = '\$ ' + (pv / sem).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        res.textContent = '—';
    }
}
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
