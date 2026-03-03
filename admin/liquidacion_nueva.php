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
$cobros_periodo = [];
$total_cobrado_periodo = 0.0;

// Calcular lunes/sábado de la semana anterior por defecto
$lunes_default  = date('Y-m-d', strtotime('last monday', strtotime('today')));
$sabado_default = date('Y-m-d', strtotime($lunes_default . ' +5 days'));
// Si hoy es lunes, mostrar semana actual
if (date('N') == 1) {
    $lunes_default  = date('Y-m-d');
    $sabado_default = date('Y-m-d', strtotime($lunes_default . ' +5 days'));
}

// AJAX: consultar cobros de un cobrador en un período
if (isset($_GET['ajax_cobros'])) {
    header('Content-Type: application/json');
    $cob_id   = (int) ($_GET['cobrador_id'] ?? 0);
    $f_desde  = $_GET['fecha_desde'] ?? '';
    $f_hasta  = $_GET['fecha_hasta'] ?? '';
    if (!$cob_id || !$f_desde || !$f_hasta) {
        echo json_encode(['total' => 0, 'dias' => []]);
        exit;
    }
    $rows = $pdo->prepare("
        SELECT DATE(pc.fecha_pago) AS dia,
               SUM(pc.monto_total) AS subtotal,
               COUNT(*) AS cant_pagos
        FROM ic_pagos_confirmados pc
        WHERE pc.cobrador_id = ?
          AND pc.fecha_pago BETWEEN ? AND ?
        GROUP BY DATE(pc.fecha_pago)
        ORDER BY dia
    ");
    $rows->execute([$cob_id, $f_desde, $f_hasta]);
    $dias = $rows->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($dias, 'subtotal'));
    echo json_encode(['total' => (float)$total, 'dias' => $dias]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;
    $items_desc  = $v['item_desc']  ?? [];
    $items_tipo  = $v['item_tipo']  ?? [];
    $items_monto = $v['item_monto'] ?? [];

    if (empty($v['cobrador_id']) || empty($v['fecha_desde']) || empty($v['fecha_hasta'])) {
        $error = 'Completá cobrador y período.';
    } else {
        $cob_id  = (int) $v['cobrador_id'];
        $f_desde = $v['fecha_desde'];
        $f_hasta = $v['fecha_hasta'];

        // Recalcular total cobrado desde BD (no confiar en el campo hidden)
        $stmt_total = $pdo->prepare("
            SELECT COALESCE(SUM(monto_total),0)
            FROM ic_pagos_confirmados
            WHERE cobrador_id=? AND fecha_pago BETWEEN ? AND ?
        ");
        $stmt_total->execute([$cob_id, $f_desde, $f_hasta]);
        $total_cobrado = (float) $stmt_total->fetchColumn();

        $comision_pct  = (float) ($v['comision_pct'] ?? 5);
        $comision_monto = round($total_cobrado * $comision_pct / 100, 2);

        // Calcular extras y descuentos de los ítems
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
            // gastos y descuentos son negativos internamente
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
                'Cobrador: ' . $cob_id . ' | Período: ' . $f_desde . ' a ' . $f_hasta . ' | Neto: ' . formato_pesos($total_neto));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Liquidación creada correctamente.'];
            header("Location: liquidacion_ver.php?id=$liq_id");
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

        <!-- ── Período y Cobrador ───────────────────────── -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-calendar-week"></i> Período y Cobrador</span>
            </div>
            <div class="form-grid">

                <div class="form-group" style="grid-column:span 2">
                    <label>Cobrador *</label>
                    <select name="cobrador_id" id="cobrador_id" required onchange="consultarCobros()">
                        <option value="">— Seleccionar cobrador —</option>
                        <?php foreach ($cobradores as $cob): ?>
                            <option value="<?= $cob['id'] ?>" <?= ($_POST['cobrador_id'] ?? '') == $cob['id'] ? 'selected' : '' ?>>
                                <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Fecha Desde (Lunes) *</label>
                    <input type="date" name="fecha_desde" id="fecha_desde"
                           value="<?= e($_POST['fecha_desde'] ?? $lunes_default) ?>"
                           required onchange="ajustarHasta();consultarCobros()">
                </div>

                <div class="form-group">
                    <label>Fecha Hasta (Sábado) *</label>
                    <input type="date" name="fecha_hasta" id="fecha_hasta"
                           value="<?= e($_POST['fecha_hasta'] ?? $sabado_default) ?>"
                           required onchange="consultarCobros()">
                </div>

            </div>

            <!-- Resumen de cobros del período -->
            <div id="resumen-cobros" style="margin-top:16px;display:none">
                <div style="font-size:.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px">
                    <i class="fa fa-search"></i> Cobros confirmados en el período
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
                    <label>Total Cobrado en el Período</label>
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

            <!-- Totales calculador -->
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
            <a href="liquidaciones.php" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$page_scripts = <<<'JS'
<script>
const TIPOS = {venta:'Venta',bonus:'Bonus',gasto:'Gasto',descuento:'Descuento',otro:'Otro'};
let itemCount = 0;
let totalCobrado = 0;

function fmt(n) {
    return '$ ' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function ajustarHasta() {
    const desde = document.getElementById('fecha_desde').value;
    if (!desde) return;
    const d = new Date(desde + 'T00:00:00');
    d.setDate(d.getDate() + 5);
    const hasta = d.toISOString().split('T')[0];
    document.getElementById('fecha_hasta').value = hasta;
}

function consultarCobros() {
    const cobId  = document.getElementById('cobrador_id').value;
    const fDesde = document.getElementById('fecha_desde').value;
    const fHasta = document.getElementById('fecha_hasta').value;
    if (!cobId || !fDesde || !fHasta) return;

    document.getElementById('cargando-cobros').style.display = '';
    document.getElementById('resumen-cobros').style.display  = 'none';

    fetch(`?ajax_cobros=1&cobrador_id=${cobId}&fecha_desde=${fDesde}&fecha_hasta=${fHasta}`)
        .then(r => r.json())
        .then(data => {
            totalCobrado = data.total;
            document.getElementById('total_cobrado_ref').value = totalCobrado;
            document.getElementById('lbl_total_cobrado').textContent = fmt(totalCobrado);
            recalcular();

            // Armar tabla de días
            let html = '<table class="table-ic" style="font-size:.82rem"><thead><tr><th>Día</th><th>Pagos</th><th>Subtotal</th></tr></thead><tbody>';
            if (data.dias.length === 0) {
                html += '<tr><td colspan="3" class="text-muted text-center" style="padding:12px">Sin cobros confirmados en este período.</td></tr>';
            } else {
                data.dias.forEach(d => {
                    const f = new Date(d.dia + 'T00:00:00');
                    const label = f.toLocaleDateString('es-AR', {weekday:'long', day:'2-digit', month:'2-digit'});
                    html += `<tr><td>${label}</td><td>${d.cant_pagos}</td><td class="fw-bold">${fmt(d.subtotal)}</td></tr>`;
                });
                html += `<tr style="background:rgba(79,70,229,.15);font-weight:700"><td colspan="2" style="text-align:right">Total</td><td>${fmt(data.total)}</td></tr>`;
            }
            html += '</tbody></table>';
            document.getElementById('tabla-dias-cobro').innerHTML = html;
            document.getElementById('resumen-cobros').style.display = '';
            document.getElementById('cargando-cobros').style.display = 'none';
        })
        .catch(() => {
            document.getElementById('cargando-cobros').style.display = 'none';
        });
}

function recalcular() {
    const pct       = parseFloat(document.getElementById('comision_pct').value) || 0;
    const comision  = totalCobrado * pct / 100;
    document.getElementById('lbl_comision').textContent = fmt(comision);

    let extras = 0, descuentos = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const tipo  = row.querySelector('.item-tipo').value;
        const monto = parseFloat(row.querySelector('.item-monto').value) || 0;
        if (['gasto','descuento'].includes(tipo)) {
            descuentos += Math.abs(monto);
        } else {
            extras += Math.abs(monto);
        }
    });

    const neto = comision + extras - descuentos;
    document.getElementById('lbl_extras').textContent     = fmt(extras);
    document.getElementById('lbl_descuentos').textContent = fmt(descuentos);
    document.getElementById('lbl_neto').textContent       = fmt(neto);
}

function agregarItem() {
    itemCount++;
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.id = 'item-' + itemCount;

    const tipoOpts = Object.entries(TIPOS)
        .map(([k,v]) => `<option value="${k}">${v}</option>`).join('');

    row.innerHTML = `
        <td>
            <select name="item_tipo[]" class="item-tipo" onchange="recalcular()" style="min-width:110px">
                ${tipoOpts}
            </select>
        </td>
        <td>
            <input type="text" name="item_desc[]" placeholder="Descripción..." style="width:100%;min-width:180px">
        </td>
        <td>
            <input type="number" name="item_monto[]" class="item-monto"
                   step="0.01" min="0" placeholder="0.00" oninput="recalcular()"
                   style="width:140px">
        </td>
        <td>
            <button type="button" class="btn-ic btn-danger btn-icon btn-sm"
                    onclick="eliminarItem(${itemCount})">
                <i class="fa fa-trash"></i>
            </button>
        </td>
    `;

    document.getElementById('tbody-items').appendChild(row);
    document.getElementById('row-vacio').style.display = 'none';
    recalcular();
}

function eliminarItem(id) {
    const row = document.getElementById('item-' + id);
    if (row) row.remove();
    if (document.querySelectorAll('.item-row').length === 0) {
        document.getElementById('row-vacio').style.display = '';
    }
    recalcular();
}

document.addEventListener('DOMContentLoaded', function() {
    consultarCobros();
    recalcular();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
