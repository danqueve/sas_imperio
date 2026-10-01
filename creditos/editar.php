<?php
// creditos/editar.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index'); exit; }

$stmt = $pdo->prepare("SELECT * FROM ic_creditos WHERE id=?");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) { header('Location: index'); exit; }

// Cuotas pagadas
$pagadas_stmt = $pdo->prepare("SELECT COUNT(*) as c, IFNULL(SUM(monto_cuota),0) as total FROM ic_cuotas WHERE credito_id=? AND estado='PAGADA'");
$pagadas_stmt->execute([$id]);
$pagadas_info  = $pagadas_stmt->fetch();
$cuotas_pagadas = (int) $pagadas_info['c'];

// Cuotas con plata asociada — no alcanza con contar las PAGADA: una cuota
// PARCIAL puede tener pagos confirmados, y borrarla haría desaparecer un
// cobro ya aprobado. Es el guard de cualquier operación destructiva.
$con_pagos_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM ic_cuotas c
    WHERE c.credito_id = ?
      AND (c.estado IN ('PAGADA','PARCIAL','CAP_PAGADA')
           OR c.saldo_pagado > 0
           OR EXISTS (SELECT 1 FROM ic_pagos_confirmados pc WHERE pc.cuota_id = c.id)
           OR EXISTS (SELECT 1 FROM ic_pagos_temporales  pt WHERE pt.cuota_id = c.id))
");
$con_pagos_stmt->execute([$id]);
$cuotas_con_pagos = (int) $con_pagos_stmt->fetchColumn();

// ── Ítems del combo ─────────────────────────────────────────────────
// Un crédito es COMBO si tiene filas en ic_credito_articulos: es la única
// señal confiable. articulo_id IS NULL también lo tienen los artículos
// escritos a mano, y el prefijo "Combo: " de articulo_desc sobrevive a una
// refinanciación — ejecutar_refinanciacion() copia la descripción pero NO
// las filas de ic_credito_articulos (caso real: crédito #1720, que es un
// crédito simple con precio_articulo = monto_total).
$items_stmt = $pdo->prepare("
    SELECT articulo_id, descripcion, cantidad, precio_unitario, subtotal
    FROM ic_credito_articulos WHERE credito_id = ? ORDER BY id
");
$items_stmt->execute([$id]);
$combo_items       = $items_stmt->fetchAll();
$es_combo          = !empty($combo_items);
$combo_total_items = (float) array_sum(array_column($combo_items, 'subtotal'));

// Ajustes manuales de vencimiento (creditos/editar_vencimiento_cuota.php):
// cambiar primer_vencimiento los pisa, así que se avisa en el formulario.
$ajustes_stmt = $pdo->prepare("SELECT COUNT(*) FROM ic_cuota_vencimiento_historial WHERE credito_id = ?");
$ajustes_stmt->execute([$id]);
$ajustes_fecha = (int) $ajustes_stmt->fetchColumn();

$primera_cuota_stmt = $pdo->prepare("SELECT MIN(fecha_vencimiento) FROM ic_cuotas WHERE credito_id = ?");
$primera_cuota_stmt->execute([$id]);
$primera_cuota_fecha = $primera_cuota_stmt->fetchColumn() ?: null;

// Descripción del artículo: usar articulo_desc si existe, sino buscar en ic_articulos
$articulo_desc_actual = $cr['articulo_desc'] ?? '';
if (empty($articulo_desc_actual) && !empty($cr['articulo_id'])) {
    $art = $pdo->prepare("SELECT descripcion FROM ic_articulos WHERE id=?");
    $art->execute([$cr['articulo_id']]);
    $articulo_desc_actual = $art->fetchColumn() ?: '';
}

$stmtCl = $pdo->prepare("SELECT id,nombres,apellidos FROM ic_clientes WHERE estado='ACTIVO' OR id=? ORDER BY apellidos,nombres");
$stmtCl->execute([$cr['cliente_id']]);
$clientes = $stmtCl->fetchAll();
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();
$stmtVe = $pdo->prepare("SELECT id,nombre,apellido FROM ic_vendedores WHERE activo=1 OR id=? ORDER BY nombre");
$stmtVe->execute([$cr['vendedor_id'] ?? 0]);
$vendedores = $stmtVe->fetchAll();

// Catálogo de artículos para selector
$articulos_raw = $pdo->query("SELECT id, descripcion, precio_venta, sku, stock FROM ic_articulos WHERE activo=1 ORDER BY descripcion")->fetchAll();
$articulos_map = [];
$articulos_search_map = [];
foreach ($articulos_raw as $art) {
    $label = $art['descripcion'] . ($art['sku'] ? ' [' . $art['sku'] . ']' : '');
    $articulos_map[(int)$art['id']] = ['precio' => (float)$art['precio_venta'], 'desc' => $art['descripcion'], 'stock' => (int)$art['stock'], 'label' => $label];
    $articulos_search_map[$label]   = ['id' => (int)$art['id'], 'desc' => $art['descripcion']];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    // Las claves que el combo no postea (precio_articulo, interes_pct,
    // articulo_id/desc) se repueblan desde la BD, para que el re-render tras
    // un error de validación no quede con índices indefinidos.
    $v = $_POST + $cr;

    if ($es_combo) {
        // El artículo de un combo no se edita desde acá: se conserva tal cual,
        // sin importar qué llegue por POST.
        $articulo_id_edit   = 0;
        $articulo_desc_post = (string) $cr['articulo_desc'];
    } else {
        $articulo_id_edit   = (int)($v['articulo_id'] ?? 0);
        $articulo_desc_post = trim($v['articulo_desc'] ?? '');
    }
    $cant_cuotas = (int) ($v['cant_cuotas'] ?? 0);

    if (
        empty($v['cliente_id']) ||
        (!$es_combo && !$articulo_id_edit && empty($articulo_desc_post)) ||
        empty($v['cobrador_id']) || empty($v['cant_cuotas']) ||
        empty($v['primer_vencimiento'])
    ) {
        $error = 'Completá todos los campos obligatorios (incluido el artículo).';
    } elseif ($es_combo && round((float)($v['monto_cuota_combo'] ?? 0), 2) <= 0) {
        $error = 'El monto por cuota debe ser mayor a cero.';
    } elseif ($cant_cuotas !== (int) $cr['cant_cuotas'] && $cuotas_con_pagos > 0) {
        // Con cobros registrados el código no puede agregar ni quitar filas de
        // ic_cuotas: guardar otro cant_cuotas solo dejaría ic_creditos
        // desacoplado de COUNT(ic_cuotas).
        $error = 'Este crédito ya tiene cobros registrados: no se puede cambiar la cantidad de cuotas desde acá. Usá Refinanciar.';
    } else {
        if ($es_combo) {
            // precio_articulo (suma de ítems, solo referencia) e interes_pct
            // (siempre 0 en un combo) NUNCA se leen del formulario: el total
            // es cuota × cantidad, igual que al dar de alta el combo.
            $precio      = (float) $cr['precio_articulo'];
            $interes     = (float) $cr['interes_pct'];
            $monto_cuota = round((float) $v['monto_cuota_combo'], 2);
            $monto_tot   = round($monto_cuota * $cant_cuotas, 2);
        } else {
            $precio      = (float) $v['precio_articulo'];
            $interes     = (float) $v['interes_pct'];
            $monto_tot   = round($precio * (1 + ($interes / 100)), 2);
            $monto_cuota = round($monto_tot / $cant_cuotas, 2);
        }

        // ── ¿Cambió algo que mueva el cronograma? ───────────────────────
        // Se evalúa contra $cr, que todavía tiene los valores previos.
        $dinero_igual = static fn(float $a, float $b): bool => abs($a - $b) < 0.005;

        if ($es_combo) {
            // En un combo la cuota es un dato tipeado: se compara directo.
            $monto_cambio = !$dinero_igual($monto_cuota, (float) $cr['monto_cuota']);
        } else {
            // En un crédito simple monto_cuota es DERIVADO, y hay créditos
            // heredados donde no coincide con monto_total/cant_cuotas:
            // compararlo daría un falso positivo en cada guardado. Se comparan
            // los insumos, que sí son consistentes.
            $monto_cambio = !$dinero_igual($precio,  (float) $cr['precio_articulo'])
                         || !$dinero_igual($interes, (float) $cr['interes_pct']);
        }
        // Fechas y frecuencia se comparan como string: type=date postea Y-m-d
        // y MySQL DATE devuelve Y-m-d, así que la comparación es exacta.
        $cuotas_cambio = $cant_cuotas !== (int) $cr['cant_cuotas'];
        $fechas_cambio = $v['frecuencia'] !== $cr['frecuencia']
                      || $v['primer_vencimiento'] !== $cr['primer_vencimiento'];
        $plan_cambio   = $monto_cambio || $cuotas_cambio || $fechas_cambio;

        if (!$plan_cambio) {
            // Nada del plan cambió: conservar los montos EXACTAMENTE como
            // están guardados, sin recalcularlos — recalcular "corregiría" el
            // monto_cuota de un crédito refinanciado o heredado y lo dejaría
            // desalineado de ic_cuotas.
            $precio      = (float) $cr['precio_articulo'];
            $interes     = (float) $cr['interes_pct'];
            $monto_tot   = (float) $cr['monto_total'];
            $monto_cuota = (float) $cr['monto_cuota'];
        }

        try {
            $pdo->beginTransaction();

            // Snapshot descripción del artículo seleccionado
            $articulo_desc_snap = $articulo_desc_post;
            if (empty($articulo_desc_snap) && isset($articulos_map[$articulo_id_edit])) {
                $articulo_desc_snap = $articulos_map[$articulo_id_edit]['desc'];
            }
            $articulo_id_db = $articulo_id_edit > 0 ? $articulo_id_edit : null;

            $upd = $pdo->prepare("
                UPDATE ic_creditos SET
                    cliente_id=?, articulo_id=?, articulo_desc=?,
                    cobrador_id=?, vendedor_id=?,
                    precio_articulo=?, monto_total=?, monto_cuota=?, interes_pct=?, interes_moratorio_pct=?,
                    frecuencia=?, cant_cuotas=?, dia_cobro=?, observaciones=?, primer_vencimiento=?
                WHERE id=?
            ");
            $upd->execute([
                $v['cliente_id'],
                $articulo_id_db,
                $articulo_desc_snap,
                $v['cobrador_id'],
                ($v['vendedor_id'] ?: null),
                $precio,
                $monto_tot,
                $monto_cuota,
                $interes,
                (float)($v['interes_moratorio_pct'] ?? 5),
                $v['frecuencia'],
                $cant_cuotas,
                ($v['dia_cobro'] ?: null),
                trim($v['observaciones'] ?? ''),
                $v['primer_vencimiento'],
                $id,
            ]);

            if (!$plan_cambio) {
                // CASO 1 — no cambió nada del plan: ic_cuotas no se toca.
                // Ni montos, ni fechas, ni IDs, ni el estado del crédito.
            } elseif ($cuotas_cambio) {
                // CASO 2 — cambió la cantidad de cuotas: hay que rehacer el
                // cronograma. Solo llega acá un crédito sin cobros (la
                // validación de más arriba lo garantiza).
                // Primero eliminar dependencias FK antes de borrar cuotas
                $cuota_ids_stmt = $pdo->prepare("SELECT id FROM ic_cuotas WHERE credito_id=?");
                $cuota_ids_stmt->execute([$id]);
                $cuota_ids = $cuota_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($cuota_ids)) {
                    $ph = implode(',', array_fill(0, count($cuota_ids), '?'));
                    $pdo->prepare("DELETE FROM ic_pagos_confirmados WHERE cuota_id IN ($ph)")->execute($cuota_ids);
                    $pdo->prepare("DELETE FROM ic_pagos_temporales WHERE cuota_id IN ($ph)")->execute($cuota_ids);
                }
                $pdo->prepare("DELETE FROM ic_cuotas WHERE credito_id=?")->execute([$id]);
                generar_cuotas($id, [
                    'primer_vencimiento' => $v['primer_vencimiento'],
                    'cant_cuotas'        => $cant_cuotas,
                    'frecuencia'         => $v['frecuencia'],
                    'monto_cuota'        => $monto_cuota,
                ], $pdo);
                // FIX: cuotas regeneradas como PENDIENTE → crédito vuelve a EN_CURSO
                $pdo->prepare("UPDATE ic_creditos SET estado='EN_CURSO' WHERE id=? AND estado != 'CANCELADO'")
                    ->execute([$id]);
            } elseif ($fechas_cambio) {
                // CASO 3 — cambió la frecuencia o el primer vencimiento:
                // recorrer las cuotas recalculando la fecha. El importe solo se
                // reescribe si además cambió el monto.
                $cuotas_all = $pdo->prepare("SELECT id, estado, saldo_pagado FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
                $cuotas_all->execute([$id]);
                $todas_cuotas = $cuotas_all->fetchAll();

                $hoy_str       = (new DateTime('today'))->format('Y-m-d');
                $fecha_calc    = new DateTime($v['primer_vencimiento']);
                $upd_fecha     = $pdo->prepare("UPDATE ic_cuotas SET fecha_vencimiento=? WHERE id=?");
                $upd_fecha_est = $pdo->prepare("UPDATE ic_cuotas SET fecha_vencimiento=?, estado=? WHERE id=?");
                $upd_fecha_mon = $pdo->prepare("UPDATE ic_cuotas SET fecha_vencimiento=?, monto_cuota=?, estado=? WHERE id=?");
                foreach ($todas_cuotas as $idx => $c) {
                    if ($idx > 0) {
                        switch ($v['frecuencia']) {
                            case 'diario':
                                $fecha_calc->modify('+1 day');
                                while ((int)$fecha_calc->format('N') === 7) {
                                    $fecha_calc->modify('+1 day');
                                }
                                break;
                            case 'semanal':   $fecha_calc->modify('+7 days');  break;
                            case 'quincenal': $fecha_calc->modify('+15 days'); break;
                            case 'mensual':   $fecha_calc->modify('+1 month'); break;
                        }
                    }
                    $ya_cobrada = in_array($c['estado'], ['PAGADA', 'PARCIAL', 'CAP_PAGADA'], true)
                               || (float) $c['saldo_pagado'] > 0;
                    if ($ya_cobrada) {
                        // Solo ajusta fecha; no toca el monto ya cobrado
                        $upd_fecha->execute([$fecha_calc->format('Y-m-d'), $c['id']]);
                    } else {
                        $nueva_fecha = $fecha_calc->format('Y-m-d');
                        // FIX: si la nueva fecha es futura, una cuota VENCIDA vuelve a PENDIENTE
                        $nuevo_est = ($c['estado'] === 'VENCIDA' && $nueva_fecha > $hoy_str)
                            ? 'PENDIENTE' : $c['estado'];
                        if ($monto_cambio) {
                            $upd_fecha_mon->execute([$nueva_fecha, $monto_cuota, $nuevo_est, $c['id']]);
                        } else {
                            $upd_fecha_est->execute([$nueva_fecha, $nuevo_est, $c['id']]);
                        }
                    }
                }
            } else {
                // CASO 4 — solo cambió el monto: se actualiza el importe de las
                // cuotas sin cobrar. No se borra ni se recrea nada, así que los
                // IDs y las fechas (incluidos los ajustes manuales de
                // vencimiento) quedan intactos.
                $pdo->prepare("
                    UPDATE ic_cuotas SET monto_cuota = ?
                    WHERE credito_id = ?
                      AND estado NOT IN ('PAGADA','PARCIAL','CAP_PAGADA','CANCELADA')
                      AND saldo_pagado = 0
                ")->execute([$monto_cuota, $id]);
            }

            // Recalcular el estado del crédito tras tocar fechas/montos. En el
            // CASO 2 ya lo fijó la regeneración, y en el CASO 1 no se tocó nada.
            if ($plan_cambio && !$cuotas_cambio) {
                $cr_est = $pdo->prepare("SELECT estado FROM ic_creditos WHERE id=?");
                $cr_est->execute([$id]);
                if ($cr_est->fetchColumn() !== 'CANCELADO') {
                    $cr_cnt = $pdo->prepare("
                        SELECT SUM(CASE WHEN estado != 'PAGADA' THEN 1 ELSE 0 END) AS pendientes,
                               SUM(CASE WHEN estado = 'VENCIDA' THEN 1 ELSE 0 END) AS vencidas
                        FROM ic_cuotas WHERE credito_id=?
                    ");
                    $cr_cnt->execute([$id]);
                    $cnts = $cr_cnt->fetch(PDO::FETCH_ASSOC);
                    if ((int)$cnts['pendientes'] === 0)      $nuevo_cr = 'FINALIZADO';
                    elseif ((int)$cnts['vencidas'] > 0)      $nuevo_cr = 'MOROSO';
                    else                                     $nuevo_cr = 'EN_CURSO';
                    $pdo->prepare("UPDATE ic_creditos SET estado=? WHERE id=?")->execute([$nuevo_cr, $id]);
                }
            }

            $pdo->commit();
            registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_EDITADO', 'credito', $id);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito actualizado correctamente.'];
            header("Location: ver?id=$id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('creditos/editar error: ' . $e->getMessage());
            $error = 'Error al actualizar el crédito. Intente nuevamente.';
        }
    }
    // repopular — en un combo el artículo no se edita, no hay nada que repoblar
    if (!$es_combo) {
        $articulo_desc_actual = trim($v['articulo_desc'] ?? $articulo_desc_actual);
        $cr['articulo_id'] = $v['articulo_id'] ?? $cr['articulo_id'];
    }
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
            <a href="refinanciar?id=<?= $id ?>" class="fw-bold" style="color:var(--warning)">
                <i class="fa fa-sync-alt"></i> Refinanciar
            </a>.
        </div>
    <?php endif; ?>

    <?php if ($es_combo): ?>
        <div class="alert-ic alert-info">
            <i class="fa fa-layer-group"></i>
            <span>
                Crédito <strong>COMBO</strong> de <?= count($combo_items) ?> artículo<?= count($combo_items) === 1 ? '' : 's' ?>.
                El total se calcula como <strong>Monto por Cuota × Cantidad de Cuotas</strong>, no como la suma
                de los precios de lista (<?= formato_pesos($combo_total_items) ?>, solo de referencia).
                Los artículos no se editan desde acá.
            </span>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <?php csrf_input(); ?>

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

                <?php if ($es_combo): ?>

                <div class="form-group" style="grid-column:span 2">
                    <label>Artículos del Combo
                        <small class="text-muted">(no editables — se definen al dar de alta el crédito)</small>
                    </label>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.86rem">
                            <thead>
                                <tr style="color:var(--text-muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--dark-border)">
                                    <th style="padding:4px 6px 6px 0;text-align:left">Descripción</th>
                                    <th style="padding:4px 6px 6px;width:70px">Cant.</th>
                                    <th style="padding:4px 6px 6px;width:120px;text-align:right">Precio unit.</th>
                                    <th style="padding:4px 6px 6px;width:120px;text-align:right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($combo_items as $ci): ?>
                                <tr style="border-bottom:1px solid var(--dark-border)">
                                    <td style="padding:5px 6px 5px 0">
                                        <?= e($ci['descripcion']) ?>
                                        <?php if ($ci['articulo_id'] === null): ?>
                                            <span class="text-muted" style="font-size:.72rem">(libre)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:5px 6px;text-align:center"><?= (int) $ci['cantidad'] ?></td>
                                    <td style="padding:5px 6px;text-align:right"><?= formato_pesos($ci['precio_unitario']) ?></td>
                                    <td style="padding:5px 6px;text-align:right"><?= formato_pesos($ci['subtotal']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="border-top:1px solid var(--dark-border)">
                                    <td colspan="3" style="padding:7px 6px 2px;text-align:right;font-size:.77rem;color:var(--text-muted)">
                                        Total ítems (referencia):
                                    </td>
                                    <td style="padding:7px 6px 2px;text-align:right;font-weight:700;color:var(--primary-light)">
                                        <?= formato_pesos($combo_total_items) ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="form-group">
                    <label>Monto por Cuota $ <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="monto_cuota_combo" id="monto_cuota_combo"
                           value="<?= e($v['monto_cuota_combo'] ?? $v['monto_cuota']) ?>"
                           step="0.01" min="0.01" required oninput="calcularCuotasCombo()">
                    <small class="text-muted">Importe de cada cuota. El total = cuota × cantidad de cuotas.</small>
                    <?php if ($cuotas_con_pagos > 0): ?>
                        <small style="color:var(--warning);display:block">
                            <i class="fa fa-triangle-exclamation"></i>
                            Las <?= $cuotas_con_pagos ?> cuota<?= $cuotas_con_pagos === 1 ? '' : 's' ?> ya cobrada<?= $cuotas_con_pagos === 1 ? '' : 's' ?>
                            conserva<?= $cuotas_con_pagos === 1 ? '' : 'n' ?> su importe original, así que el total del crédito
                            va a quedar distinto de la suma del cronograma. Para reestructurarlo usá Refinanciar.
                        </small>
                    <?php endif; ?>
                </div>

                <?php else: ?>

                <div class="form-group" style="grid-column:span 2">
                    <label>Artículo del Catálogo *</label>
                    <input type="text" id="articulo_search_edit" list="articulos_list_edit"
                           value="<?php
                               $ai_edit = (int)($cr['articulo_id'] ?? 0);
                               if ($ai_edit && isset($articulos_map[$ai_edit])) {
                                   echo e($articulos_map[$ai_edit]['label']);
                               } elseif (!empty($articulo_desc_actual)) {
                                   echo e($articulo_desc_actual);
                               }
                           ?>"
                           placeholder="Buscar por nombre o SKU..."
                           autocomplete="off" style="width:100%">
                    <datalist id="articulos_list_edit">
                        <?php foreach ($articulos_raw as $art): ?>
                            <option value="<?= e($art['descripcion'] . ($art['sku'] ? ' [' . $art['sku'] . ']' : '')) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="articulo_id"   id="articulo_id_edit"
                           value="<?= (int)($cr['articulo_id'] ?? 0) ?>">
                    <input type="hidden" name="articulo_desc" id="articulo_desc_edit"
                           value="<?= e($articulo_desc_actual) ?>">
                    <small id="stock_info_edit" class="text-muted"></small>
                </div>

                <div class="form-group">
                    <label>Precio del Artículo $</label>
                    <input type="number" name="precio_articulo" id="precio_articulo"
                           value="<?= e($v['precio_articulo']) ?>"
                           step="0.01" min="0" required oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Interés Total %</label>
                    <input type="number" name="interes_pct" id="interes_pct"
                           value="<?= e($v['interes_pct']) ?>"
                           step="0.01" min="0" max="999" oninput="calcularCuotas()">
                </div>

                <?php endif; ?>

                <div class="form-group">
                    <label>Cantidad de Cuotas *</label>
                    <input type="number" name="cant_cuotas" id="cant_cuotas"
                           value="<?= e($v['cant_cuotas']) ?>"
                           min="<?= $cuotas_con_pagos > 0 ? (int) $v['cant_cuotas'] : 1 ?>"
                           max="<?= $cuotas_con_pagos > 0 ? (int) $v['cant_cuotas'] : 520 ?>"
                           <?= $cuotas_con_pagos > 0 ? 'readonly' : '' ?>
                           required oninput="recalcularLabels()">
                    <?php if ($cuotas_con_pagos > 0): ?>
                        <small class="text-muted">No se puede cambiar: el crédito ya tiene cobros registrados. Para reestructurarlo usá Refinanciar.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Frecuencia *</label>
                    <select name="frecuencia" id="frecuencia" required onchange="recalcularLabels()">
                        <?php foreach (['diario' => 'Diario', 'semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= ($v['frecuencia'] === $k) ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Primer Vencimiento *</label>
                    <input type="date" name="primer_vencimiento"
                           value="<?= e($v['primer_vencimiento']) ?>" required>
                    <?php if ($cuotas_pagadas > 0): ?>
                        <small class="text-muted">Cambiar esta fecha reajusta las fechas de todas las cuotas (incluidas las ya pagadas).</small>
                    <?php endif; ?>
                    <?php if ($ajustes_fecha > 0): ?>
                        <small style="color:var(--warning);display:block">
                            <i class="fa fa-triangle-exclamation"></i>
                            Este crédito tiene <?= $ajustes_fecha ?> ajuste<?= $ajustes_fecha === 1 ? '' : 's' ?> manual<?= $ajustes_fecha === 1 ? '' : 'es' ?> de vencimiento
                            <?php if ($primera_cuota_fecha): ?>
                                (la cuota más próxima vence el <?= date('d/m/Y', strtotime($primera_cuota_fecha)) ?>)<?php endif; ?>.
                            Si cambiás esta fecha se recalculan TODAS las fechas y esos ajustes se pierden.
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Interés Moratorio % semanal</label>
                    <input type="number" name="interes_moratorio_pct"
                           value="<?= e($v['interes_moratorio_pct']) ?>" step="0.01">
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

                <div class="form-group">
                    <label>Día de Cobro</label>
                    <select name="dia_cobro">
                        <option value="">— Cualquier día —</option>
                        <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'] as $n => $d): ?>
                            <option value="<?= $n ?>" <?= ($v['dia_cobro'] == $n) ? 'selected' : '' ?>>
                                <?= $d ?>
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
            <a href="ver?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<script>
const articulosMapEdit  = <?= json_encode($articulos_map,        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const artSearchMapEdit  = <?= json_encode($articulos_search_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

const esCombo = <?= $es_combo ? 'true' : 'false' ?>;

// El buscador de artículo no se renderiza en modo combo.
const artInputEdit = document.getElementById('articulo_search_edit');
if (artInputEdit) artInputEdit.addEventListener('change', function() {
    const val  = this.value.trim();
    const item = artSearchMapEdit[val];
    if (item) {
        document.getElementById('articulo_id_edit').value   = item.id;
        document.getElementById('articulo_desc_edit').value = item.desc;
        const info = articulosMapEdit[item.id];
        if (info) {
            document.getElementById('precio_articulo').value = info.precio.toFixed(2);
            document.getElementById('stock_info_edit').textContent = 'Stock disponible: ' + info.stock;
            calcularCuotas();
        }
    } else if (val === '') {
        document.getElementById('articulo_id_edit').value   = '';
        document.getElementById('articulo_desc_edit').value = '';
        document.getElementById('stock_info_edit').textContent = '';
    } else {
        // Texto escrito pero sin match exacto: guardar descripción, sin ID de catálogo
        document.getElementById('articulo_id_edit').value   = '0';
        document.getElementById('articulo_desc_edit').value = val;
        document.getElementById('stock_info_edit').textContent = '';
    }
});

function fmt(n) {
    return '$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function calcularCuotas() {
    if (esCombo) return;   // en combo el total no sale del precio de lista
    const precio  = parseFloat(document.getElementById('precio_articulo').value) || 0;
    const interes = parseFloat(document.getElementById('interes_pct').value) || 0;
    const cuotas  = parseInt(document.getElementById('cant_cuotas').value) || 1;
    const total   = precio * (1 + interes / 100);
    document.getElementById('lbl_monto_total').textContent = fmt(total);
    document.getElementById('lbl_monto_cuota').textContent = fmt(total / cuotas);
}

function calcularCuotasCombo() {
    if (!esCombo) return;
    const cuota = parseFloat(document.getElementById('monto_cuota_combo').value) || 0;
    const cant  = parseInt(document.getElementById('cant_cuotas').value) || 1;
    document.getElementById('lbl_monto_total').textContent = fmt(cuota * cant);
    document.getElementById('lbl_monto_cuota').textContent = fmt(cuota);
}

function recalcularLabels() {
    esCombo ? calcularCuotasCombo() : calcularCuotas();
}

// Hasta ahora los labels no se recalculaban al cargar: mostraban los valores
// guardados mientras los inputs codificaban otro número, que es lo que hacía
// invisible el problema en los combos. Para un crédito simple es un no-op.
document.addEventListener('DOMContentLoaded', recalcularLabels);
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
