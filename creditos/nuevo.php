<?php
// ============================================================
// creditos/nuevo.php — Alta de crédito con calculador
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$cliente_id = (int) ($_GET['cliente_id'] ?? 0);

$clientes = $pdo->query("SELECT id,nombres,apellidos FROM ic_clientes WHERE estado='ACTIVO' ORDER BY apellidos,nombres")->fetchAll();
$articulos = $pdo->query("SELECT id,descripcion,precio_venta FROM ic_articulos WHERE activo=1 ORDER BY descripcion")->fetchAll();
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();
$vendedores = $pdo->query("SELECT id,nombre,apellido FROM ic_vendedores WHERE activo=1 ORDER BY nombre")->fetchAll();

$error = '';
$v = ['cliente_id' => $cliente_id, 'frecuencia' => 'semanal', 'cant_cuotas' => 12, 'interes_pct' => 0, 'interes_moratorio_pct' => 15];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;
    // Validación
    if (
        empty($v['cliente_id']) || empty($v['articulo_id']) || empty($v['cobrador_id']) || empty($v['vendedor_id']) ||
        empty($v['cant_cuotas']) || empty($v['primer_vencimiento'])
    ) {
        $error = 'Completá todos los campos obligatorios.';
    } else {
        $precio = (float) $v['precio_articulo'];
        $monto_tot = (float) $v['monto_total'];
        $monto_cuot = (float) $v['monto_cuota'];

        if ($precio <= 0 || $monto_tot <= 0 || $monto_cuot <= 0) {
            $error = 'Los montos deben ser mayores a cero.';
        } else {
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("
                    INSERT INTO ic_creditos
                      (cliente_id, articulo_id, cobrador_id, vendedor_id, fecha_alta, precio_articulo, monto_total,
                       interes_pct, interes_moratorio_pct, frecuencia, cant_cuotas, monto_cuota,
                       dia_cobro, primer_vencimiento, estado, observaciones, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $ins->execute([
                    $v['cliente_id'],
                    $v['articulo_id'],
                    $v['cobrador_id'],
                    $v['vendedor_id'],
                    date('Y-m-d'),
                    $precio,
                    $monto_tot,
                    (float) ($v['interes_pct'] ?? 0),
                    (float) ($v['interes_moratorio_pct'] ?? 15),
                    $v['frecuencia'],
                    (int) $v['cant_cuotas'],
                    $monto_cuot,
                    ($v['dia_cobro'] ?: null),
                    $v['primer_vencimiento'],
                    'EN_CURSO',
                    trim($v['observaciones'] ?? ''),
                    $_SESSION['user_id'],
                ]);
                $credito_id = $pdo->lastInsertId();

                // Generar cuotas
                generar_cuotas($credito_id, [
                    'primer_vencimiento' => $v['primer_vencimiento'],
                    'cant_cuotas' => (int) $v['cant_cuotas'],
                    'frecuencia' => $v['frecuencia'],
                    'monto_cuota' => $monto_cuot,
                ], $pdo);

                $pdo->commit();
                registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_CREADO', 'credito', (int)$credito_id,
                    'Cuotas: ' . $v['cant_cuotas'] . ' | Total: ' . formato_pesos($monto_tot));
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito registrado y cuotas generadas.'];
                header("Location: ver.php?id=$credito_id");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Nuevo Crédito';
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:860px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Datos del
                    Crédito</span></div>
            <div class="form-grid">
                <div class="form-group" style="grid-column:span 2">
                    <label>Cliente *</label>
                    <select name="cliente_id" required>
                        <option value="">— Seleccionar cliente —</option>
                        <?php foreach ($clientes as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= ($v['cliente_id'] == $cl['id']) ? 'selected' : '' ?>>
                                <?= e($cl['apellidos'] . ', ' . $cl['nombres']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column:span 2">
                    <label>Artículo *</label>
                    <select name="articulo_id" id="sel_articulo" required onchange="cargarPrecio(this)">
                        <option value="">— Seleccionar artículo —</option>
                        <?php foreach ($articulos as $art): ?>
                            <option value="<?= $art['id'] ?>" data-precio="<?= $art['precio_venta'] ?>"
                                <?= ($v['articulo_id'] ?? '') == $art['id'] ? 'selected' : '' ?>>
                                <?= e($art['descripcion']) ?> —
                                <?= $art['precio_venta'] ? formato_pesos($art['precio_venta']) : 'sin precio' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Precio del Artículo $</label>
                    <input type="number" name="precio_articulo" id="precio_articulo"
                        value="<?= $v['precio_articulo'] ?? '' ?>" step="0.01" min="0" required
                        oninput="calcularCuotas()">
                </div>
                <div class="form-group">
                    <label>Interés Total % (sobre el precio)</label>
                    <input type="number" name="interes_pct" id="interes_pct" value="<?= $v['interes_pct'] ?? 0 ?>"
                        step="0.01" min="0" max="999" oninput="calcularCuotas()">
                </div>
                <div class="form-group">
                    <label>Interés Moratorio % semanal</label>
                    <input type="number" name="interes_moratorio_pct" value="<?= $v['interes_moratorio_pct'] ?? 15 ?>"
                        step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label>Cobrador *</label>
                    <select name="cobrador_id" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($cobradores as $cob): ?>
                            <option value="<?= $cob['id'] ?>" <?= ($v['cobrador_id'] ?? '') == $cob['id'] ? 'selected' : '' ?>>
                                <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Vendedor *</label>
                    <select name="vendedor_id" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($vendedores as $ven): ?>
                            <option value="<?= $ven['id'] ?>" <?= ($v['vendedor_id'] ?? '') == $ven['id'] ? 'selected' : '' ?>>
                                <?= e($ven['nombre'] . ' ' . $ven['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- PLAN DE PAGO -->
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title"><i class="fa fa-calendar-alt"></i> Plan de Pago</span>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Frecuencia *</label>
                    <select name="frecuencia" id="frecuencia" required onchange="toggleDiaCobro();calcularCuotas()">
                        <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= ($v['frecuencia'] === $k) ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="grupo_dia_cobro">
                    <label>Día de Cobro (semanal)</label>
                    <select name="dia_cobro">
                        <option value="">— Cualquier día —</option>
                        <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'] as $n => $d): ?>
                            <option value="<?= $n ?>" <?= ($v['dia_cobro'] ?? '') == $n ? 'selected' : '' ?>>
                                <?= $d ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cantidad de Cuotas *</label>
                    <input type="number" name="cant_cuotas" id="cant_cuotas" value="<?= $v['cant_cuotas'] ?? 12 ?>"
                        min="1" max="520" required oninput="calcularCuotas()">
                </div>
                <div class="form-group">
                    <label>Primer Vencimiento *</label>
                    <input type="date" name="primer_vencimiento" value="<?= $v['primer_vencimiento'] ?? '' ?>" required>
                </div>
            </div>

            <!-- CALCULADOR DISPLAY -->
            <div style="margin-top:20px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px">
                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px">Monto
                        Total del Crédito</div>
                    <div id="monto_total_display"
                        style="font-size:1.6rem;font-weight:800;color:var(--primary-light);margin-top:6px">$ 0,00</div>
                    <input type="hidden" name="monto_total" id="monto_total" value="<?= $v['monto_total'] ?? 0 ?>">
                </div>
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px">
                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px">Monto
                        por Cuota</div>
                    <div id="monto_cuota_display"
                        style="font-size:1.6rem;font-weight:800;color:var(--success);margin-top:6px">$ 0,00</div>
                    <input type="hidden" name="monto_cuota" id="monto_cuota" value="<?= $v['monto_cuota'] ?? 0 ?>">
                </div>
            </div>
        </div>

        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title"><i class="fa fa-comment"></i> Observaciones</span>
            </div>
            <textarea name="observaciones" rows="3"
                placeholder="Notas adicionales sobre el crédito..."><?= e($v['observaciones'] ?? '') ?></textarea>
        </div>

        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Crear Crédito</button>
            <a href="index.php" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$page_scripts = <<<JS
<script>
function cargarPrecio(sel){
  const opt = sel.options[sel.selectedIndex];
  const precio = opt.dataset.precio || '';
  document.getElementById('precio_articulo').value = precio;
  calcularCuotas();
}
// Inicializar al cargar
document.addEventListener('DOMContentLoaded', function(){
  toggleDiaCobro();
  calcularCuotas();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>