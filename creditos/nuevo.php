<?php
// ============================================================
// creditos/nuevo.php — Alta de crédito (simple o combo)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$cliente_id = (int) ($_GET['cliente_id'] ?? 0);

$clientes = $pdo->query("
    SELECT c.id, c.nombres, c.apellidos, c.cobrador_id, c.zona, c.puntaje_pago,
           CONCAT(u.nombre, ' ', u.apellido) AS cobrador_nombre,
           (SELECT COUNT(*) FROM ic_creditos cr
            WHERE cr.cliente_id = c.id AND cr.estado IN ('EN_CURSO','MOROSO')) AS creditos_activos
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id AND u.activo = 1
    WHERE c.estado='ACTIVO'
    ORDER BY c.apellidos, c.nombres
")->fetchAll();

$vendedores = $pdo->query("SELECT id,nombre,apellido FROM ic_vendedores WHERE activo=1 ORDER BY nombre")->fetchAll();

$articulos_raw = $pdo->query("
    SELECT id, descripcion, precio_venta, sku, stock
    FROM ic_articulos WHERE activo=1 ORDER BY descripcion
")->fetchAll();

$articulos_map = [];
$articulos_search_map = [];
foreach ($articulos_raw as $art) {
    $label = $art['descripcion'] . ($art['sku'] ? ' [' . $art['sku'] . ']' : '');
    $articulos_map[(int)$art['id']] = [
        'precio' => (float)$art['precio_venta'],
        'desc'   => $art['descripcion'],
        'stock'  => (int)$art['stock'],
        'label'  => $label,
    ];
    $articulos_search_map[$label] = ['id' => (int)$art['id'], 'desc' => $art['descripcion']];
}

$clientes_cob_map = [];
foreach ($clientes as $cl) {
    $clientes_cob_map[(int)$cl['id']] = [
        'cob_id'          => $cl['cobrador_id'] ? (int)$cl['cobrador_id'] : null,
        'cob_nombre'      => $cl['cobrador_nombre'] ?? null,
        'zona'            => $cl['zona'] ?? '',
        'puntaje'         => $cl['puntaje_pago'] ? (int)$cl['puntaje_pago'] : null,
        'creditos_activos'=> (int)($cl['creditos_activos'] ?? 0),
    ];
}

$error = '';
$modo_ini = 'simple';
$combo_items_post = [];
$v = [
    'cliente_id'            => $cliente_id,
    'frecuencia'            => 'semanal',
    'cant_cuotas'           => 12,
    'interes_pct'           => 0,
    'interes_moratorio_pct' => 5,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $v      = $_POST;
    $modo   = trim($_POST['modo'] ?? 'simple');
    $modo_ini = $modo;

    // ── COMBO ────────────────────────────────────────────────
    if ($modo === 'combo') {

        $raw_art_ids = array_map('intval',   $_POST['items_art_id'] ?? []);
        $raw_descs   = array_map('trim',     $_POST['items_desc']   ?? []);
        $raw_precios = array_map('floatval', $_POST['items_precio'] ?? []);
        $raw_cants   = array_map('intval',   $_POST['items_cant']   ?? []);

        foreach ($raw_art_ids as $i => $aid) {
            $combo_items_post[] = [
                'art_id' => $aid,
                'desc'   => $raw_descs[$i] ?? '',
                'precio' => $raw_precios[$i] ?? 0,
                'cant'   => $raw_cants[$i] ?? 1,
            ];
        }

        if (
            empty($v['cliente_id']) ||
            empty($v['cobrador_id']) ||
            empty($v['cant_cuotas']) || empty($v['primer_vencimiento'])
        ) {
            $error = 'Completá todos los campos obligatorios.';
        } elseif (empty($raw_art_ids)) {
            $error = 'Agregá al menos un artículo al combo.';
        } else {
            $items_validos = [];
            foreach ($raw_art_ids as $i => $aid) {
                $desc   = $raw_descs[$i] ?? '';
                $precio = $raw_precios[$i] ?? 0;
                $cant   = max(1, $raw_cants[$i] ?? 1);
                if ($desc === '' || $precio <= 0) {
                    $error = 'Todos los ítems deben tener descripción y precio mayor a cero.';
                    break;
                }
                $items_validos[] = [
                    'art_id'  => $aid,
                    'desc'    => $desc,
                    'precio'  => $precio,
                    'cant'    => $cant,
                    'subtotal'=> $precio * $cant,
                ];
            }

            if (!$error) {
                $monto_cuot  = (float)($v['monto_cuota'] ?? 0);
                $cant_cuotas = (int)($v['cant_cuotas'] ?? 0);
                if ($monto_cuot <= 0 || $cant_cuotas < 1) {
                    $error = 'El monto de cuota y la cantidad de cuotas deben ser mayores a cero.';
                }
            }

            if (!$error) {
                $monto_cuot  = (float)$v['monto_cuota'];
                $cant_cuotas = (int)$v['cant_cuotas'];
                $monto_tot   = $monto_cuot * $cant_cuotas;
                $precio_sum  = array_sum(array_column($items_validos, 'subtotal'));
                $desc_combo  = 'Combo: ' . implode(' + ', array_column($items_validos, 'desc'));

                try {
                    $pdo->beginTransaction();
                    $stock_ok = true;

                    foreach ($items_validos as &$item) {
                        if ($item['art_id'] > 0) {
                            $as = $pdo->prepare("SELECT stock FROM ic_articulos WHERE id=? FOR UPDATE");
                            $as->execute([$item['art_id']]);
                            $ar = $as->fetch();
                            if (!$ar || $ar['stock'] < $item['cant']) {
                                $pdo->rollBack();
                                $error = 'Sin stock para "' . $item['desc'] . '" (disponible: ' . ($ar['stock'] ?? 0) . ', requerido: ' . $item['cant'] . ').';
                                $stock_ok = false;
                                break;
                            }
                        }
                    }
                    unset($item);

                    if ($stock_ok) {
                        $ins = $pdo->prepare("
                            INSERT INTO ic_creditos
                              (cliente_id, articulo_id, articulo_desc, cobrador_id, vendedor_id,
                               fecha_alta, precio_articulo, monto_total,
                               interes_pct, interes_moratorio_pct, frecuencia, cant_cuotas, monto_cuota,
                               dia_cobro, primer_vencimiento, estado, observaciones, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");
                        $ins->execute([
                            $v['cliente_id'],
                            null,
                            $desc_combo,
                            $v['cobrador_id'],
                            ($v['vendedor_id'] ?: null),
                            date('Y-m-d'),
                            $precio_sum,
                            $monto_tot,
                            0,
                            (float)($v['interes_moratorio_pct'] ?? 5),
                            $v['frecuencia'],
                            $cant_cuotas,
                            $monto_cuot,
                            ($v['dia_cobro'] ?: null),
                            $v['primer_vencimiento'],
                            'EN_CURSO',
                            trim($v['observaciones'] ?? ''),
                            $_SESSION['user_id'],
                        ]);
                        $credito_id = (int)$pdo->lastInsertId();

                        $ins_det = $pdo->prepare("
                            INSERT INTO ic_credito_articulos
                              (credito_id, articulo_id, descripcion, cantidad, precio_unitario, subtotal)
                            VALUES (?,?,?,?,?,?)
                        ");
                        foreach ($items_validos as $item) {
                            $ins_det->execute([
                                $credito_id,
                                $item['art_id'] ?: null,
                                $item['desc'],
                                $item['cant'],
                                $item['precio'],
                                $item['subtotal'],
                            ]);
                        }

                        foreach ($items_validos as $item) {
                            if ($item['art_id'] > 0) {
                                $pdo->prepare("UPDATE ic_articulos SET stock = stock - ? WHERE id=?")
                                    ->execute([$item['cant'], $item['art_id']]);
                            }
                        }

                        generar_cuotas($credito_id, [
                            'primer_vencimiento' => $v['primer_vencimiento'],
                            'cant_cuotas'        => $cant_cuotas,
                            'frecuencia'         => $v['frecuencia'],
                            'monto_cuota'        => $monto_cuot,
                        ], $pdo);

                        $pdo->prepare("
                            UPDATE ic_clientes
                            SET cobrador_id = ?,
                                dia_cobro   = CASE WHEN ? IS NOT NULL THEN ? ELSE dia_cobro END
                            WHERE id = ?
                        ")->execute([
                            $v['cobrador_id'],
                            ($v['dia_cobro'] ?: null),
                            ($v['dia_cobro'] ?: null),
                            $v['cliente_id'],
                        ]);

                        $pdo->commit();
                        registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_CREADO', 'credito', $credito_id,
                            'Combo: ' . count($items_validos) . ' ítems | Cuotas: ' . $cant_cuotas . ' | Total: ' . formato_pesos($monto_tot));
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito combo registrado y cuotas generadas.'];
                        header("Location: ver?id=$credito_id");
                        exit;
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('creditos/nuevo combo error: ' . $e->getMessage());
                    $error = 'Error al guardar el crédito. Intente nuevamente.';
                }
            }
        }

    // ── SIMPLE ───────────────────────────────────────────────
    } else {
        $articulo_id         = (int)($v['articulo_id'] ?? 0);
        $articulo_desc_input = trim($v['articulo_desc'] ?? '');

        if (
            empty($v['cliente_id']) ||
            empty($v['cobrador_id']) ||
            empty($v['cant_cuotas']) || empty($v['primer_vencimiento']) ||
            ($articulo_id === 0 && $articulo_desc_input === '')
        ) {
            $error = 'Completá todos los campos obligatorios (incluido el artículo).';
        } else {
            $precio     = (float) $v['precio_articulo'];
            $monto_tot  = (float) $v['monto_total'];
            $monto_cuot = (float) $v['monto_cuota'];

            if ($precio <= 0 || $monto_tot <= 0 || $monto_cuot <= 0) {
                $error = 'Los montos deben ser mayores a cero.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $articulo_desc_snap = $articulo_desc_input;
                    $stock_ok = true;

                    if ($articulo_id > 0) {
                        $art_stmt = $pdo->prepare("SELECT stock, descripcion FROM ic_articulos WHERE id=? FOR UPDATE");
                        $art_stmt->execute([$articulo_id]);
                        $art_row = $art_stmt->fetch();

                        if (!$art_row || $art_row['stock'] < 1) {
                            $pdo->rollBack();
                            $error = 'Sin stock disponible para el artículo seleccionado (stock: ' . ($art_row['stock'] ?? 0) . ').';
                            $stock_ok = false;
                        } else {
                            $articulo_desc_snap = $articulo_desc_input ?: $art_row['descripcion'];
                        }
                    }

                    if ($stock_ok) {
                        $ins = $pdo->prepare("
                            INSERT INTO ic_creditos
                              (cliente_id, articulo_id, articulo_desc, cobrador_id, vendedor_id,
                               fecha_alta, precio_articulo, monto_total,
                               interes_pct, interes_moratorio_pct, frecuencia, cant_cuotas, monto_cuota,
                               dia_cobro, primer_vencimiento, estado, observaciones, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");
                        $ins->execute([
                            $v['cliente_id'],
                            $articulo_id ?: null,
                            $articulo_desc_snap,
                            $v['cobrador_id'],
                            ($v['vendedor_id'] ?: null),
                            date('Y-m-d'),
                            $precio,
                            $monto_tot,
                            (float) ($v['interes_pct'] ?? 0),
                            (float) ($v['interes_moratorio_pct'] ?? 5),
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

                        generar_cuotas($credito_id, [
                            'primer_vencimiento' => $v['primer_vencimiento'],
                            'cant_cuotas'        => (int) $v['cant_cuotas'],
                            'frecuencia'         => $v['frecuencia'],
                            'monto_cuota'        => $monto_cuot,
                        ], $pdo);

                        if ($articulo_id > 0) {
                            $pdo->prepare("UPDATE ic_articulos SET stock = stock - 1 WHERE id=?")
                                ->execute([$articulo_id]);
                        }

                        $pdo->prepare("
                            UPDATE ic_clientes
                            SET cobrador_id = ?,
                                dia_cobro   = CASE WHEN ? IS NOT NULL THEN ? ELSE dia_cobro END
                            WHERE id = ?
                        ")->execute([
                            $v['cobrador_id'],
                            ($v['dia_cobro'] ?: null),
                            ($v['dia_cobro'] ?: null),
                            $v['cliente_id'],
                        ]);

                        $pdo->commit();
                        registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_CREADO', 'credito', (int)$credito_id,
                            'Cuotas: ' . $v['cant_cuotas'] . ' | Total: ' . formato_pesos($monto_tot));
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito registrado y cuotas generadas.'];
                        header("Location: ver?id=$credito_id");
                        exit;
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('creditos/nuevo error: ' . $e->getMessage());
                    $error = 'Error al guardar el crédito. Intente nuevamente.';
                }
            }
        }
    }
}

$page_title   = 'Nuevo Crédito';
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:880px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <?php csrf_input(); ?>
        <input type="hidden" name="modo" id="modo" value="<?= e($modo_ini) ?>">

        <!-- ── Toggle simple / combo ─────────────────────────── -->
        <div style="margin-bottom:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="font-size:.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px">Tipo de crédito:</span>
            <div style="display:flex;border-radius:8px;overflow:hidden;border:1px solid var(--dark-border)">
                <button type="button" id="btn-simple" onclick="setModo('simple')"
                        style="padding:7px 18px;border:none;cursor:pointer;font-size:.88rem;display:flex;align-items:center;gap:6px;transition:background .15s,color .15s">
                    <i class="fa fa-box"></i> Artículo simple
                </button>
                <button type="button" id="btn-combo" onclick="setModo('combo')"
                        style="padding:7px 18px;border:none;cursor:pointer;font-size:.88rem;display:flex;align-items:center;gap:6px;transition:background .15s,color .15s">
                    <i class="fa fa-layer-group"></i> Combo de artículos
                </button>
            </div>
        </div>

        <!-- ── Datos del crédito ──────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Datos del Crédito</span>
            </div>
            <div class="form-grid">

                <div class="form-group" style="grid-column:1 / -1">
                    <label>Cliente *</label>
                    <select name="cliente_id" id="cliente_id_sel" required>
                        <option value="">— Seleccionar cliente —</option>
                        <?php foreach ($clientes as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= ($v['cliente_id'] == $cl['id']) ? 'selected' : '' ?>>
                                <?= e($cl['apellidos'] . ', ' . $cl['nombres']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="cliente-info" style="margin-top:6px;min-height:20px"></div>
                </div>

                <!-- ── Artículo simple ───────────────────────── -->
                <div id="seccion-art-simple" style="grid-column:1 / -1">
                    <div class="form-group">
                        <label>Artículo * <small class="text-muted">(seleccioná del catálogo o escribí manualmente)</small></label>
                        <input type="text" id="articulo_search" list="articulos_list"
                               value="<?php
                                   if (!empty($v['articulo_id'])) {
                                       $ai = (int)$v['articulo_id'];
                                       echo e($articulos_map[$ai]['label'] ?? $v['articulo_desc'] ?? '');
                                   } elseif (!empty($v['articulo_desc'])) {
                                       echo e($v['articulo_desc']);
                                   }
                               ?>"
                               placeholder="Buscar en catálogo o escribir descripción..."
                               autocomplete="off" style="width:100%">
                        <datalist id="articulos_list">
                            <?php foreach ($articulos_raw as $art): ?>
                                <option value="<?= e($art['descripcion'] . ($art['sku'] ? ' [' . $art['sku'] . ']' : '')) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" name="articulo_id"   id="articulo_id"
                               value="<?= (int)($v['articulo_id'] ?? 0) ?>">
                        <input type="hidden" name="articulo_desc" id="articulo_desc"
                               value="<?= e($v['articulo_desc'] ?? '') ?>">
                        <small id="stock_info" class="text-muted">
                            <?php
                            if (!empty($v['articulo_id'])) {
                                $ai = (int)$v['articulo_id'];
                                if (isset($articulos_map[$ai])) echo 'Stock disponible: ' . $articulos_map[$ai]['stock'];
                            }
                            ?>
                        </small>
                    </div>
                </div>

                <!-- ── Artículos combo ───────────────────────── -->
                <div id="seccion-art-combo" style="grid-column:1 / -1; display:none">
                    <div style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between">
                        <label style="margin-bottom:0">Artículos del Combo</label>
                        <button type="button" onclick="addComboRow()"
                                class="btn-ic btn-primary" style="padding:4px 14px;font-size:.82rem">
                            <i class="fa fa-plus"></i> Agregar ítem
                        </button>
                    </div>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.86rem">
                            <thead>
                                <tr style="color:var(--text-muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--dark-border)">
                                    <th style="padding:4px 6px 6px 0;width:105px">Tipo</th>
                                    <th style="padding:4px 6px 6px">Descripción</th>
                                    <th style="padding:4px 6px 6px;width:115px">Precio unit. $</th>
                                    <th style="padding:4px 6px 6px;width:70px">Cant.</th>
                                    <th style="padding:4px 6px 6px;width:110px;text-align:right">Subtotal</th>
                                    <th style="width:32px"></th>
                                </tr>
                            </thead>
                            <tbody id="combo-tbody"></tbody>
                            <tfoot>
                                <tr style="border-top:1px solid var(--dark-border)">
                                    <td colspan="4" style="padding:7px 6px 2px;text-align:right;font-size:.77rem;color:var(--text-muted)">
                                        Total ítems (referencia):
                                    </td>
                                    <td style="padding:7px 6px 2px;text-align:right;font-weight:700;color:var(--primary-light)" id="combo-total-items">
                                        $ 0,00
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- ── Precio + interés (solo simple) ───────── -->
                <div id="seccion-precio-simple" class="form-group">
                    <label>Precio del Artículo $</label>
                    <input type="number" name="precio_articulo" id="precio_articulo"
                           value="<?= $v['precio_articulo'] ?? '' ?>"
                           step="0.01" min="0" oninput="calcularCuotas()"
                           placeholder="0.00">
                    <small class="text-muted">Pre-llenado desde el catálogo, editable.</small>
                </div>

                <div id="seccion-interes-simple" class="form-group">
                    <label>Interés Total % (sobre el precio)</label>
                    <input type="number" name="interes_pct" id="interes_pct"
                           value="<?= $v['interes_pct'] ?? 0 ?>"
                           step="0.01" min="0" max="999" oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Interés Moratorio % semanal</label>
                    <input type="number" name="interes_moratorio_pct"
                           value="<?= $v['interes_moratorio_pct'] ?? 5 ?>"
                           step="0.01" min="0">
                </div>

                <div class="form-group">
                    <label>Cobrador</label>
                    <div id="cobrador_display" style="padding:9px 13px;background:rgba(0,0,0,.25);border:1px solid var(--dark-border);border-radius:8px;font-size:.92rem;min-height:38px">
                        <?php
                        $cid_post = (int)($v['cobrador_id'] ?? 0);
                        if ($cid_post) {
                            foreach ($clientes_cob_map as $map) {
                                if ($map['cob_id'] === $cid_post) {
                                    echo '<span style="color:var(--primary-light)">' . e($map['cob_nombre']) . '</span>';
                                    break;
                                }
                            }
                        } else {
                            echo '<span style="color:var(--text-muted)">— Seleccioná un cliente —</span>';
                        }
                        ?>
                    </div>
                    <input type="hidden" name="cobrador_id" id="cobrador_id"
                           value="<?= e($v['cobrador_id'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Zona</label>
                    <div id="zona_display" style="padding:9px 13px;background:rgba(0,0,0,.25);border:1px solid var(--dark-border);border-radius:8px;font-size:.92rem;min-height:38px">
                        <?php
                        $cli_post = (int)($v['cliente_id'] ?? 0);
                        if ($cli_post && isset($clientes_cob_map[$cli_post]['zona']) && $clientes_cob_map[$cli_post]['zona'] !== '') {
                            echo '<span style="color:var(--primary-light)">' . e($clientes_cob_map[$cli_post]['zona']) . '</span>';
                        } else {
                            echo '<span style="color:var(--text-muted)">— Seleccioná un cliente —</span>';
                        }
                        ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Vendedor</label>
                    <select name="vendedor_id">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($vendedores as $ven): ?>
                            <option value="<?= $ven['id'] ?>" <?= ($v['vendedor_id'] ?? '') == $ven['id'] ? 'selected' : '' ?>>
                                <?= e($ven['nombre'] . ' ' . $ven['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
        </div>

        <!-- ── Plan de Pago ───────────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-calendar-alt"></i> Plan de Pago</span>
            </div>
            <div class="form-grid">

                <div class="form-group">
                    <label>Frecuencia *</label>
                    <select name="frecuencia" id="frecuencia" required
                            onchange="toggleDiaCobro();calcularCuotas();updateComboTotal();previsualizarFechas()">
                        <?php foreach (['diario' => 'Diario', 'semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $lbl): ?>
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
                    <input type="number" name="cant_cuotas" id="cant_cuotas"
                           value="<?= $v['cant_cuotas'] ?? 12 ?>"
                           min="1" max="520" required
                           oninput="calcularCuotas();updateComboTotal();previsualizarFechas()">
                </div>

                <div class="form-group">
                    <label>Primer Vencimiento *</label>
                    <input type="date" name="primer_vencimiento" id="primer_vencimiento"
                           value="<?= $v['primer_vencimiento'] ?? '' ?>" required
                           onchange="previsualizarFechas()">
                    <small id="preview_fechas" style="color:var(--text-muted);font-size:.76rem"></small>
                </div>

                <!-- Monto de cuota editable (solo combo) -->
                <div class="form-group" id="combo-cuota-group" style="display:none;grid-column:span 2">
                    <label>Monto por Cuota $ <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="monto_cuota_combo_input"
                           step="100" min="0"
                           value="<?= ($modo_ini === 'combo' && !empty($v['monto_cuota'])) ? e($v['monto_cuota']) : '' ?>"
                           oninput="updateComboTotal()"
                           placeholder="0,00" style="max-width:220px">
                    <small class="text-muted">Ingresá el importe de cada cuota directamente.</small>
                </div>

            </div>

            <!-- Calculador visual -->
            <div style="margin-top:20px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:18px">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">
                        Monto Total del Crédito
                    </div>
                    <div id="monto_total_display"
                         style="font-size:1.7rem;font-weight:800;color:var(--primary-light);margin-top:6px">
                        $ 0,00
                    </div>
                    <input type="hidden" name="monto_total" id="monto_total" value="<?= $v['monto_total'] ?? 0 ?>">
                </div>
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:18px">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">
                        Monto por Cuota
                    </div>
                    <div id="monto_cuota_display"
                         style="font-size:1.7rem;font-weight:800;color:var(--success);margin-top:6px">
                        $ 0,00
                    </div>
                    <input type="hidden" name="monto_cuota" id="monto_cuota" value="<?= $v['monto_cuota'] ?? 0 ?>">
                </div>
            </div>
        </div>

        <!-- ── Observaciones ──────────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-comment"></i> Observaciones</span>
            </div>
            <textarea name="observaciones" rows="3"
                      placeholder="Notas adicionales sobre el crédito..."><?= e($v['observaciones'] ?? '') ?></textarea>
        </div>

        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary">
                <i class="fa fa-save"></i> Crear Crédito
            </button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$clientes_cob_json    = json_encode($clientes_cob_map,    JSON_UNESCAPED_UNICODE);
$articulos_map_json   = json_encode($articulos_map,        JSON_UNESCAPED_UNICODE);
$art_search_json      = json_encode($articulos_search_map, JSON_UNESCAPED_UNICODE);
$combo_items_ini_json = json_encode($combo_items_post,     JSON_UNESCAPED_UNICODE);

$page_scripts = <<<JS
<script>
const clientesCob  = $clientes_cob_json;
const articulosMap = $articulos_map_json;
const artSearchMap = $art_search_json;

// ── Artículo selector (modo simple) ─────────────────────
document.getElementById('articulo_search').addEventListener('change', function() {
    const val  = this.value.trim();
    const item = artSearchMap[val];
    if (item) {
        document.getElementById('articulo_id').value   = item.id;
        document.getElementById('articulo_desc').value = item.desc;
        const info = articulosMap[item.id];
        if (info) {
            document.getElementById('precio_articulo').value = info.precio.toFixed(2);
            document.getElementById('stock_info').textContent = 'Stock disponible: ' + info.stock;
            calcularCuotas();
        }
    } else if (val === '') {
        document.getElementById('articulo_id').value   = '0';
        document.getElementById('articulo_desc').value = '';
        document.getElementById('stock_info').textContent = '';
    } else {
        document.getElementById('articulo_id').value   = '0';
        document.getElementById('articulo_desc').value = val;
        document.getElementById('stock_info').textContent = 'Artículo manual (sin descuento de stock)';
    }
});

// ── Cobrador / Zona / Puntaje ────────────────────────────
const puntajeMap = {
    1: { label: '⭐⭐⭐ Excelente', color: 'var(--success)' },
    2: { label: '⭐⭐ Bueno',           color: 'var(--primary)' },
    3: { label: '⭐ Regular',               color: 'var(--warning)' },
    4: { label: '✗ Sin mora',              color: 'var(--danger)'  },
};

function actualizarCobrador() {
    const cid   = parseInt(document.getElementById('cliente_id_sel').value) || 0;
    const info  = clientesCob[cid];
    const disp  = document.getElementById('cobrador_display');
    const hid   = document.getElementById('cobrador_id');
    const zdis  = document.getElementById('zona_display');
    const clinf = document.getElementById('cliente-info');

    if (cid && info && info.cob_id) {
        disp.innerHTML = '<span style="color:var(--primary-light)">' + info.cob_nombre + '</span>';
        hid.value = info.cob_id;
    } else if (cid && info && !info.cob_id) {
        disp.innerHTML = '<span style="color:var(--danger)"><i class="fa fa-exclamation-triangle"></i> Sin cobrador asignado</span>';
        hid.value = '';
    } else {
        disp.innerHTML = '<span style="color:var(--text-muted)">— Selección pendiente —</span>';
        hid.value = '';
    }

    if (cid && info && info.zona) {
        zdis.innerHTML = '<span style="color:var(--primary-light)">' + info.zona + '</span>';
    } else {
        zdis.innerHTML = '<span style="color:var(--text-muted)">— Selección pendiente —</span>';
    }

    if (cid && info) {
        let html = '';
        if (info.puntaje && puntajeMap[info.puntaje]) {
            const p = puntajeMap[info.puntaje];
            html += '<span style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;padding:2px 8px;border-radius:4px;background:rgba(255,255,255,.07);border:1px solid ' + p.color + ';color:' + p.color + '">' + p.label + '</span> ';
        }
        if (info.creditos_activos > 0) {
            const s = info.creditos_activos > 1 ? 's' : '';
            html += '<span style="font-size:.78rem;color:var(--warning)"><i class="fa fa-exclamation-triangle"></i> Este cliente tiene <strong>' + info.creditos_activos + ' crédito' + s + ' activo' + s + '</strong>. Verificar antes de continuar.</span>';
        }
        clinf.innerHTML = html;
    } else {
        clinf.innerHTML = '';
    }
}

document.getElementById('cliente_id_sel').addEventListener('change', actualizarCobrador);

// ── Formato moneda ───────────────────────────────────────
function fmt(n) {
    return '\$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ── Calculador simple ────────────────────────────────────
function calcularCuotas() {
    if (document.getElementById('modo').value === 'combo') return;
    const precio  = parseFloat(document.getElementById('precio_articulo').value) || 0;
    const interes = parseFloat(document.getElementById('interes_pct').value) || 0;
    const cuotas  = parseInt(document.getElementById('cant_cuotas').value) || 1;
    const total   = precio * (1 + interes / 100);
    const cuota   = cuotas > 0 ? total / cuotas : 0;
    document.getElementById('monto_total_display').textContent = fmt(total);
    document.getElementById('monto_cuota_display').textContent = fmt(cuota);
    document.getElementById('monto_total').value = total.toFixed(2);
    document.getElementById('monto_cuota').value = cuota.toFixed(2);
}

// ── Calculador combo ─────────────────────────────────────
function updateComboTotal() {
    if (document.getElementById('modo').value !== 'combo') return;
    const cuota = parseFloat(document.getElementById('monto_cuota_combo_input').value) || 0;
    const cant  = parseInt(document.getElementById('cant_cuotas').value) || 1;
    const total = cuota * cant;
    document.getElementById('monto_total_display').textContent = fmt(total);
    document.getElementById('monto_cuota_display').textContent = fmt(cuota);
    document.getElementById('monto_total').value = total.toFixed(2);
    document.getElementById('monto_cuota').value = cuota.toFixed(2);
}

// ── Toggle modo ──────────────────────────────────────────
function setModo(modo) {
    document.getElementById('modo').value = modo;
    const isCombo = modo === 'combo';

    document.getElementById('seccion-art-simple').style.display    = isCombo ? 'none' : '';
    document.getElementById('seccion-art-combo').style.display     = isCombo ? ''     : 'none';
    document.getElementById('seccion-precio-simple').style.display  = isCombo ? 'none' : '';
    document.getElementById('seccion-interes-simple').style.display = isCombo ? 'none' : '';
    document.getElementById('combo-cuota-group').style.display      = isCombo ? ''     : 'none';

    const artSearch = document.getElementById('articulo_search');
    const precioArt = document.getElementById('precio_articulo');
    artSearch.required = !isCombo;
    precioArt.required = !isCombo;

    const bS = document.getElementById('btn-simple');
    const bC = document.getElementById('btn-combo');
    if (isCombo) {
        bC.style.cssText += 'background:var(--primary);color:#fff;';
        bS.style.cssText += 'background:none;color:var(--text-muted);';
    } else {
        bS.style.cssText += 'background:var(--primary);color:#fff;';
        bC.style.cssText += 'background:none;color:var(--text-muted);';
    }

    if (isCombo) { recalcCombo(); updateComboTotal(); }
    else         { calcularCuotas(); }
}

// ── Combo: gestión de filas ──────────────────────────────
let comboRowId = 0;

function addComboRow(preData) {
    comboRowId++;
    const n    = comboRowId;
    const d    = preData || {};
    const artId = d.art_id || 0;
    const desc  = (d.desc  || '').replace(/"/g, '&quot;');
    const prec  = d.precio || '';
    const cant  = d.cant   || 1;
    const tipo  = artId > 0 ? 'catalogo' : (d.desc ? 'libre' : 'catalogo');
    const catDsp  = tipo === 'catalogo' ? '' : 'none';
    const libreDsp= tipo === 'libre'    ? '' : 'none';

    let artLabel = '';
    if (artId > 0 && articulosMap[artId]) artLabel = articulosMap[artId].label.replace(/"/g,'&quot;');

    const tr = document.createElement('tr');
    tr.id = 'crow-' + n;
    tr.style.borderBottom = '1px solid var(--dark-border)';
    tr.innerHTML =
        '<td style="padding:5px 6px 5px 0">' +
            '<select id="tipo-' + n + '" onchange="toggleComboTipo(' + n + ')" style="font-size:.82rem;padding:4px 6px;width:100px">' +
                '<option value="catalogo"' + (tipo==='catalogo'?' selected':'') + '>Catálogo</option>' +
                '<option value="libre"'    + (tipo==='libre'   ?' selected':'') + '>Libre</option>' +
            '</select>' +
        '</td>' +
        '<td style="padding:5px 6px">' +
            '<div id="cat-div-' + n + '" style="display:' + catDsp + '">' +
                '<input type="text" id="cat-s-' + n + '" list="articulos_list" value="' + artLabel + '"' +
                ' onchange="onCatSelect(' + n + ')" oninput="onCatInput(' + n + ')"' +
                ' placeholder="Buscar artículo…" style="width:100%;font-size:.85rem">' +
            '</div>' +
            '<div id="libre-div-' + n + '" style="display:' + libreDsp + '">' +
                '<input type="text" id="libre-' + n + '" value="' + (tipo==='libre' ? desc : '') + '"' +
                ' oninput="onLibreInput(' + n + ')"' +
                ' placeholder="Descripción libre…" style="width:100%;font-size:.85rem">' +
            '</div>' +
            '<input type="hidden" name="items_art_id[]" id="artid-' + n + '" value="' + artId + '">' +
            '<input type="hidden" name="items_desc[]"   id="hdesc-' + n + '" value="' + desc + '">' +
        '</td>' +
        '<td style="padding:5px 6px">' +
            '<input type="number" name="items_precio[]" id="precio-' + n + '"' +
            ' value="' + prec + '" step="100" min="0" oninput="recalcCombo()"' +
            ' style="width:100px;font-size:.85rem" placeholder="0">' +
        '</td>' +
        '<td style="padding:5px 6px">' +
            '<input type="number" name="items_cant[]" id="cant-' + n + '"' +
            ' value="' + cant + '" min="1" oninput="recalcCombo()"' +
            ' style="width:55px;font-size:.85rem">' +
        '</td>' +
        '<td style="padding:5px 6px;text-align:right;white-space:nowrap;font-size:.85rem" id="sub-' + n + '">$ 0,00</td>' +
        '<td style="padding:5px 0 5px 4px;text-align:center">' +
            '<button type="button" onclick="removeComboRow(' + n + ')"' +
            ' style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:1rem;padding:2px 6px">' +
            '<i class="fa fa-times"></i></button>' +
        '</td>';

    document.getElementById('combo-tbody').appendChild(tr);
    recalcCombo();
}

function removeComboRow(n) {
    const tr = document.getElementById('crow-' + n);
    if (tr) tr.remove();
    recalcCombo();
}

function toggleComboTipo(n) {
    const tipo = document.getElementById('tipo-' + n).value;
    document.getElementById('cat-div-'  + n).style.display  = tipo === 'catalogo' ? '' : 'none';
    document.getElementById('libre-div-'+ n).style.display  = tipo === 'libre'    ? '' : 'none';
    document.getElementById('artid-'    + n).value = '0';
    document.getElementById('hdesc-'    + n).value = '';
    recalcCombo();
}

function onCatSelect(n) {
    const val  = document.getElementById('cat-s-' + n).value.trim();
    const item = artSearchMap[val];
    if (item) {
        document.getElementById('artid-' + n).value = item.id;
        document.getElementById('hdesc-' + n).value = item.desc;
        const info = articulosMap[item.id];
        if (info && !(parseFloat(document.getElementById('precio-' + n).value) > 0)) {
            document.getElementById('precio-' + n).value = info.precio.toFixed(2);
        }
    } else {
        document.getElementById('artid-' + n).value = '0';
        document.getElementById('hdesc-' + n).value = val;
    }
    recalcCombo();
}

function onCatInput(n) {
    const val = document.getElementById('cat-s-' + n).value.trim();
    if (!artSearchMap[val]) {
        document.getElementById('artid-' + n).value = '0';
        document.getElementById('hdesc-' + n).value = val;
    }
}

function onLibreInput(n) {
    document.getElementById('hdesc-' + n).value = document.getElementById('libre-' + n).value;
    recalcCombo();
}

function recalcCombo() {
    let totalItems = 0;
    document.querySelectorAll('#combo-tbody tr').forEach(function(tr) {
        const n = tr.id.replace('crow-','');
        const p = parseFloat(document.getElementById('precio-' + n)?.value) || 0;
        const c = parseInt(document.getElementById('cant-'   + n)?.value)   || 1;
        const s = p * c;
        const el = document.getElementById('sub-' + n);
        if (el) el.textContent = fmt(s);
        totalItems += s;
    });
    document.getElementById('combo-total-items').textContent = fmt(totalItems);
    updateComboTotal();
}

// ── Utilidades plan de pago ──────────────────────────────
function toggleDiaCobro() {
    const frec = document.getElementById('frecuencia').value;
    document.getElementById('grupo_dia_cobro').style.display = frec === 'semanal' ? '' : 'none';
}

function previsualizarFechas() {
    const venc = document.getElementById('primer_vencimiento').value;
    const frec = document.getElementById('frecuencia').value;
    const cant = parseInt(document.getElementById('cant_cuotas').value) || 0;
    const prev = document.getElementById('preview_fechas');
    if (!venc || !cant) { prev.textContent = ''; return; }
    const fechas = [];
    const cur = new Date(venc + 'T00:00:00');
    if (frec === 'diario') { while (cur.getDay() === 0) cur.setDate(cur.getDate() + 1); }
    for (let i = 0; i < Math.min(3, cant); i++) {
        fechas.push(cur.toLocaleDateString('es-AR', { day:'2-digit', month:'2-digit', year:'numeric' }));
        if      (frec === 'mensual')   cur.setMonth(cur.getMonth() + 1);
        else if (frec === 'quincenal') cur.setDate(cur.getDate() + 15);
        else if (frec === 'diario')  { cur.setDate(cur.getDate() + 1); while (cur.getDay()===0) cur.setDate(cur.getDate()+1); }
        else                           cur.setDate(cur.getDate() + 7);
    }
    const extra = cant > 3 ? ' y ' + (cant-3) + ' más…' : '';
    prev.textContent = 'Vencimientos: ' + fechas.join(', ') + extra;
}

// ── Init ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const comboIni = $combo_items_ini_json;
    if (comboIni && comboIni.length) {
        comboIni.forEach(function(item) { addComboRow(item); });
    }
    setModo(document.getElementById('modo').value);
    actualizarCobrador();
    toggleDiaCobro();
    calcularCuotas();
    previsualizarFechas();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
