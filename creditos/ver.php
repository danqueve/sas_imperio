<?php
// ============================================================
// creditos/ver.php — Cronograma de cuotas de un crédito
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index');
    exit;
}

$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono, cl.id AS cid,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a,
           v.nombre AS vendedor_n, v.apellido AS vendedor_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    LEFT JOIN ic_usuarios v ON cr.vendedor_id=v.id
    WHERE cr.id=?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) {
    header('Location: index');
    exit;
}

$cuotas = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas->execute([$id]);
$lista_cuotas = $cuotas->fetchAll();

// Mapa cuota_id → pago confirmado (id, solicitud_baja, motivo_baja) para acciones admin/supervisor
$conf_map = [];
if (es_admin() || es_supervisor()) {
    $cuota_ids = array_column($lista_cuotas, 'id');
    if ($cuota_ids) {
        $placeholders = implode(',', array_fill(0, count($cuota_ids), '?'));
        $conf_rows = $pdo->prepare("
            SELECT id AS pc_id, cuota_id, solicitud_baja, motivo_baja
            FROM ic_pagos_confirmados WHERE cuota_id IN ($placeholders) ORDER BY id DESC
        ");
        $conf_rows->execute($cuota_ids);
        foreach ($conf_rows->fetchAll() as $pc) {
            $cid = (int) $pc['cuota_id'];
            if (!isset($conf_map[$cid])) $conf_map[$cid] = $pc; // solo el más reciente
        }
    }
}

// Calcular mora actualizada para cada cuota
$hoy = new DateTime('today');
foreach ($lista_cuotas as &$cuota) {
    if ($cuota['estado'] === 'PENDIENTE' || $cuota['estado'] === 'VENCIDA') {
        $dias = dias_atraso_habiles($cuota['fecha_vencimiento']);
        $cuota['dias_atraso_calc'] = $dias;
        $cuota['mora_calc'] = calcular_mora($cuota['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
    } else {
        $cuota['dias_atraso_calc'] = 0;
        $cuota['mora_calc'] = 0;
    }
}
unset($cuota);

$pagadas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PAGADA'));
$pendientes = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PENDIENTE'));
$vencidas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'VENCIDA' || ($c['estado'] === 'PENDIENTE' && $c['dias_atraso_calc'] > 0)));
$total_cuotas = count($lista_cuotas);
$porc = $total_cuotas > 0 ? round($pagadas / $total_cuotas * 100) : 0;

$total_mora_pendiente = array_sum(array_map(fn($c) => $c['mora_calc'], $lista_cuotas));
$deuda_pendiente = array_sum(array_map(fn($c) => $c['estado'] !== 'PAGADA' ? $c['monto_cuota'] : 0, $lista_cuotas));

$page_title   = 'Crédito #' . $id;
$page_current = 'creditos';

$topbar_actions = '';
if (es_admin()) {
    $topbar_actions .= '<a href="editar?id=' . $id . '" class="btn-ic btn-ghost btn-sm"><i class="fa fa-edit"></i> Editar</a> ';
}
if ((es_admin() || es_supervisor()) && in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $topbar_actions .= '<a href="refinanciar?id=' . $id . '" class="btn-ic btn-primary btn-sm"><i class="fa fa-sync-alt"></i> Refinanciar</a>';
}

require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <i class="fa fa-box-open kpi-icon"></i>
        <div class="kpi-label">Artículo</div>
        <div class="kpi-value" style="font-size:1rem;margin-top:8px">
            <?= e($cr['articulo']) ?>
        </div>
        <div class="kpi-sub">Crédito #
            <?= $id ?> —
            <?= badge_estado_credito($cr['estado']) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <i class="fa fa-check-circle kpi-icon"></i>
        <div class="kpi-label">Pagadas</div>
        <div class="kpi-value">
            <?= $pagadas ?>/
            <?= $total_cuotas ?>
        </div>
        <div class="kpi-sub">
            <?= $porc ?>% completado
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <i class="fa fa-clock kpi-icon"></i>
        <div class="kpi-label">Deuda Capital</div>
        <div class="kpi-value" style="font-size:1.3rem">
            <?= formato_pesos($deuda_pendiente) ?>
        </div>
        <div class="kpi-sub">
            <?= $pendientes ?> cuota
            <?= $pendientes !== 1 ? 's' : '' ?> pendiente
            <?= $pendientes !== 1 ? 's' : '' ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <i class="fa fa-fire kpi-icon"></i>
        <div class="kpi-label">Mora Acumulada</div>
        <div class="kpi-value" style="font-size:1.3rem;color:var(--danger)">
            <?= formato_pesos($total_mora_pendiente) ?>
        </div>
        <div class="kpi-sub">
            <?= $vencidas ?> cuota
            <?= $vencidas !== 1 ? 's' : '' ?> vencida
            <?= $vencidas !== 1 ? 's' : '' ?>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">

    <!-- SIDEBAR DEL CRÉDITO -->
    <div>
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title">Datos del Crédito</span></div>
            <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                <tr>
                    <td class="text-muted" style="padding:5px 0;width=45%">Cliente</td>
                    <td><a href="../clientes/ver?id=<?= $cr['cid'] ?>" class="fw-bold">
                            <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                        </a></td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Artículo</td>
                    <td>
                        <?= e($cr['articulo']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Precio art.</td>
                    <td>
                        <?= formato_pesos($cr['precio_articulo']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Interés</td>
                    <td>
                        <?= $cr['interes_pct'] ?>%
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Monto total</td>
                    <td class="fw-bold">
                        <?= formato_pesos($cr['monto_total']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Monto cuota</td>
                    <td>
                        <?= formato_pesos($cr['monto_cuota']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cuotas</td>
                    <td>
                        <?= $cr['cant_cuotas'] ?> (
                        <?= $cr['frecuencia'] ?>)
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Mora/sem.</td>
                    <td>
                        <?= $cr['interes_moratorio_pct'] ?>%
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Alta</td>
                    <td>
                        <?= date('d/m/Y', strtotime($cr['fecha_alta'])) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">1er venc.</td>
                    <td>
                        <?= date('d/m/Y', strtotime($cr['primer_vencimiento'])) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cobrador</td>
                    <td>
                        <?= e($cr['cobrador_n'] . ' ' . $cr['cobrador_a']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Vendedor</td>
                    <td>
                        <?= isset($cr['vendedor_n']) ? e($cr['vendedor_n'] . ' ' . $cr['vendedor_a']) : '<span class="text-muted">No asignado</span>' ?>
                        <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                            <a href="cambiar_vendedor?id=<?= $id ?>" class="btn-ic btn-ghost btn-sm" title="Cambiar vendedor" style="padding:2px 5px; font-size:.7rem; margin-left:10px;"><i class="fa fa-edit"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($cr['veces_refinanciado']) && (int)$cr['veces_refinanciado'] > 0): ?>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Refinanciaciones</td>
                    <td>
                        <span class="badge-ic badge-warning"><?= (int)$cr['veces_refinanciado'] ?> vez<?= (int)$cr['veces_refinanciado'] > 1 ? 'ces' : '' ?></span>
                        <?php if (!empty($cr['fecha_ultima_refinanciacion'])): ?>
                            <span class="text-muted" style="font-size:.78rem;margin-left:6px">
                                Última: <?= date('d/m/Y', strtotime($cr['fecha_ultima_refinanciacion'])) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <hr class="divider">
            <!-- Barra de progreso -->
            <div class="text-muted mb-2" style="font-size:.75rem">
                <?= $porc ?>% pagado
            </div>
            <div style="background:var(--dark-border);border-radius:6px;height:8px">
                <div
                    style="width:<?= $porc ?>%;height:100%;background:var(--success);border-radius:6px;transition:width .4s">
                </div>
            </div>
            <hr class="divider">
            <div class="d-flex gap-2">
                <a href="imprimir_cronograma?id=<?= $id ?>" target="_blank" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-print"></i> PDF
                </a>
                <a href="../clientes/ver?id=<?= $cr['cid'] ?>" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-user"></i> Cliente
                </a>
                <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                    <a href="finalizar?id=<?= $id ?>" class="btn-ic btn-danger btn-sm" title="Finalizar Crédito">
                        <i class="fa fa-power-off"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- CRONOGRAMA -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-calendar-alt"></i> Cronograma de Cuotas</span>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Vencimiento</th>
                        <th>Monto Cuota</th>
                        <th>Días Atraso</th>
                        <th>Mora</th>
                        <th>Total a Pagar</th>
                        <th>Estado</th>
                        <th>Pago</th>
                        <?php if (es_admin() || es_supervisor()): ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_cuotas as $q): ?>
                        <?php
                        $esAtrasada = $q['dias_atraso_calc'] > 0;
                        $pc_info    = $conf_map[$q['id']] ?? null;
                        $pc_id      = $pc_info ? (int) $pc_info['pc_id'] : 0;
                        $sol_baja   = $pc_info ? (int) $pc_info['solicitud_baja'] : 0;
                        $mot_baja   = $pc_info ? $pc_info['motivo_baja'] : '';
                        $rowStyle   = '';
                        if ($sol_baja && $q['estado'] === 'PAGADA')
                            $rowStyle = 'background:rgba(245,158,11,.07);border-left:3px solid var(--warning)';
                        elseif ($esAtrasada && $q['estado'] !== 'PAGADA')
                            $rowStyle = 'background:rgba(239,68,68,.05)';
                        ?>
                        <tr style="<?= $rowStyle ?>">
                            <td class="text-muted">
                                <?= $q['numero_cuota'] ?>
                            </td>
                            <td class="nowrap <?= $esAtrasada && $q['estado'] !== 'PAGADA' ? 'text-danger' : '' ?>">
                                <?= date('d/m/Y', strtotime($q['fecha_vencimiento'])) ?>
                            </td>
                            <td class="nowrap">
                                <?= formato_pesos($q['monto_cuota']) ?>
                            </td>
                            <td class="text-center <?= $q['dias_atraso_calc'] > 0 ? 'text-warning' : '' ?>">
                                <?= $q['dias_atraso_calc'] > 0 ? $q['dias_atraso_calc'] . ' hábiles' : '—' ?>
                            </td>
                            <td class="nowrap <?= $q['mora_calc'] > 0 ? 'text-danger' : '' ?>">
                                <?= $q['mora_calc'] > 0 ? formato_pesos($q['mora_calc']) : '—' ?>
                            </td>
                            <td class="nowrap fw-bold">
                                <?= formato_pesos($q['monto_cuota'] + $q['mora_calc']) ?>
                            </td>
                            <td>
                                <?php $badgeMap = ['PENDIENTE' => 'badge-warning', 'PAGADA' => 'badge-success', 'VENCIDA' => 'badge-danger', 'PARCIAL' => 'badge-primary']; ?>
                                <span class="badge-ic <?= $badgeMap[$q['estado']] ?? 'badge-muted' ?>">
                                    <?= $q['estado'] ?>
                                </span>
                                <?php if ($sol_baja && $q['estado'] === 'PAGADA'): ?>
                                    <br><span class="badge-ic badge-warning" style="font-size:.65rem;margin-top:2px"
                                        title="<?= e($mot_baja) ?>"><i class="fa fa-flag"></i> Solicitud baja</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?= $q['fecha_pago'] ? date('d/m/Y', strtotime($q['fecha_pago'])) : '—' ?>
                            </td>
                            <?php if (es_admin() || es_supervisor()): ?>
                            <td class="nowrap" style="display:flex;gap:4px;align-items:center">
                                <?php if (in_array($q['estado'], ['PENDIENTE', 'VENCIDA'])): ?>
                                    <button
                                        onclick="abrirPagoDirecto(<?= $q['id'] ?>, <?= $q['numero_cuota'] ?>, <?= (float)$q['monto_cuota'] ?>, <?= (float)$q['mora_calc'] ?>, <?= (float)($q['monto_cuota'] + $q['mora_calc']) ?>)"
                                        class="btn-ic btn-success btn-sm" title="Registrar pago directo">
                                        <i class="fa fa-dollar-sign"></i>
                                    </button>
                                <?php elseif ($q['estado'] === 'PAGADA' && $pc_id): ?>
                                    <?php if (es_admin()): ?>
                                        <button
                                            onclick="abrirRevertir(<?= $pc_id ?>, <?= $q['numero_cuota'] ?>, <?= $sol_baja ?>)"
                                            class="btn-ic btn-sm <?= $sol_baja ? 'btn-warning' : 'btn-danger' ?>"
                                            title="<?= $sol_baja ? 'Reversa solicitada — Revertir' : 'Revertir pago' ?>">
                                            <i class="fa fa-undo"></i>
                                        </button>
                                    <?php elseif (es_supervisor()): ?>
                                        <?php if (!$sol_baja): ?>
                                            <button
                                                onclick="abrirSolBaja(<?= $pc_id ?>, <?= $q['numero_cuota'] ?>)"
                                                class="btn-ic btn-warning btn-sm" title="Solicitar reversa">
                                                <i class="fa fa-flag"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-warning" style="font-size:.75rem" title="Solicitud enviada — pendiente de admin">
                                                <i class="fa fa-clock"></i>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (es_admin()): ?>
<!-- MODAL REVERTIR PAGO (admin) -->
<div class="modal-overlay" id="modal-revertir">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-undo"></i> Revertir Pago</div>
            <button class="modal-close" onclick="closeModal('modal-revertir')">✕</button>
        </div>
        <div id="info-revertir"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="gestionar_pago" class="form-ic">
            <input type="hidden" name="accion" value="revertir_confirmado">
            <input type="hidden" name="pago_conf_id" id="rev_pc_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-danger w-100" style="justify-content:center">
                    <i class="fa fa-undo"></i> Confirmar Reversa
                </button>
                <button type="button" onclick="closeModal('modal-revertir')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php elseif (es_supervisor()): ?>
<!-- MODAL SOLICITAR REVERSA (supervisor) -->
<div class="modal-overlay" id="modal-sol-baja-conf">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-flag"></i> Solicitar Reversa de Pago</div>
            <button class="modal-close" onclick="closeModal('modal-sol-baja-conf')">✕</button>
        </div>
        <div id="info-sol-baja"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:14px;font-size:.875rem"></div>
        <form method="POST" action="gestionar_pago" class="form-ic">
            <input type="hidden" name="accion" value="solicitar_baja_confirmado">
            <input type="hidden" name="pago_conf_id" id="sol_pc_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="form-group mb-4">
                <label>Motivo *</label>
                <textarea name="motivo" rows="3" required
                    placeholder="Ej: Pago duplicado, error de importe, cliente equivocado..."
                    style="resize:vertical"></textarea>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-warning w-100" style="justify-content:center">
                    <i class="fa fa-paper-plane"></i> Enviar Solicitud
                </button>
                <button type="button" onclick="closeModal('modal-sol-baja-conf')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (es_admin() || es_supervisor()): ?>
<!-- MODAL PAGO DIRECTO -->
<div class="modal-overlay" id="modal-pago-directo">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-dollar-sign"></i> Registrar Pago</div>
            <button class="modal-close" onclick="closeModal('modal-pago-directo')">✕</button>
        </div>
        <div id="info-pago-dir"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="pagar_cuota" class="form-ic">
            <input type="hidden" name="cuota_id" id="dir_cuota_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Efectivo $</label>
                    <input type="number" name="monto_efectivo" id="dir_efectivo"
                        step="0.01" min="0" value="0" oninput="dirTotal()">
                </div>
                <div class="form-group">
                    <label>Transferencia $</label>
                    <input type="number" name="monto_transferencia" id="dir_transfer"
                        step="0.01" min="0" value="0" oninput="dirTotal()">
                </div>
            </div>
            <div style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <span class="text-muted">Total:</span>
                <span id="dir_total" style="font-size:1.2rem;font-weight:800;color:var(--success)">$ 0,00</span>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-success w-100" style="justify-content:center">
                    <i class="fa fa-save"></i> Confirmar Pago
                </button>
                <button type="button" onclick="closeModal('modal-pago-directo')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$page_scripts = <<<'JS'
<script>
function abrirPagoDirecto(cuota_id, num_cuota, capital, mora, total) {
    document.getElementById('dir_cuota_id').value = cuota_id;
    document.getElementById('dir_efectivo').value = total.toFixed(2);
    document.getElementById('dir_transfer').value = '0';
    document.getElementById('info-pago-dir').innerHTML =
        'Cuota <strong>#' + num_cuota + '</strong><br>' +
        'Capital: ' + formatPesos(capital) +
        (mora > 0 ? ' + Mora: <span style="color:var(--danger)">' + formatPesos(mora) + '</span>' : '') +
        '<br><strong>Total sugerido: ' + formatPesos(total) + '</strong>';
    dirTotal();
    openModal('modal-pago-directo');
}

function dirTotal() {
    const ef = parseFloat(document.getElementById('dir_efectivo').value) || 0;
    const tr = parseFloat(document.getElementById('dir_transfer').value) || 0;
    document.getElementById('dir_total').textContent = formatPesos(ef + tr);
}

// Admin: revertir pago confirmado
function abrirRevertir(pc_id, num_cuota, sol_baja) {
    document.getElementById('rev_pc_id').value = pc_id;
    document.getElementById('info-revertir').innerHTML =
        'Se revertirá el pago de la <strong>Cuota #' + num_cuota + '</strong>.' +
        (sol_baja ? '<br><span style="color:var(--warning)"><i class="fa fa-flag"></i> Hay una solicitud de reversa del supervisor.</span>' : '') +
        '<br><span style="color:var(--danger);font-size:.82rem">La cuota volverá a PENDIENTE o VENCIDA y el pago quedará anulado.</span>';
    openModal('modal-revertir');
}

// Supervisor: solicitar reversa de pago confirmado
function abrirSolBaja(pc_id, num_cuota) {
    document.getElementById('sol_pc_id').value = pc_id;
    document.getElementById('info-sol-baja').innerHTML =
        'Solicitar reversa del pago de la <strong>Cuota #' + num_cuota + '</strong>.<br>' +
        '<span style="font-size:.82rem;color:var(--text-muted)">El administrador revisará la solicitud y decidirá si revierte el pago.</span>';
    openModal('modal-sol-baja-conf');
}
</script>
JS;
?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>