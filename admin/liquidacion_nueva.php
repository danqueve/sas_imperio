<?php
// ============================================================
// admin/liquidacion_nueva.php — Crear Liquidación Semanal
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();

$cobradores = $pdo->query("SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

$error = '';

// AJAX: semanas disponibles para un cobrador (semana_lunes con pagos sin liquidar)
if (isset($_GET['ajax_semanas'])) {
    header('Content-Type: application/json');
    $cob_id = (int) ($_GET['cobrador_id'] ?? 0);
    if (!$cob_id) { echo json_encode([]); exit; }
    $rows = $pdo->prepare("
        SELECT pc.semana_lunes,
               COUNT(*)           AS cant_pagos,
               SUM(pc.monto_total) AS total
        FROM ic_pagos_confirmados pc
        WHERE pc.cobrador_id = ?
          AND pc.semana_lunes IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM ic_liquidaciones liq
              WHERE liq.cobrador_id = pc.cobrador_id
                AND liq.fecha_desde = pc.semana_lunes
          )
        GROUP BY pc.semana_lunes
        ORDER BY pc.semana_lunes DESC
        LIMIT 26
    ");
    $rows->execute([$cob_id]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// AJAX: cobros de una semana para un cobrador (por semana_lunes)
if (isset($_GET['ajax_cobros'])) {
    header('Content-Type: application/json');
    $cob_id      = (int) ($_GET['cobrador_id'] ?? 0);
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

        // Derivar fecha_desde (lunes) y fecha_hasta (domingo)
        $f_desde = $semana_lunes;
        $f_hasta = date('Y-m-d', strtotime($semana_lunes . ' +6 days'));

        // Verificar duplicado
        $dup = $pdo->prepare("SELECT id FROM ic_liquidaciones WHERE cobrador_id=? AND fecha_desde=? LIMIT 1");
        $dup->execute([$cob_id, $f_desde]);
        if ($dup->fetch()) {
            $error = 'Ya existe una liquidación para ese cobrador en esa semana.';
        }
    }

    if (!$error) {
        // Recalcular total cobrado desde BD por semana_lunes (no confiar en campo hidden)
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
        $estado     = ($v['accion'] ?? 'borrador') === 'aprobar' ? 'APROBADA' : 'BORRADOR';

        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO ic_liquidaciones
                  (cobrador_id, fecha_desde, fecha_hasta, total_cobrado, comision_pct,
                   comision_monto, total_extras, total_descuentos, total_neto,
                   estado, observaciones, created_by, aprobado_by, aprobado_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->execute([
                $cob_id, $f_desde, $f_hasta, $total_cobrado, $comision_pct,
                $comision_monto, $total_extras, $total_desc, $total_neto,
                $estado, trim($v['observaciones'] ?? ''), $_SESSION['user_id'],
                $estado === 'APROBADA' ? $_SESSION['user_id'] : null,
                $estado === 'APROBADA' ? date('Y-m-d H:i:s') : null,
            ]);
            $liq_id = (int) $pdo->lastInsertId();

            $ins_item = $pdo->prepare("
                INSERT INTO ic_liquidacion_items (liquidacion_id, tipo, descripcion, monto)
                VALUES (?,?,?,?)
            ");
            foreach ($items_validos as $it) {
                $ins_item->execute([$liq_id, $it['tipo'], $it['desc'], $it['monto']]);
            }

            $pdo->commit();
            registrar_log($pdo, $_SESSION['user_id'], 'LIQUIDACION_CREADA', 'liquidacion', $liq_id,
                'Cobrador: ' . $cob_id . ' | Semana: ' . $f_desde . ' | Neto: ' . formato_pesos($total_neto));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Liquidación creada correctamente.'];
            header("Location: liquidacion_ver?id=$liq_id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$page_title   = 'Nueva Liquidación';
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
                            <option value="<?= $cob['id'] ?>" <?= ($_POST['cobrador_id'] ?? '') == $cob['id'] ? 'selected' : '' ?>>
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
                            <option value="">— Primero seleccioná un cobrador —</option>
                        </select>
                        <span class="text-muted" style="font-size:.8rem;white-space:nowrap">o ingresá manualmente:</span>
                        <input type="date" id="semana_manual" placeholder="Lunes de la semana"
                               style="width:160px" onchange="seleccionarSemana(this.value)" title="Ingresar lunes de la semana manualmente">
                    </div>
                    <input type="hidden" name="semana_lunes" id="semana_lunes" value="<?= e($_POST['semana_lunes'] ?? '') ?>">
                    <div id="lbl_periodo" style="margin-top:8px;font-size:.82rem;color:var(--primary-light);display:none">
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
                         style="font-size:1.5rem;font-weight:800;color:var(--primary-light);margin-top:6px">$ 0,00</div>
                    <input type="hidden" name="total_cobrado_ref" id="total_cobrado_ref" value="0">
                </div>
                <div class="form-group">
                    <label>% de Comisión</label>
                    <input type="number" name="comision_pct" id="comision_pct"
                           value="<?= e($_POST['comision_pct'] ?? '5') ?>"
                           step="0.01" min="0" max="100" oninput="recalcular()">
                </div>
                <div class="form-group">
                    <label>Monto de Comisión</label>
                    <div id="lbl_comision"
                         style="font-size:1.5rem;font-weight:800;color:var(--success);margin-top:6px">$ 0,00</div>
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
                        <tr id="row-vacio">
                            <td colspan="4" class="text-center text-muted" style="padding:20px;font-size:.85rem">
                                Sin ítems. Usá "Agregar ítem" para ventas, bonos, gastos, etc.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:14px;text-align:center">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Extras / Ventas</div>
                    <div id="lbl_extras" style="font-size:1.2rem;font-weight:700;color:var(--success);margin-top:4px">$ 0,00</div>
                </div>
                <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:14px;text-align:center">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Gastos / Descuentos</div>
                    <div id="lbl_descuentos" style="font-size:1.2rem;font-weight:700;color:var(--danger);margin-top:4px">$ 0,00</div>
                </div>
                <div style="background:rgba(79,70,229,.15);border-radius:10px;padding:14px;text-align:center;border:1px solid rgba(103,112,210,.3)">
                    <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Neto a Pagar</div>
                    <div id="lbl_neto" style="font-size:1.4rem;font-weight:800;color:var(--accent);margin-top:4px">$ 0,00</div>
                </div>
            </div>
        </div>

        <!-- ── Observaciones ────────────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-comment"></i> Observaciones</span>
            </div>
            <textarea name="observaciones" rows="3"
                      placeholder="Notas adicionales..."><?= e($_POST['observaciones'] ?? '') ?></textarea>
        </div>

        <div class="d-flex gap-3 mb-4">
            <button type="submit" name="accion" value="borrador" class="btn-ic btn-ghost">
                <i class="fa fa-save"></i> Guardar Borrador
            </button>
            <button type="submit" name="accion" value="aprobar" class="btn-ic btn-primary">
                <i class="fa fa-check-circle"></i> Crear y Aprobar
            </button>
            <a href="liquidaciones" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$page_scripts = <<<'JS'
<script>
const TIPOS = {venta:'Venta',bonus:'Bonus',gasto:'Gasto',descuento:'Descuento',otro:'Otro'};
let itemCount   = 0;
let totalCobrado = 0;

const DIAS_ES = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
const MESES_ES = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

function fmt(n) {
    return '$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
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
    sel.innerHTML = '<option value="">Cargando...</option>';
    document.getElementById('lbl_periodo').style.display = 'none';
    document.getElementById('resumen-cobros').style.display = 'none';
    totalCobrado = 0;
    document.getElementById('lbl_total_cobrado').textContent = fmt(0);
    recalcular();

    if (!cobId) {
        sel.innerHTML = '<option value="">— Primero seleccioná un cobrador —</option>';
        return;
    }

    fetch(`?ajax_semanas=1&cobrador_id=${cobId}`)
        .then(r => r.json())
        .then(semanas => {
            if (semanas.length === 0) {
                sel.innerHTML = '<option value="">Sin semanas pendientes de liquidar</option>';
                return;
            }
            sel.innerHTML = '<option value="">— Seleccionar semana —</option>';
            semanas.forEach(s => {
                const lbl = fmtSemanaLabel(s.semana_lunes) + ' (' + s.cant_pagos + ' pagos · ' + fmt(s.total) + ')';
                const opt = document.createElement('option');
                opt.value = s.semana_lunes;
                opt.textContent = lbl;
                sel.appendChild(opt);
            });
        })
        .catch(() => { sel.innerHTML = '<option value="">Error al cargar semanas</option>'; });
}

function seleccionarSemana(lunes) {
    if (!lunes) return;
    document.getElementById('semana_lunes').value = lunes;

    // Mostrar label del período
    const dom = new Date(lunes + 'T00:00:00');
    dom.setDate(dom.getDate() + 6);
    const domIso = dom.toISOString().split('T')[0];
    document.getElementById('lbl_periodo_txt').textContent = fmtSemanaLabel(lunes);
    document.getElementById('lbl_periodo').style.display = '';

    consultarCobros(lunes);
}

function consultarCobros(semana) {
    const cobId = document.getElementById('cobrador_id').value;
    if (!cobId || !semana) return;

    document.getElementById('cargando-cobros').style.display = '';
    document.getElementById('resumen-cobros').style.display  = 'none';

    fetch(`?ajax_cobros=1&cobrador_id=${cobId}&semana_lunes=${semana}`)
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
                    html += `<tr><td>${fmtFecha(d.dia)}</td><td>${d.cant_pagos}</td><td class="fw-bold">${fmt(d.subtotal)}</td></tr>`;
                });
                html += `<tr style="background:rgba(79,70,229,.15);font-weight:700"><td colspan="2" style="text-align:right">Total</td><td>${fmt(data.total)}</td></tr>`;
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

function agregarItem() {
    itemCount++;
    const tipoOpts = Object.entries(TIPOS)
        .map(([k,v]) => `<option value="${k}">${v}</option>`).join('');
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.id = 'item-' + itemCount;
    row.innerHTML = `
        <td><select name="item_tipo[]" class="item-tipo" onchange="recalcular()" style="min-width:110px">${tipoOpts}</select></td>
        <td><input type="text" name="item_desc[]" placeholder="Descripción..." style="width:100%;min-width:180px"></td>
        <td><input type="number" name="item_monto[]" class="item-monto" step="0.01" min="0" placeholder="0.00" oninput="recalcular()" style="width:140px"></td>
        <td><button type="button" class="btn-ic btn-danger btn-icon btn-sm" onclick="this.closest('tr').remove();checkVacio();recalcular()"><i class="fa fa-trash"></i></button></td>
    `;
    document.getElementById('tbody-items').appendChild(row);
    document.getElementById('row-vacio').style.display = 'none';
    recalcular();
}

function checkVacio() {
    document.getElementById('row-vacio').style.display =
        document.querySelectorAll('.item-row').length === 0 ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const cobId = document.getElementById('cobrador_id').value;
    if (cobId) cargarSemanas();
    // Restaurar semana desde POST si hubo error
    const semanaHidden = document.getElementById('semana_lunes').value;
    if (semanaHidden) {
        document.getElementById('semana_manual').value = semanaHidden;
        seleccionarSemana(semanaHidden);
    }
    recalcular();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
