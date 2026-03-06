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

$clientes = $pdo->query("
    SELECT c.id, c.nombres, c.apellidos, c.cobrador_id, c.zona,
           CONCAT(u.nombre, ' ', u.apellido) AS cobrador_nombre
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id AND u.activo = 1
    WHERE c.estado='ACTIVO'
    ORDER BY c.apellidos, c.nombres
")->fetchAll();

$vendedores = $pdo->query("SELECT id,nombre,apellido FROM ic_vendedores WHERE activo=1 ORDER BY nombre")->fetchAll();

// Catálogo de artículos activos
$articulos_raw = $pdo->query("
    SELECT id, descripcion, precio_venta, sku, stock
    FROM ic_articulos WHERE activo=1 ORDER BY descripcion
")->fetchAll();

// Mapa JS: id → {precio, desc, stock}
$articulos_map = [];
$articulos_search_map = []; // label → {id, desc}
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

// Mapa cliente_id → {cob_id, cob_nombre, zona}
$clientes_cob_map = [];
foreach ($clientes as $cl) {
    $clientes_cob_map[(int)$cl['id']] = [
        'cob_id'     => $cl['cobrador_id'] ? (int)$cl['cobrador_id'] : null,
        'cob_nombre' => $cl['cobrador_nombre'] ?? null,
        'zona'       => $cl['zona'] ?? '',
    ];
}

$error = '';
$v = [
    'cliente_id'            => $cliente_id,
    'frecuencia'            => 'semanal',
    'cant_cuotas'           => 12,
    'interes_pct'           => 0,
    'interes_moratorio_pct' => 15,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;

    $articulo_id = (int)($v['articulo_id'] ?? 0);

    if (
        empty($v['cliente_id']) || !$articulo_id ||
        empty($v['cobrador_id']) ||
        empty($v['cant_cuotas']) || empty($v['primer_vencimiento'])
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

                // Lock de fila para atomicidad del stock
                $art_stmt = $pdo->prepare("SELECT stock, descripcion FROM ic_articulos WHERE id=? FOR UPDATE");
                $art_stmt->execute([$articulo_id]);
                $art_row = $art_stmt->fetch();

                if (!$art_row || $art_row['stock'] < 1) {
                    $pdo->rollBack();
                    $error = 'Sin stock disponible para el artículo seleccionado (stock: ' . ($art_row['stock'] ?? 0) . ').';
                } else {
                    // Snapshot de descripción
                    $articulo_desc_snap = trim($v['articulo_desc'] ?? '') ?: $art_row['descripcion'];

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
                        $articulo_id,
                        $articulo_desc_snap,
                        $v['cobrador_id'],
                        ($v['vendedor_id'] ?: null),
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

                    generar_cuotas($credito_id, [
                        'primer_vencimiento' => $v['primer_vencimiento'],
                        'cant_cuotas'        => (int) $v['cant_cuotas'],
                        'frecuencia'         => $v['frecuencia'],
                        'monto_cuota'        => $monto_cuot,
                    ], $pdo);

                    // Descontar stock
                    $pdo->prepare("UPDATE ic_articulos SET stock = stock - 1 WHERE id=?")
                        ->execute([$articulo_id]);

                    $pdo->commit();
                    registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_CREADO', 'credito', (int)$credito_id,
                        'Cuotas: ' . $v['cant_cuotas'] . ' | Total: ' . formato_pesos($monto_tot));
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito registrado y cuotas generadas.'];
                    header("Location: ver?id=$credito_id");
                    exit;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }
}

$page_title   = 'Nuevo Crédito';
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:860px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-ic">

        <!-- ── Datos del crédito ──────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Datos del Crédito</span>
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

                <!-- Selector de artículo desde catálogo -->
                <div class="form-group" style="grid-column:span 2">
                    <label>Artículo del Catálogo *</label>
                    <input type="text" id="articulo_search" list="articulos_list"
                           value="<?php
                               // Recuperar label si hubo POST con error
                               if (!empty($v['articulo_id'])) {
                                   $ai = (int)$v['articulo_id'];
                                   echo e($articulos_map[$ai]['label'] ?? $v['articulo_desc'] ?? '');
                               }
                           ?>"
                           placeholder="Buscar por nombre o SKU..."
                           autocomplete="off" required style="width:100%">
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

                <div class="form-group">
                    <label>Precio del Artículo $</label>
                    <input type="number" name="precio_articulo" id="precio_articulo"
                           value="<?= $v['precio_articulo'] ?? '' ?>"
                           step="0.01" min="0" required oninput="calcularCuotas()"
                           placeholder="0.00">
                    <small class="text-muted">Pre-llenado desde el catálogo, editable.</small>
                </div>

                <div class="form-group">
                    <label>Interés Total % (sobre el precio)</label>
                    <input type="number" name="interes_pct" id="interes_pct"
                           value="<?= $v['interes_pct'] ?? 0 ?>"
                           step="0.01" min="0" max="999" oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Interés Moratorio % semanal</label>
                    <input type="number" name="interes_moratorio_pct"
                           value="<?= $v['interes_moratorio_pct'] ?? 15 ?>"
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
                    <input type="number" name="cant_cuotas" id="cant_cuotas"
                           value="<?= $v['cant_cuotas'] ?? 12 ?>"
                           min="1" max="520" required oninput="calcularCuotas()">
                </div>

                <div class="form-group">
                    <label>Primer Vencimiento *</label>
                    <input type="date" name="primer_vencimiento"
                           value="<?= $v['primer_vencimiento'] ?? '' ?>" required>
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
$clientes_cob_json   = json_encode($clientes_cob_map,    JSON_UNESCAPED_UNICODE);
$articulos_map_json  = json_encode($articulos_map,        JSON_UNESCAPED_UNICODE);
$art_search_json     = json_encode($articulos_search_map, JSON_UNESCAPED_UNICODE);

$page_scripts = <<<JS
<script>
const clientesCob   = $clientes_cob_json;
const articulosMap  = $articulos_map_json;
const artSearchMap  = $art_search_json;

// ── Artículo selector ────────────────────────────────────
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
        document.getElementById('articulo_id').value   = '';
        document.getElementById('articulo_desc').value = '';
        document.getElementById('stock_info').textContent = '';
    }
});

// ── Cobrador / Zona ──────────────────────────────────────
function actualizarCobrador() {
    const sel    = document.querySelector('[name=cliente_id]');
    const cid    = parseInt(sel.value) || 0;
    const info   = clientesCob[cid];
    const disp   = document.getElementById('cobrador_display');
    const hidden = document.getElementById('cobrador_id');
    const zdis   = document.getElementById('zona_display');

    if (cid && info && info.cob_id) {
        disp.innerHTML = '<span style="color:var(--primary-light)">' + info.cob_nombre + '</span>';
        hidden.value   = info.cob_id;
    } else if (cid && info && !info.cob_id) {
        disp.innerHTML = '<span style="color:var(--danger)"><i class="fa fa-exclamation-triangle"></i> Sin cobrador asignado</span>';
        hidden.value   = '';
    } else {
        disp.innerHTML = '<span style="color:var(--text-muted)">— Seleccioná un cliente —</span>';
        hidden.value   = '';
    }

    if (cid && info && info.zona) {
        zdis.innerHTML = '<span style="color:var(--primary-light)">' + info.zona + '</span>';
    } else {
        zdis.innerHTML = '<span style="color:var(--text-muted)">— Seleccioná un cliente —</span>';
    }
}

document.querySelector('[name=cliente_id]').addEventListener('change', actualizarCobrador);

// ── Calculador de cuotas ─────────────────────────────────
function fmt(n) {
    return '\$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function calcularCuotas() {
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

function toggleDiaCobro() {
    const frec = document.getElementById('frecuencia').value;
    document.getElementById('grupo_dia_cobro').style.display = frec === 'semanal' ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function(){
    actualizarCobrador();
    toggleDiaCobro();
    calcularCuotas();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
