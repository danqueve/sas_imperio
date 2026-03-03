<?php
// ============================================================
// admin/liquidacion_ver.php — Detalle de Liquidación
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: liquidaciones.php'); exit; }

// Cargar liquidación
$stmt = $pdo->prepare("
    SELECT liq.*,
           u.nombre AS cob_nombre, u.apellido AS cob_apellido, u.telefono AS cob_tel,
           a.nombre AS apr_nombre, a.apellido AS apr_apellido
    FROM ic_liquidaciones liq
    JOIN ic_usuarios u ON liq.cobrador_id = u.id
    LEFT JOIN ic_usuarios a ON liq.aprobado_by = a.id
    WHERE liq.id = ?
");
$stmt->execute([$id]);
$liq = $stmt->fetch();
if (!$liq) { header('Location: liquidaciones.php'); exit; }

// Ítems de la liquidación
$items_stmt = $pdo->prepare("SELECT * FROM ic_liquidacion_items WHERE liquidacion_id=? ORDER BY tipo, id");
$items_stmt->execute([$id]);
$items = $items_stmt->fetchAll();

// Cobros del período por día
$cobros_stmt = $pdo->prepare("
    SELECT DATE(pc.fecha_pago) AS dia,
           SUM(pc.monto_efectivo) AS efectivo,
           SUM(pc.monto_transferencia) AS transferencia,
           SUM(pc.monto_mora_cobrada) AS mora,
           SUM(pc.monto_total) AS subtotal,
           COUNT(*) AS cant_pagos
    FROM ic_pagos_confirmados pc
    WHERE pc.cobrador_id = ?
      AND pc.fecha_pago BETWEEN ? AND ?
    GROUP BY DATE(pc.fecha_pago)
    ORDER BY dia
");
$cobros_stmt->execute([$liq['cobrador_id'], $liq['fecha_desde'], $liq['fecha_hasta']]);
$cobros_dias = $cobros_stmt->fetchAll();

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'aprobar' && $liq['estado'] === 'BORRADOR') {
        $pdo->prepare("
            UPDATE ic_liquidaciones SET estado='APROBADA', aprobado_by=?, aprobado_at=NOW() WHERE id=?
        ")->execute([$_SESSION['user_id'], $id]);
        registrar_log($pdo, $_SESSION['user_id'], 'LIQUIDACION_APROBADA', 'liquidacion', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Liquidación aprobada.'];

    } elseif ($accion === 'marcar_pagada' && $liq['estado'] === 'APROBADA') {
        $pdo->prepare("
            UPDATE ic_liquidaciones SET estado='PAGADA', pagado_at=NOW() WHERE id=?
        ")->execute([$id]);
        registrar_log($pdo, $_SESSION['user_id'], 'LIQUIDACION_PAGADA', 'liquidacion', $id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Liquidación marcada como pagada.'];

    } elseif ($accion === 'eliminar' && $liq['estado'] === 'BORRADOR') {
        $pdo->prepare("DELETE FROM ic_liquidaciones WHERE id=?")->execute([$id]);
        registrar_log($pdo, $_SESSION['user_id'], 'LIQUIDACION_ELIMINADA', 'liquidacion', $id);
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Liquidación eliminada.'];
        header('Location: liquidaciones.php');
        exit;
    }

    header("Location: liquidacion_ver.php?id=$id");
    exit;
}

$badge_map   = ['BORRADOR' => 'warning', 'APROBADA' => 'primary', 'PAGADA' => 'success'];
$badge_color = $badge_map[$liq['estado']] ?? 'secondary';

$page_title   = 'Liquidación #' . $id;
$page_current = 'liquidaciones';

// Botones en topbar
$topbar_actions  = '<button onclick="window.print()" class="btn-ic btn-ghost btn-sm no-print"><i class="fa fa-print"></i> Imprimir</button>';
if ($liq['estado'] === 'BORRADOR') {
    $topbar_actions .= ' <form method="POST" style="display:inline" class="no-print">
        <input type="hidden" name="accion" value="aprobar">
        <button type="submit" class="btn-ic btn-primary btn-sm" data-confirm="¿Aprobar esta liquidación?">
            <i class="fa fa-check-circle"></i> Aprobar
        </button>
    </form>';
}
if ($liq['estado'] === 'APROBADA') {
    $topbar_actions .= ' <form method="POST" style="display:inline" class="no-print">
        <input type="hidden" name="accion" value="marcar_pagada">
        <button type="submit" class="btn-ic btn-success btn-sm" data-confirm="¿Marcar como pagada?">
            <i class="fa fa-money-bill-wave"></i> Marcar Pagada
        </button>
    </form>';
}

require_once __DIR__ . '/../views/layout.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .app-wrapper { display: block !important; }
    .sidebar, .topbar, .sidebar-backdrop { display: none !important; }
    .main-content { margin: 0 !important; padding: 20px !important; }
    body { background: white !important; color: black !important; }
    .card-ic { border: 1px solid #ccc !important; background: white !important; box-shadow: none !important; }
    .card-ic-header { border-bottom: 1px solid #ccc !important; background: #f5f5f5 !important; }
    .table-ic th, .table-ic td { border-bottom: 1px solid #ddd !important; color: black !important; }
    .badge { border: 1px solid #999 !important; color: #333 !important; background: #eee !important; }
    .print-logo { display: block !important; }
    .text-muted { color: #555 !important; }
}
.print-logo { display: none; margin-bottom: 16px; }
</style>

<!-- Encabezado solo para impresión -->
<div class="print-logo">
    <h2 style="margin:0 0 4px">💼 Imperio Comercial — Liquidación #<?= $id ?></h2>
    <p style="margin:0;font-size:.9rem;color:#555">
        Cobrador: <strong><?= e($liq['cob_nombre'] . ' ' . $liq['cob_apellido']) ?></strong>
        &nbsp;|&nbsp; Período:
        <strong><?= date('d/m/Y', strtotime($liq['fecha_desde'])) ?> al <?= date('d/m/Y', strtotime($liq['fecha_hasta'])) ?></strong>
    </p>
    <hr>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- ── Encabezado de la liquidación ── -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Liquidación #<?= $id ?></span>
        <span class="badge bg-<?= $badge_color ?>"><?= $liq['estado'] ?></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;padding:4px 0">
        <div>
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.7px">Cobrador</div>
            <div class="fw-bold" style="font-size:1.05rem"><?= e($liq['cob_nombre'] . ' ' . $liq['cob_apellido']) ?></div>
            <?php if ($liq['cob_tel']): ?>
                <div class="text-muted" style="font-size:.8rem"><?= e($liq['cob_tel']) ?></div>
            <?php endif; ?>
        </div>
        <div>
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.7px">Período</div>
            <div class="fw-bold"><?= date('d/m/Y', strtotime($liq['fecha_desde'])) ?> → <?= date('d/m/Y', strtotime($liq['fecha_hasta'])) ?></div>
        </div>
        <div>
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.7px">Creada</div>
            <div><?= date('d/m/Y H:i', strtotime($liq['created_at'])) ?></div>
        </div>
        <?php if ($liq['estado'] !== 'BORRADOR'): ?>
        <div>
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.7px">Aprobada por</div>
            <div><?= e($liq['apr_nombre'] . ' ' . $liq['apr_apellido']) ?></div>
            <div class="text-muted" style="font-size:.8rem"><?= date('d/m/Y H:i', strtotime($liq['aprobado_at'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($liq['pagado_at']): ?>
        <div>
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.7px">Pagada</div>
            <div><?= date('d/m/Y H:i', strtotime($liq['pagado_at'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Cobros del período ── -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-calendar-check"></i> Cobros del Período</span>
        <span class="text-muted" style="font-size:.82rem">Lun–Sáb</span>
    </div>
    <?php if (empty($cobros_dias)): ?>
        <p class="text-muted text-center" style="padding:24px">Sin cobros confirmados en este período.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th>Pagos</th>
                        <th>Efectivo</th>
                        <th>Transf.</th>
                        <th>Mora</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cobros_dias as $dia): ?>
                        <tr>
                            <td><?= date('l d/m/Y', strtotime($dia['dia'])) ?></td>
                            <td class="text-muted"><?= $dia['cant_pagos'] ?></td>
                            <td class="nowrap"><?= formato_pesos($dia['efectivo']) ?></td>
                            <td class="nowrap"><?= formato_pesos($dia['transferencia']) ?></td>
                            <td class="nowrap <?= $dia['mora'] > 0 ? 'text-warning' : 'text-muted' ?>"><?= formato_pesos($dia['mora']) ?></td>
                            <td class="nowrap fw-bold"><?= formato_pesos($dia['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:rgba(79,70,229,.15);font-weight:700">
                        <td colspan="5" style="text-align:right;padding-right:12px">TOTAL COBRADO</td>
                        <td class="nowrap" style="font-size:1.05rem;color:var(--primary-light)"><?= formato_pesos($liq['total_cobrado']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ── Ítems adicionales ── -->
<?php if (!empty($items)): ?>
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-list-ul"></i> Ítems Adicionales</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr><th>Tipo</th><th>Descripción</th><th>Monto</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <?php
                            $tipo_lbl = ['venta'=>'Venta','bonus'=>'Bonus','gasto'=>'Gasto','descuento'=>'Descuento','otro'=>'Otro'];
                            $tipo_color = in_array($it['tipo'], ['gasto','descuento']) ? 'var(--danger)' : 'var(--success)';
                            ?>
                            <span style="color:<?= $tipo_color ?>"><?= $tipo_lbl[$it['tipo']] ?? $it['tipo'] ?></span>
                        </td>
                        <td><?= e($it['descripcion']) ?></td>
                        <td class="nowrap fw-bold" style="color:<?= $it['monto'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                            <?= ($it['monto'] >= 0 ? '+' : '−') . formato_pesos(abs($it['monto'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Resumen financiero ── -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-calculator"></i> Resumen Financiero</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px;text-align:center">
            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Total Cobrado</div>
            <div style="font-size:1.3rem;font-weight:800;color:var(--primary-light);margin-top:6px"><?= formato_pesos($liq['total_cobrado']) ?></div>
        </div>
        <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px;text-align:center">
            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">Comisión <?= $liq['comision_pct'] ?>%</div>
            <div style="font-size:1.3rem;font-weight:800;color:var(--success);margin-top:6px"><?= formato_pesos($liq['comision_monto']) ?></div>
        </div>
        <?php if ($liq['total_extras'] > 0): ?>
        <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px;text-align:center">
            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">+ Extras / Ventas</div>
            <div style="font-size:1.3rem;font-weight:800;color:var(--success);margin-top:6px">+<?= formato_pesos($liq['total_extras']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($liq['total_descuentos'] > 0): ?>
        <div style="background:rgba(0,0,0,.3);border-radius:10px;padding:16px;text-align:center">
            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">− Gastos / Descuentos</div>
            <div style="font-size:1.3rem;font-weight:800;color:var(--danger);margin-top:6px">−<?= formato_pesos($liq['total_descuentos']) ?></div>
        </div>
        <?php endif; ?>
        <div style="background:rgba(79,70,229,.15);border-radius:10px;padding:16px;text-align:center;border:1px solid rgba(103,112,210,.4)">
            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.8px">NETO A PAGAR</div>
            <div style="font-size:1.6rem;font-weight:900;color:var(--accent);margin-top:6px"><?= formato_pesos($liq['total_neto']) ?></div>
        </div>
    </div>

    <?php if ($liq['observaciones']): ?>
        <div style="margin-top:16px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px;font-size:.88rem">
            <span class="text-muted"><i class="fa fa-comment"></i> </span><?= e($liq['observaciones']) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Acciones ─── -->
<div class="d-flex gap-3 no-print mb-4">
    <?php if ($liq['estado'] === 'BORRADOR'): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="accion" value="eliminar">
            <button type="submit" class="btn-ic btn-danger btn-sm" data-confirm="¿Eliminar este borrador?">
                <i class="fa fa-trash"></i> Eliminar Borrador
            </button>
        </form>
    <?php endif; ?>
    <a href="liquidaciones.php" class="btn-ic btn-ghost btn-sm">
        <i class="fa fa-arrow-left"></i> Volver al listado
    </a>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
