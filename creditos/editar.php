<?php
// creditos/editar.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

// Obtener datos actuales
$stmt = $pdo->prepare("SELECT * FROM ic_creditos WHERE id=?");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) {
    header('Location: index.php');
    exit;
}

// Verificar si hay cuotas pagadas
$pagadas_stmt = $pdo->prepare("SELECT COUNT(*) as c, IFNULL(SUM(monto_cuota), 0) as total FROM ic_cuotas WHERE credito_id=? AND estado='PAGADA'");
$pagadas_stmt->execute([$id]);
$pagadas_info = $pagadas_stmt->fetch();
$cuotas_pagadas = (int) $pagadas_info['c'];
$total_pagado = (float) $pagadas_info['total'];

// Listas para el formulario
$clientes = $pdo->query("SELECT id,nombres,apellidos FROM ic_clientes WHERE estado='ACTIVO' OR id={$cr['cliente_id']} ORDER BY apellidos,nombres")->fetchAll();
$articulos = $pdo->query("SELECT id,descripcion,precio_venta FROM ic_articulos WHERE activo=1 OR id={$cr['articulo_id']} ORDER BY descripcion")->fetchAll();
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();
$vendedores = $pdo->query("SELECT id,nombre,apellido FROM ic_vendedores WHERE activo=1 OR id=".($cr['vendedor_id'] ?? 0)." ORDER BY nombre")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;
    if (
        empty($v['cliente_id']) || empty($v['articulo_id']) || empty($v['cobrador_id']) || empty($v['vendedor_id']) ||
        empty($v['cant_cuotas']) || ($cuotas_pagadas == 0 && empty($v['primer_vencimiento']))
    ) {
        $error = 'Completá todos los campos obligatorios.';
    } else {
        $precio = (float) $v['precio_articulo'];
        $interes = (float) $v['interes_pct'];
        $monto_tot = round($precio * (1 + ($interes / 100)), 2);
        $cant_cuotas = (int) $v['cant_cuotas'];

        if ($cuotas_pagadas > 0) {
            if ($cant_cuotas <= $cuotas_pagadas) {
                $error = "La cantidad de cuotas ($cant_cuotas) debe ser mayor a las cuotas ya pagadas ($cuotas_pagadas).";
            } elseif ($monto_tot <= $total_pagado) {
                $error = "El monto total ($monto_tot) debe ser mayor a lo ya pagado ($total_pagado).";
            }
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();

                // 1. Actualizar el crédito
                $upd = $pdo->prepare("
                    UPDATE ic_creditos SET
                        cliente_id=?, articulo_id=?, cobrador_id=?, vendedor_id=?,
                        precio_articulo=?, monto_total=?, interes_pct=?, interes_moratorio_pct=?,
                        frecuencia=?, cant_cuotas=?, observaciones=?
                    WHERE id=?
                ");
                $upd->execute([
                    $v['cliente_id'], $v['articulo_id'], $v['cobrador_id'], $v['vendedor_id'],
                    $precio, $monto_tot, $interes, (float)($v['interes_moratorio_pct'] ?? 15),
                    $v['frecuencia'], $cant_cuotas, trim($v['observaciones'] ?? ''),
                    $id
                ]);

                // 2. Gestionar Cuotas
                if ($cuotas_pagadas == 0) {
                    // Borrar todas y rehacer
                    $pdo->prepare("DELETE FROM ic_cuotas WHERE credito_id=?")->execute([$id]);
                    $monto_cuota = round($monto_tot / $cant_cuotas, 2);
                    $pdo->prepare("UPDATE ic_creditos SET monto_cuota=?, primer_vencimiento=? WHERE id=?")->execute([$monto_cuota, $v['primer_vencimiento'], $id]);
                    
                    generar_cuotas($id, [
                        'primer_vencimiento' => $v['primer_vencimiento'],
                        'cant_cuotas' => $cant_cuotas,
                        'frecuencia' => $v['frecuencia'],
                        'monto_cuota' => $monto_cuota
                    ], $pdo);
                } else {
                    // Hay pagos. Borrar solo pendientes/vencidas
                    $pdo->prepare("DELETE FROM ic_cuotas WHERE credito_id=? AND estado != 'PAGADA'")->execute([$id]);
                    
                    $saldo_restante = $monto_tot - $total_pagado;
                    $cuotas_restantes = $cant_cuotas - $cuotas_pagadas;
                    $nuevo_monto_cuota = round($saldo_restante / $cuotas_restantes, 2);
                    
                    $pdo->prepare("UPDATE ic_creditos SET monto_cuota=? WHERE id=?")->execute([$nuevo_monto_cuota, $id]);

                    // Buscar fecha del último pago para proyectar los nuevos vencimientos
                    $last_paid = $pdo->prepare("SELECT fecha_vencimiento FROM ic_cuotas WHERE credito_id=? AND estado='PAGADA' ORDER BY numero_cuota DESC LIMIT 1");
                    $last_paid->execute([$id]);
                    $fecha_last = $last_paid->fetchColumn();
                    $f = new DateTime($fecha_last);

                    for ($i = 1; $i <= $cuotas_restantes; $i++) {
                        switch ($v['frecuencia']) {
                            case 'semanal': $f->modify('+7 days'); break;
                            case 'quincenal': $f->modify('+15 days'); break;
                            case 'mensual': $f->modify('+1 month'); break;
                        }
                        $stmt_ins_c = $pdo->prepare("INSERT INTO ic_cuotas (credito_id, numero_cuota, fecha_vencimiento, monto_cuota) VALUES (?, ?, ?, ?)");
                        $stmt_ins_c->execute([$id, $cuotas_pagadas + $i, $f->format('Y-m-d'), $nuevo_monto_cuota]);
                    }
                }

                $pdo->commit();
                registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_EDITADO', 'credito', $id);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito editado satisfactoriamente.'];
                header("Location: ver.php?id=$id");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error al actualizar: ' . $e->getMessage();
            }
        }
    }
} else {
    // Cargar datos actuales en $v
    $v = $cr;
}

$page_title = 'Editar Crédito #' . $id;
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:860px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($cuotas_pagadas > 0): ?>
        <div class="alert-ic alert-warning"><i class="fa fa-info-circle"></i> 
            Este crédito ya tiene <strong><?= $cuotas_pagadas ?> cuotas pagadas</strong> (Total cobrado: <?= formato_pesos($total_pagado) ?>). <br>
            Si modificás el monto total o la cantidad total de cuotas, el sistema mantendrá intactas las cuotas ya cobradas y recalculará una nueva cuota mensual para el saldo restante. No se podrá modificar el primer vencimiento.
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title"><i class="fa fa-edit"></i> Edición de
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
                                <?= ($v['articulo_id'] == $art['id']) ? 'selected' : '' ?>>
                                <?= e($art['descripcion']) ?> —
                                <?= $art['precio_venta'] ? formato_pesos($art['precio_venta']) : 'sin precio' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Precio del Artículo $</label>
                    <input type="number" name="precio_articulo" id="precio_articulo"
                        value="<?= $v['precio_articulo'] ?>" step="0.01" min="0" required
                        oninput="calcularCuotas()">
                </div>
                <div class="form-group">
                    <label>Interés Total % (sobre el precio)</label>
                    <input type="number" name="interes_pct" id="interes_pct" value="<?= $v['interes_pct'] ?>"
                        step="0.01" min="0" max="999" oninput="calcularCuotas()">
                </div>
                <div class="form-group">
                    <label>Cuotas Mensuales (Cantidad Total) *</label>
                    <input type="number" name="cant_cuotas" id="cant_cuotas" value="<?= $v['cant_cuotas'] ?>" min="<?= $cuotas_pagadas > 0 ? $cuotas_pagadas + 1 : 1 ?>" max="120"
                        required oninput="calcularCuotas()">
                </div>
                <div class="form-group">
                    <label>Frecuencia de Pagos *</label>
                    <select name="frecuencia" id="frecuencia" required onchange="calcularCuotas()">
                        <option value="semanal" <?= $v['frecuencia'] === 'semanal' ? 'selected' : '' ?>>Semanal
                        </option>
                        <option value="quincenal" <?= $v['frecuencia'] === 'quincenal' ? 'selected' : '' ?>>Quincenal
                        </option>
                        <option value="mensual" <?= $v['frecuencia'] === 'mensual' ? 'selected' : '' ?>>Mensual
                        </option>
                    </select>
                </div>
                
                <?php if ($cuotas_pagadas == 0): ?>
                <div class="form-group">
                    <label>Primer Vencimiento *</label>
                    <input type="date" name="primer_vencimiento" value="<?= $v['primer_vencimiento'] ?>" required>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Interés Moratorio % (Mensual)</label>
                    <input type="number" name="interes_moratorio_pct" value="<?= $v['interes_moratorio_pct'] ?>"
                        step="0.01">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Observaciones / Detalles</label>
                    <input type="text" name="observaciones" class="form-control"
                        value="<?= e($v['observaciones'] ?? '') ?>" placeholder="Opcional...">
                </div>
            </div>
            <div
                style="margin-top:20px;padding:15px;background:var(--dark-bg);border:1px solid var(--dark-border);border-radius:6px;display:flex;justify-content:space-around">
                <div class="text-center">
                    <div class="text-muted" style="font-size:.8rem">Monto Total Calculado</div>
                    <div class="fw-bold" style="font-size:1.2rem;color:var(--primary)" id="lbl_monto_total">
                        <?= formato_pesos($v['monto_total']) ?></div>
                </div>
                <div class="text-center">
                    <div class="text-muted" style="font-size:.8rem">Valor Cuota (Restantes)</div>
                    <div class="fw-bold" style="font-size:1.2rem;color:var(--primary)" id="lbl_monto_cuota">
                        <?php
                            $saldo_proyectado = $v['monto_total'] - $total_pagado;
                            $cuotas_proj = max(1, $v['cant_cuotas'] - $cuotas_pagadas);
                            echo formato_pesos($saldo_proyectado / $cuotas_proj);
                        ?>
                    </div>
                </div>
            </div>

            <hr class="divider">
            <h5 style="margin-bottom:10px">Asignación</h5>
            <div class="form-grid">
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
                    <label>Vendedor *</label>
                    <select name="vendedor_id" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($vendedores as $ven): ?>
                            <option value="<?= $ven['id'] ?>" <?= ($v['vendedor_id'] == $ven['id']) ? 'selected' : '' ?>>
                                <?= e($ven['nombre'] . ' ' . $ven['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3 mb-5">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar Modificaciones del Crédito</button>
            <a href="ver.php?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<script>
    const cbArticulo = document.getElementById('sel_articulo');
    const inpPrecio = document.getElementById('precio_articulo');
    const inpInteres = document.getElementById('interes_pct');
    const inpCuotas = document.getElementById('cant_cuotas');
    const lblTotal = document.getElementById('lbl_monto_total');
    const lblCuota = document.getElementById('lbl_monto_cuota');
    
    // Variables pre-existentes para recálculo visual 
    const cuotasPagadas = <?= $cuotas_pagadas ?>;
    const totalPagado = <?= $total_pagado ?>;

    function formatMoney(n) {
        return "$ " + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function cargarPrecio(sel) {
        if (!sel.value) return;
        const o = sel.options[sel.selectedIndex];
        if (o.dataset.precio) {
            inpPrecio.value = o.dataset.precio;
            calcularCuotas();
        }
    }

    function calcularCuotas() {
        let precio = parseFloat(inpPrecio.value) || 0;
        let interes = parseFloat(inpInteres.value) || 0;
        let cuotas = parseInt(inpCuotas.value) || 1;

        let total = precio * (1 + (interes / 100));
        lblTotal.textContent = formatMoney(total);
        
        let restante = total - totalPagado;
        if(restante < 0) restante = 0;
        
        let c_restantes = cuotas - cuotasPagadas;
        if(c_restantes < 1) c_restantes = 1;

        let m_cuota = restante / c_restantes;
        lblCuota.textContent = formatMoney(m_cuota);
    }
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
