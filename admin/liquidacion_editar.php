<?php
// ============================================================
// admin/liquidacion_editar.php — Editar Liquidación (solo BORRADOR)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: liquidaciones'); exit; }

// Cargar liquidación
$stmt = $pdo->prepare("
    SELECT liq.*, u.nombre AS cob_nombre, u.apellido AS cob_apellido
    FROM ic_liquidaciones liq
    JOIN ic_usuarios u ON liq.cobrador_id = u.id
    WHERE liq.id = ?
");
$stmt->execute([$id]);
$liq = $stmt->fetch();
if (!$liq || $liq['estado'] !== 'BORRADOR') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Solo se pueden editar liquidaciones en estado BORRADOR.'];
    header("Location: liquidacion_ver?id=$id");
    exit;
}

// Ítems actuales
$items_stmt = $pdo->prepare("SELECT * FROM ic_liquidacion_items WHERE liquidacion_id=? ORDER BY tipo, id");
$items_stmt->execute([$id]);
$items_actuales = $items_stmt->fetchAll();

$cobradores = $pdo->query("SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

$error = '';

// AJAX: semanas disponibles para un cobrador (excluye la semana de esta liquidación en edición)
if (isset($_GET['ajax_semanas'])) {
    header('Content-Type: application/json');
    $cob_id = (int) ($_GET['cobrador_id'] ?? 0);
    if (!$cob_id) { echo json_encode([]); exit; }
    $rows = $pdo->prepare("
        SELECT pc.semana_lunes,
               COUNT(*)            AS cant_pagos,
               SUM(pc.monto_total) AS total
        FROM ic_pagos_confirmados pc
        WHERE pc.cobrador_id = ?
          AND pc.semana_lunes IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM ic_liquidaciones liq
              WHERE liq.cobrador_id = pc.cobrador_id
                AND liq.fecha_desde = pc.semana_lunes
                AND liq.id != ?
          )
        GROUP BY pc.semana_lunes
        ORDER BY pc.semana_lunes DESC
        LIMIT 26
    ");
    $rows->execute([$cob_id, $id]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// AJAX: cobros de una semana por semana_lunes
if (isset($_GET['ajax_cobros'])) {
    header('Content-Type: application/json');
    $cob_id       = (int) ($_GET['cobrador_id'] ?? 0);
    $semana_lunes = trim($_GET['semana_lunes'] ?? '');
    if (!$cob_id || !$semana_lunes) {
        echo json_encode(['total' => 0, 'dias' => []]);
        exit;
    }
    $rows = $pdo->prepare("
        SELECT DATE(pc.fecha_jornada) AS dia,
               SUM(pc.monto_total)   AS subtotal,
               COUNT(*)              AS cant_pagos
        FROM ic_pagos_confirmados pc
        WHERE pc.cobrador_id  = ?
          AND pc.semana_lunes = ?
        GROUP BY DATE(pc.fecha_jornada)
        ORDER BY dia
    ");
    $rows->execute([$cob_id, $semana_lunes]);
    $dias  = $rows->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($dias, 'subtotal'));
    echo json_encode(['total' => (float)$total, 'dias' => $dias]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v           = $_POST;
    $items_desc  = $v['item_desc']  ?? [];
    $items_tipo  = $v['item_tipo']  ?? [];
    $items_monto = $v['item_monto'] ?? [];

    $semana_lunes = trim($v['semana_lunes'] ?? '');

    if (empty($v['cobrador_id']) || !$semana_lunes) {
        $error = 'Completá cobrador y semana.';
    } else {
        $cob_id  = (int) $v['cobrador_id'];
        $f_desde = $semana_lunes;
        $f_hasta = date('Y-m-d', strtotime($semana_lunes . ' +6 days'));

        // Verificar duplicado (otra liquidación distinta para mismo cobrador/semana)
        $dup = $pdo->prepare("SELECT id FROM ic_liquidaciones WHERE cobrador_id=? AND fecha_desde=? AND id != ? LIMIT 1");
        $dup->execute([$cob_id, $f_desde, $id]);
        if ($dup->fetch()) {
            $error = 'Ya existe otra liquidación para ese cobrador en esa semana.';
        }
    }

    if (!$error) {
        // Recalcular total cobrado desde BD
        $stmt_total = $pdo->prepare("
            SELECT COALESCE(SUM(monto_total),0)
            FROM ic_pagos_confirmados
            WHERE cobrador_id=? AND semana_lunes=?
        ");
        $stmt_total->execute([$cob_id, $semana_lunes]);
        $total_cobrado = (float) $stmt_total->fetchColumn();

        $comision_pct   = (float) ($v['comision_pct'] ?? 5);
        $comision_monto = round($total_cobrado * $comision_pct / 100, 2);

        $total_extras = 0.0;
        $total_desc   = 0.0;
        $items_validos = [];
        for ($i = 0; $i < count($items_desc); $i++) {
            $desc  = trim($items_desc[$i] ?? '');
            $tipo  = $items_tipo[$i]  ?? '';
            $monto = (float) ($items_monto[$i] ?? 0);
            if ($desc === '' || $monto == 0) continue;
            $tipos_validos = ['venta', 'bonus', 'gasto', 'descuento', 'otro'];
            if (!in_array($tipo, $tipos_validos)) continue;
            if (in_array($tipo, ['gasto', 'descuento'])) {
                $monto_real = -abs($monto);
                $total_desc += abs($monto);
            } else {
                $monto_real = abs($monto);
                $total_extras += abs($monto);
            }
            $items_validos[] = ['desc' => $desc, 'tipo' => $tipo, 'monto' => $monto_real];
        }

        $total_neto = round($comision_monto + $total_extras - $total_desc, 2);

        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE ic_liquidaciones SET
                    cobrador_id=?, fecha_desde=?, fecha_hasta=?,
                    total_cobrado=?, comision_pct=?, comision_monto=?,
                    total_extras=?, total_descuentos=?, total_neto=?,
                    observaciones=?
                WHERE id=?
            ")->execute([
                $cob_id, $f_desde, $f_hasta,
                $total_cobrado, $comision_pct, $comision_monto,
                $total_extras, $total_desc, $total_neto,
                trim($v['observaciones'] ?? ''),
                $id,
            ]);

            // Reemplazar ítems
            $pdo->prepare("DELETE FROM ic_liquidacion_items WHERE liquidacion_id=?")->execute([$id]);
            $ins_item = $pdo->prepare("
                INSERT INTO ic_liquidacion_items (liquidacion_id, tipo, descripcion, monto)
                VALUES (?,?,?,?)
            ");
            foreach ($items_validos as $it) {
                $ins_item->execute([$id, $it['tipo'], $it['desc'], $it['monto']]);
            }

            $pdo->commit();
            registrar_log($pdo, $_SESSION['user_id'], 'LIQUIDACION_EDITADA', 'liquidacion', $id,
                'Cobrador: ' . $cob_id . ' | Semana: ' . $f_desde . ' | Neto: ' . formato_pesos($total_neto));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Liquidación actualizada correctamente.'];
            header("Location: liquidacion_ver?id=$id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }

    // Si hay error, recargar items desde POST
    $items_actuales = [];
    for ($i = 0; $i < count($items_desc ?? []); $i++) {
        $desc  = trim($items_desc[$i] ?? '');
        $tipo  = $items_tipo[$i] ?? 'venta';
        $monto = (float) ($items_monto[$i] ?? 0);
        if ($desc === '') continue;
        $items_actuales[] = ['tipo' => $tipo, 'descripcion' => $desc, 'monto' => $monto];
    }
}

// Valores del formulario: POST tiene prioridad (en caso de error), sino la BD
$val_cobrador   = $_POST['cobrador_id']  ?? $liq['cobrador_id'];
$val_semana     = $_POST['semana_lunes'] ?? $liq['fecha_desde']; // fecha_desde = semana_lunes
$val_comision   = $_POST['comision_pct'] ?? $liq['comision_pct'];
$val_obs        = $_POST['observaciones'] ?? $liq['observaciones'];

$page_title   = 'Editar Liquidación #' . $id;
$page_current = 'liquidaciones';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:900px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-ic" id="form-liq">

        <!-- ── Cobrador y Semana ─────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-calendar-week"></i> Cobrador y Semana</span>
            </div>
            <div class="form-grid">

                <div class="form-group" style="grid-column:span 2">
                    <label>Cobrador *</label>
                    <select name="cobrador_id" id="cobrador_id" required onchange="cargarSemanas()">
                        <option value="">— Seleccionar cobrador —</option>
                        <?php foreach ($cobradores as $cob): ?>
                            <option value="<?= $cob['id'] ?>" <?= (int)$val_cobrador === (int)$cob['id'] ? 'selected' : '' ?>>
                                <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="grid-column:span 2">
                    <label>Semana a Liquidar *
                        <span class="text-muted" style="font-size:.75rem;font-weight:400"> — Lunes a Domingo</span>
                    </label>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <select id="sel_semana" style="flex:1;min-width:260px" onchange="seleccionarSemana(this.value)">
                            <option value="">Cargando semanas...</option>
                        </select>
                        <span class="text-muted" style="font-size:.8rem;white-space:nowrap">o ingresá manualmente:</span>
                        <input type="date" id="semana_manual" placeholder="Lunes de la semana"
                               style="width:160px" onchange="seleccionarSemana(this.value)"
                               value="<?= e($val_semana) ?>">
                    </div>
                    <input type="hidden" name="semana_lunes" id="semana_lunes" value="<?= e($val_semana) ?>">
                    <div id="lbl_periodo" style="margin-top:8px;font-size:.82rem;color:var(--primary-light)">
                        <i class="fa fa-calendar-range"></i> <span id="lbl_periodo_txt"></span>
                    </div>
                </div>

            </div>

            <!-- Resumen de cobros de la semana -->
            <div id="resumen-cobros" style="margin-top:16px;display:none">
                <div style="font-size:.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px">
                    <i class="fa fa-search"></i> Cobros confirmados en la semana
                </div>
                <div id="tabla-dias-cobro" style="overflow-x:auto"></div>
            </div>
            <div id="cargando-cobros" style="margin-top:12px;display:none;color:var(--text-muted);font-size:.85rem">
                <i class="fa fa-spinner fa-spin"></i> Consultando cobros...
            </div>
        </div>

        <!-- ── Comisión ─────────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-percent"></i> Comisión</span>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Total Cobrado en la Semana</label>
                    <div id="lbl_total_cobrado"
                         style="font-size:1.5rem;font-weight:800;color:var(--primary-light);margin-top:6px">
                        <?= formato_pesos($liq['total_cobrado']) ?>
                    </div>
                    <input type="hidden" name="total_cobrado_ref" id="total_cobrado_ref"
                           value="<?= e($liq['total_cobrado']) ?>">
                </div>
                <div class="form-group">
                    <label>% de Comisión</label>
                    <input type="number" name="comision_pct" id="comision_pct"
                           value="<?= e($val_comision) ?>"
                           step="0.01" min="0" max="100" oninput="recalcular()">
                </div>
                <div class="form-group">
                    <label>Monto de Comisión</label>
                    <div id="lbl_comision"
                         style="font-size:1.5rem;font-weight:800;color:var(--success);margin-top:6px">
                        <?= formato_pesos($liq['comision_monto']) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Ítems adicionales ─────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-list-ul"></i> Ítems Adicionales</span>
                <button type="button" class="btn-ic btn-ghost btn-sm" onclick="agregarItem()">
                    <i class="fa fa-plus"></i> Agregar ítem
                </button>
            </div>

            <div style="overflow-x:auto">
                <table class="table-ic" id="tabla-items">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descripción</th>
                            <th style="width:160px">Monto $</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="tbody-items">
                        <tr id="row-vacio" <?= !empty($items_actuales) ? 'style="display:none"' : '' ?>>
                            <td colspan="4" class="text-center text-muted" style="padding:20px;font-size:.85rem">
                                Sin ítems. Usá "Agregar ítem" para ventas, bonos, gastos, etc.
                            </td>
                        </tr>
                        <?php $itemIdx = 0; foreach ($items_actuales as $it): ?>
                            <?php $monto_abs = abs((float)$it['monto']); ?>
                            <tr class="item-row" id="item-<?= ++$itemIdx ?>">
                                <td>
                                    <select name="item_tipo[]" class="item-tipo" onchange="recalcular()" style="min-width:110px">
                                        <?php foreach (['venta'=>'Venta','bonus'=>'Bonus','gasto'=>'Gasto','descuento'=>'Descuento','otro'=>'Otro'] as $k => $lbl): ?>
                                            <option value="<?= $k ?>" <?= $it['tipo'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="item_desc[]"
                                           value="<?= e($it['descripcion']) ?>"
                                           placeholder="Descripción..." style="width:100%;min-width:180px">
                                </td>
                                <td>
                                    <input type="number" name="item_monto[]" class="item-monto"
                                           step="0.01" min="0" placeholder="0.00" oninput="recalcular()"
                                           value="<?= $monto_abs ?>" style="width:140px">
                                </td>
                                <td>
                                    <button type="button" class="btn-ic btn-danger btn-icon btn-sm"
                                            onclick="this.closest('tr').remove();checkVacio();recalcular()">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:14px;text-align:center">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Extras / Ventas</div>
                    <div id="lbl_extras" style="font-size:1.2rem;font-weight:700;color:var(--success);margin-top:4px"><?= formato_pesos($liq['total_extras']) ?></div>
                </div>
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:14px;text-align:center">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Gastos / Descuentos</div>
                    <div id="lbl_descuentos" style="font-size:1.2rem;font-weight:700;color:var(--danger);margin-top:4px"><?= formato_pesos($liq['total_descuentos']) ?></div>
                </div>
                <div style="background:rgba(79,70,229,.15);border-radius:10px;padding:14px;text-align:center;border:1px solid rgba(103,112,210,.3)">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Neto a Pagar</div>
                    <div id="lbl_neto" style="font-size:1.4rem;font-weight:800;color:var(--accent);margin-top:4px"><?= formato_pesos($liq['total_neto']) ?></div>
                </div>
            </div>
        </div>

        <!-- ── Observaciones ────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-comment"></i> Observaciones</span>
            </div>
            <textarea name="observaciones" rows="3"
                      placeholder="Notas adicionales..."><?= e($val_obs) ?></textarea>
        </div>

        <div class="d-flex gap-3 mb-4">
            <button type="submit" class="btn-ic btn-primary">
                <i class="fa fa-save"></i> Guardar Cambios
            </button>
            <a href="liquidacion_ver?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$itemIdx_js = $itemIdx ?? 0;
$total_cobrado_js = $liq['total_cobrado'];
$liq_id_js = $id;
$page_scripts = <<<JS
<script>
const TIPOS = {venta:'Venta',bonus:'Bonus',gasto:'Gasto',descuento:'Descuento',otro:'Otro'};
let itemCount    = {$itemIdx_js};
let totalCobrado = {$total_cobrado_js};
const LIQ_ID     = {$liq_id_js};

const DIAS_ES  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
const MESES_ES = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

function fmt(n) {
    return '\$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function fmtFecha(iso) {
    const d = new Date(iso + 'T00:00:00');
    return DIAS_ES[d.getDay()] + ' ' + String(d.getDate()).padStart(2,'0') + '/' + MESES_ES[d.getMonth()];
}

function fmtSemanaLabel(lunes) {
    const d = new Date(lunes + 'T00:00:00');
    const dom = new Date(d); dom.setDate(dom.getDate() + 6);
    return 'Semana ' + fmtFecha(lunes) + ' al ' + fmtFecha(dom.toISOString().split('T')[0]);
}

function cargarSemanas() {
    const cobId = document.getElementById('cobrador_id').value;
    const sel   = document.getElementById('sel_semana');
    if (!cobId) { sel.innerHTML = '<option value="">— Primero seleccioná un cobrador —</option>'; return; }

    sel.innerHTML = '<option value="">Cargando...</option>';
    fetch(`?id=\${LIQ_ID}&ajax_semanas=1&cobrador_id=\${cobId}`)
        .then(r => r.json())
        .then(semanas => {
            const current = document.getElementById('semana_lunes').value;
            sel.innerHTML = '<option value="">— Seleccionar semana —</option>';
            semanas.forEach(s => {
                const lbl = fmtSemanaLabel(s.semana_lunes) + ' (' + s.cant_pagos + ' pagos · ' + fmt(s.total) + ')';
                const opt = document.createElement('option');
                opt.value = s.semana_lunes;
                opt.textContent = lbl;
                if (s.semana_lunes === current) opt.selected = true;
                sel.appendChild(opt);
            });
            // Si la semana actual no está en la lista (ya tenía liquidación), agregarla
            if (current && !semanas.find(s => s.semana_lunes === current)) {
                const opt = document.createElement('option');
                opt.value = current;
                opt.textContent = fmtSemanaLabel(current) + ' (semana actual)';
                opt.selected = true;
                sel.appendChild(opt);
            }
        })
        .catch(() => { sel.innerHTML = '<option value="">Error al cargar semanas</option>'; });
}

function seleccionarSemana(lunes) {
    if (!lunes) return;
    document.getElementById('semana_lunes').value = lunes;
    document.getElementById('lbl_periodo_txt').textContent = fmtSemanaLabel(lunes);
    document.getElementById('lbl_periodo').style.display = '';
    consultarCobros(lunes);
}

function consultarCobros(semana) {
    const cobId = document.getElementById('cobrador_id').value;
    if (!cobId || !semana) return;

    document.getElementById('cargando-cobros').style.display = '';
    document.getElementById('resumen-cobros').style.display  = 'none';

    fetch(`?id=\${LIQ_ID}&ajax_cobros=1&cobrador_id=\${cobId}&semana_lunes=\${semana}`)
        .then(r => r.json())
        .then(data => {
            totalCobrado = data.total;
            document.getElementById('total_cobrado_ref').value = totalCobrado;
            document.getElementById('lbl_total_cobrado').textContent = fmt(totalCobrado);
            recalcular();

            let html = '<table class="table-ic" style="font-size:.82rem"><thead><tr><th>Día</th><th>Pagos</th><th>Subtotal</th></tr></thead><tbody>';
            if (data.dias.length === 0) {
                html += '<tr><td colspan="3" class="text-muted text-center" style="padding:12px">Sin cobros confirmados en esta semana.</td></tr>';
            } else {
                data.dias.forEach(d => {
                    html += '<tr><td>' + fmtFecha(d.dia) + '</td><td>' + d.cant_pagos + '</td><td class="fw-bold">' + fmt(d.subtotal) + '</td></tr>';
                });
                html += '<tr style="background:rgba(79,70,229,.15);font-weight:700"><td colspan="2" style="text-align:right">Total</td><td>' + fmt(data.total) + '</td></tr>';
            }
            html += '</tbody></table>';
            document.getElementById('tabla-dias-cobro').innerHTML = html;
            document.getElementById('resumen-cobros').style.display = '';
            document.getElementById('cargando-cobros').style.display = 'none';
        })
        .catch(() => { document.getElementById('cargando-cobros').style.display = 'none'; });
}

function recalcular() {
    const pct      = parseFloat(document.getElementById('comision_pct').value) || 0;
    const comision = totalCobrado * pct / 100;
    document.getElementById('lbl_comision').textContent = fmt(comision);

    let extras = 0, descuentos = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const tipo  = row.querySelector('.item-tipo').value;
        const monto = parseFloat(row.querySelector('.item-monto').value) || 0;
        if (['gasto','descuento'].includes(tipo)) descuentos += Math.abs(monto);
        else extras += Math.abs(monto);
    });

    const neto = comision + extras - descuentos;
    document.getElementById('lbl_extras').textContent     = fmt(extras);
    document.getElementById('lbl_descuentos').textContent = fmt(descuentos);
    document.getElementById('lbl_neto').textContent       = fmt(neto);
}

function checkVacio() {
    document.getElementById('row-vacio').style.display =
        document.querySelectorAll('.item-row').length === 0 ? '' : 'none';
}

function agregarItem() {
    itemCount++;
    const tipoOpts = Object.entries(TIPOS).map(([k,v]) => '<option value="' + k + '">' + v + '</option>').join('');
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.id = 'item-' + itemCount;
    row.innerHTML = '<td><select name="item_tipo[]" class="item-tipo" onchange="recalcular()" style="min-width:110px">' + tipoOpts + '</select></td>'
        + '<td><input type="text" name="item_desc[]" placeholder="Descripción..." style="width:100%;min-width:180px"></td>'
        + '<td><input type="number" name="item_monto[]" class="item-monto" step="0.01" min="0" placeholder="0.00" oninput="recalcular()" style="width:140px"></td>'
        + '<td><button type="button" class="btn-ic btn-danger btn-icon btn-sm" onclick="this.closest(\'tr\').remove();checkVacio();recalcular()"><i class="fa fa-trash"></i></button></td>';
    document.getElementById('tbody-items').appendChild(row);
    document.getElementById('row-vacio').style.display = 'none';
    recalcular();
}

document.addEventListener('DOMContentLoaded', function() {
    const semana = document.getElementById('semana_lunes').value;
    if (semana) {
        document.getElementById('lbl_periodo_txt').textContent = fmtSemanaLabel(semana);
        document.getElementById('lbl_periodo').style.display = '';
        consultarCobros(semana);
    }
    cargarSemanas();
    recalcular();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
