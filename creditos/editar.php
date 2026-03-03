<?php
// creditos/editar.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM ic_creditos WHERE id=?");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) { header('Location: index.php'); exit; }

// Cuotas pagadas
$pagadas_stmt = $pdo->prepare("SELECT COUNT(*) as c, IFNULL(SUM(monto_cuota),0) as total FROM ic_cuotas WHERE credito_id=? AND estado='PAGADA'");
$pagadas_stmt->execute([$id]);
$pagadas_info  = $pagadas_stmt->fetch();
$cuotas_pagadas = (int) $pagadas_info['c'];

// Descripción del artículo: usar articulo_desc si existe, sino buscar en ic_articulos
$articulo_desc_actual = $cr['articulo_desc'] ?? '';
if (empty($articulo_desc_actual) && !empty($cr['articulo_id'])) {
    $art = $pdo->prepare("SELECT descripcion FROM ic_articulos WHERE id=?");
    $art->execute([$cr['articulo_id']]);
    $articulo_desc_actual = $art->fetchColumn() ?: '';
}

$clientes   = $pdo->query("SELECT id,nombres,apellidos FROM ic_clientes WHERE estado='ACTIVO' OR id={$cr['cliente_id']} ORDER BY apellidos,nombres")->fetchAll();
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();
$vendedores = $pdo->query("SELECT id,nombre,apellido FROM ic_vendedores WHERE activo=1 OR id=".($cr['vendedor_id'] ?? 0)." ORDER BY nombre")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;

    if (
        empty($v['cliente_id']) || empty(trim($v['articulo_desc'] ?? '')) ||
        empty($v['cobrador_id']) || empty($v['cant_cuotas']) ||
        ($cuotas_pagadas == 0 && empty($v['primer_vencimiento']))
    ) {
        $error = 'Completá todos los campos obligatorios.';
    } else {
        $precio      = (float) $v['precio_articulo'];
        $interes     = (float) $v['interes_pct'];
        $monto_tot   = round($precio * (1 + ($interes / 100)), 2);
        $cant_cuotas = (int) $v['cant_cuotas'];

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE ic_creditos SET
                    cliente_id=?, articulo_id=NULL, articulo_desc=?,
                    cobrador_id=?, vendedor_id=?,
                    precio_articulo=?, monto_total=?, interes_pct=?, interes_moratorio_pct=?,
                    frecuencia=?, cant_cuotas=?, observaciones=?
                WHERE id=?
            ");
            $upd->execute([
                $v['cliente_id'],
                trim($v['articulo_desc']),
                $v['cobrador_id'],
                ($v['vendedor_id'] ?: null),
                $precio,
                $monto_tot,
                $interes,
                (float)($v['interes_moratorio_pct'] ?? 15),
                $v['frecuencia'],
                $cant_cuotas,
                trim($v['observaciones'] ?? ''),
                $id,
            ]);

            // Regenerar cuotas solo si no hay pagos
            if ($cuotas_pagadas == 0) {
                $pdo->prepare("DELETE FROM ic_cuotas WHERE credito_id=?")->execute([$id]);
                $monto_cuota = round($monto_tot / $cant_cuotas, 2);
                $pdo->prepare("UPDATE ic_creditos SET monto_cuota=?, primer_vencimiento=? WHERE id=?")
                    ->execute([$monto_cuota, $v['primer_vencimiento'], $id]);
                generar_cuotas($id, [
                    'primer_vencimiento' => $v['primer_vencimiento'],
                    'cant_cuotas'        => $cant_cuotas,
                    'frecuencia'         => $v['frecuencia'],
                    'monto_cuota'        => $monto_cuota,
                ], $pdo);
            }

            $pdo->commit();
            registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_EDITADO', 'credito', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito actualizado correctamente.'];
            header("Location: ver.php?id=$id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error al actualizar: ' . $e->getMessage();
        }
    }
    // repopular
    $articulo_desc_actual = trim($v['articulo_desc'] ?? $articulo_desc_actual);
} else {
    $v = $cr;
}

$page_title   = 'Editar Crédito #' . $id;
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:860px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($cuotas_pagadas > 0): ?>
        <div class="alert-ic alert-warning">
            <i class="fa fa-info-circle"></i>
            Este crédito tiene <strong><?= $cuotas_pagadas ?> cuotas pagadas</strong>.
            Podés editar los datos generales, pero para reestructurar el cronograma usá
            <a href="refinanciar.php?id=<?= $id ?>" class="fw-bold" style="color:var(--warning)">
                <i class="fa fa-sync-alt"></i> Refinanciar
            </a>.
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">

        <!-- ── Datos del crédito ──────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-edit"></i> Edición de Crédito</span>
            </div>
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
                    <label>Descripción del Artículo / Producto *</label>
                    <input type="text" name="articulo_desc"
                           value="<?= e($articulo_desc_actual) ?>"
                           placeholder="Ej: Heladera Samsung 400L, Moto 150cc..."
                           required maxlength="200">
                </div>

                <div class="form-group">
                    <label>Precio del Artículo $</label>
                    <input type="number" name="precio_articulo" id="precio_articulo"
                           value="<?= $v['precio_articulo'] ?>"
                           step="0.01" min="0" required oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Interés Total %</label>
                    <input type="number" name="interes_pct" id="interes_pct"
                           value="<?= $v['interes_pct'] ?>"
                           step="0.01" min="0" max="999" oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Cantidad de Cuotas *</label>
                    <input type="number" name="cant_cuotas" id="cant_cuotas"
                           value="<?= $v['cant_cuotas'] ?>"
                           min="<?= $cuotas_pagadas > 0 ? $cuotas_pagadas + 1 : 1 ?>" max="520"
                           required oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Frecuencia *</label>
                    <select name="frecuencia" id="frecuencia" required onchange="calcularCuotas()">
                        <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= ($v['frecuencia'] === $k) ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($cuotas_pagadas == 0): ?>
                <div class="form-group">
                    <label>Primer Vencimiento *</label>
                    <input type="date" name="primer_vencimiento"
                           value="<?= $v['primer_vencimiento'] ?>" required>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Interés Moratorio % semanal</label>
                    <input type="number" name="interes_moratorio_pct"
                           value="<?= $v['interes_moratorio_pct'] ?>" step="0.01">
                </div>

                <div class="form-group">
                    <label>Cobrador *</label>
                    <select name="cobrador_id" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($cobradores as $cob): ?>
                            <option value="<?= $cob['id'] ?>" <?= ($v['cobrador_id'] == $cob['id']) ? 'selected' : '' ?>>
                                <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Vendedor</label>
                    <select name="vendedor_id">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($vendedores as $ven): ?>
                            <option value="<?= $ven['id'] ?>" <?= ($v['vendedor_id'] == $ven['id']) ? 'selected' : '' ?>>
                                <?= e($ven['nombre'] . ' ' . $ven['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="grid-column:span 2">
                    <label>Observaciones</label>
                    <input type="text" name="observaciones"
                           value="<?= e($v['observaciones'] ?? '') ?>"
                           placeholder="Opcional...">
                </div>

            </div>

            <!-- Calculador -->
            <div style="margin-top:20px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">
                        Monto Total Calculado
                    </div>
                    <div id="lbl_monto_total"
                         style="font-size:1.5rem;font-weight:800;color:var(--primary-light);margin-top:6px">
                        <?= formato_pesos($v['monto_total']) ?>
                    </div>
                </div>
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">
                        Valor por Cuota
                    </div>
                    <div id="lbl_monto_cuota"
                         style="font-size:1.5rem;font-weight:800;color:var(--success);margin-top:6px">
                        <?= formato_pesos($v['cant_cuotas'] > 0 ? $v['monto_total'] / $v['cant_cuotas'] : 0) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3 mb-4">
            <button type="submit" class="btn-ic btn-primary">
                <i class="fa fa-save"></i> Guardar Cambios
            </button>
            <a href="ver.php?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<script>
function fmt(n) {
    return '$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function calcularCuotas() {
    const precio  = parseFloat(document.getElementById('precio_articulo').value) || 0;
    const interes = parseFloat(document.getElementById('interes_pct').value) || 0;
    const cuotas  = parseInt(document.getElementById('cant_cuotas').value) || 1;
    const total   = precio * (1 + interes / 100);
    document.getElementById('lbl_monto_total').textContent = fmt(total);
    document.getElementById('lbl_monto_cuota').textContent = fmt(total / cuotas);
}
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
