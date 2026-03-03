<?php
// ============================================================
// admin/rendiciones.php — Aprobación de rendiciones
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo = obtener_conexion();
$fecha_sel = $_GET['fecha'] ?? date('Y-m-d', strtotime('-1 day'));
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);

// Aprobar todo o individual
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'aprobar_todo') {
        $cob_id = (int) $_POST['cobrador_id'];
        $fecha = $_POST['fecha'];
        $res = aprobar_rendicion($cob_id, $fecha, $_SESSION['user_id'], $pdo);
        if ($res['aprobados'] > 0) {
            registrar_log($pdo, $_SESSION['user_id'], 'RENDICION_APROBADA', 'cobrador', $cob_id,
                'Aprobados: ' . $res['aprobados'] . ' pagos | Fecha: ' . $fecha);
        }
        $_SESSION['flash'] = [
            'type' => $res['errores'] === 0 ? 'success' : 'warning',
            'msg' => "Aprobados: {$res['aprobados']} — Errores: {$res['errores']}"
        ];
    } elseif ($accion === 'rechazar' && !empty($_POST['pago_id'])) {
        $pago_id = (int) $_POST['pago_id'];
        $pdo->prepare("UPDATE ic_pagos_temporales SET estado='RECHAZADO' WHERE id=?")
            ->execute([$pago_id]);
        registrar_log($pdo, $_SESSION['user_id'], 'PAGO_RECHAZADO', 'pago_temporal', $pago_id);
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Pago rechazado.'];
    }
    header('Location: rendiciones.php?fecha=' . urlencode($fecha_sel) . '&cobrador_id=' . $cobrador_id);
    exit;
}

// Cobradores con pagos pendientes en la fecha seleccionada
$cobradores_con_pend = $pdo->prepare("
    SELECT DISTINCT u.id, u.nombre, u.apellido,
           COUNT(pt.id) AS cant_pagos,
           SUM(pt.monto_total) AS total
    FROM ic_pagos_temporales pt
    JOIN ic_usuarios u ON pt.cobrador_id = u.id
    WHERE DATE(pt.fecha_registro) = ? AND pt.estado = 'PENDIENTE'
    GROUP BY u.id ORDER BY u.apellido
");
$cobradores_con_pend->execute([$fecha_sel]);
$cobradores_pend = $cobradores_con_pend->fetchAll();

// Detalle del cobrador seleccionado
$detalle_pagos = [];
if ($cobrador_id) {
    $dstmt = $pdo->prepare("
        SELECT pt.*,
               cl.nombres, cl.apellidos,
               cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE pt.cobrador_id = ? AND DATE(pt.fecha_registro) = ? AND pt.estado = 'PENDIENTE'
        ORDER BY pt.fecha_registro
    ");
    $dstmt->execute([$cobrador_id, $fecha_sel]);
    $detalle_pagos = $dstmt->fetchAll();
}

$page_title = 'Rendiciones';
$page_current = 'rendiciones';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- Selector de fecha -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Fecha de
                cobranza</label>
            <input type="date" name="fecha" value="<?= e($fecha_sel) ?>" style="min-width:180px">
        </div>
        <input type="hidden" name="cobrador_id" value="0">
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Ver</button>
    </form>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start">

    <!-- LISTA DE COBRADORES -->
    <div class="card-ic">
        <div class="card-title mb-3"><i class="fa fa-users"></i> Cobradores con Pendientes</div>
        <?php if (empty($cobradores_pend)): ?>
            <p class="text-muted text-center" style="padding:20px">Sin rendiciones pendientes para esta fecha.</p>
        <?php else: ?>
            <?php foreach ($cobradores_pend as $cob): ?>
                <a href="?fecha=<?= urlencode($fecha_sel) ?>&cobrador_id=<?= $cob['id'] ?>"
                    style="display:block;padding:12px;border-radius:8px;margin-bottom:6px;transition:.2s;
              <?= $cobrador_id === $cob['id'] ? 'background:rgba(79,70,229,.2);border-left:3px solid var(--primary)' : 'background:rgba(0,0,0,.2)' ?>">
                    <div class="fw-bold">
                        <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                    </div>
                    <div class="text-muted" style="font-size:.78rem">
                        <?= $cob['cant_pagos'] ?> pago
                        <?= $cob['cant_pagos'] !== 1 ? 's' : '' ?> —
                        <strong>
                            <?= formato_pesos($cob['total']) ?>
                        </strong>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- DETALLE -->
    <div>
        <?php if ($cobrador_id && !empty($detalle_pagos)): ?>
<?php
    $total_efectivo      = array_sum(array_column($detalle_pagos, 'monto_efectivo'));
    $total_transferencia = array_sum(array_column($detalle_pagos, 'monto_transferencia'));
    $total_mora          = array_sum(array_column($detalle_pagos, 'monto_mora_cobrada'));
    $total_general       = array_sum(array_column($detalle_pagos, 'monto_total'));
    // Nombre del cobrador para el encabezado del PDF
    $nombre_cobrador = '';
    foreach ($cobradores_pend as $c) {
        if ($c['id'] === $cobrador_id) {
            $nombre_cobrador = $c['nombre'] . ' ' . $c['apellido'];
            break;
        }
    }
?>
            <div class="card-ic">
                <div class="card-ic-header">
                    <span class="card-title"><i class="fa fa-list"></i> Detalle de Pagos</span>
                    <div style="display:flex;gap:10px;align-items:center">
                        <span class="text-muted" style="font-size:.82rem">Total:
                            <strong><?= formato_pesos($total_general) ?></strong>
                        </span>
                        <a href="rendicion_pdf.php?fecha=<?= urlencode($fecha_sel) ?>&cobrador_id=<?= $cobrador_id ?>"
                           class="btn-ic btn-ghost btn-sm no-print" target="_blank">
                            <i class="fa fa-file-pdf"></i> Exportar PDF
                        </a>
                    </div>
                </div>

                <!-- Encabezado visible solo en impresión -->
                <div class="print-header" style="display:none">
                    <h2 style="margin:0 0 4px">💼 Imperio Comercial — Rendición</h2>
                    <p style="margin:0;font-size:.9rem">
                        Cobrador: <strong><?= e($nombre_cobrador) ?></strong>
                        &nbsp;|&nbsp; Fecha: <strong><?= date('d/m/Y', strtotime($fecha_sel)) ?></strong>
                    </p>
                    <hr>
                </div>

                <div style="overflow-x:auto">
                    <table class="table-ic" id="tabla-rendicion">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Cuota</th>
                                <th>Artículo</th>
                                <th>Efectivo</th>
                                <th>Transf.</th>
                                <th>Mora</th>
                                <th>Total</th>
                                <th class="no-print"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detalle_pagos as $p): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <?= e($p['apellidos'] . ', ' . $p['nombres']) ?>
                                    </td>
                                    <td>#<?= $p['numero_cuota'] ?> — <?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?></td>
                                    <td><?= e($p['articulo']) ?></td>
                                    <td class="nowrap"><?= formato_pesos($p['monto_efectivo']) ?></td>
                                    <td class="nowrap"><?= formato_pesos($p['monto_transferencia']) ?></td>
                                    <td class="nowrap <?= $p['monto_mora_cobrada'] > 0 ? 'text-warning' : '' ?>">
                                        <?= formato_pesos($p['monto_mora_cobrada']) ?>
                                    </td>
                                    <td class="nowrap fw-bold"><?= formato_pesos($p['monto_total']) ?></td>
                                    <td class="no-print">
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <input type="hidden" name="pago_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="fecha" value="<?= e($fecha_sel) ?>">
                                            <button type="submit" class="btn-ic btn-danger btn-sm"
                                                data-confirm="¿Rechazar este pago?">
                                                <i class="fa fa-times"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:rgba(79,70,229,.15);font-weight:700">
                                <td colspan="3" style="text-align:right;padding-right:12px;font-size:.82rem;letter-spacing:.05em">TOTALES</td>
                                <td class="nowrap" style="color:var(--success)"><?= formato_pesos($total_efectivo) ?></td>
                                <td class="nowrap" style="color:var(--primary-light)"><?= formato_pesos($total_transferencia) ?></td>
                                <td class="nowrap" style="color:var(--warning)"><?= formato_pesos($total_mora) ?></td>
                                <td class="nowrap" style="color:var(--accent);font-size:1.05rem"><?= formato_pesos($total_general) ?></td>
                                <td class="no-print"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <hr class="divider no-print">
                <form method="POST" class="no-print">
                    <input type="hidden" name="accion" value="aprobar_todo">
                    <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
                    <input type="hidden" name="fecha" value="<?= e($fecha_sel) ?>">
                    <button type="submit" class="btn-ic btn-success"
                        data-confirm="¿Aprobar TODOS los <?= count($detalle_pagos) ?> pagos de este cobrador?">
                        <i class="fa fa-check-double"></i> Aprobar Todo (<?= count($detalle_pagos) ?> pagos)
                    </button>
                </form>
            </div>
        <?php elseif ($cobrador_id): ?>
            <div class="card-ic">
                <p class="text-muted text-center" style="padding:30px">Sin pagos pendientes para este cobrador.</p>
            </div>
        <?php else: ?>
            <div class="card-ic">
                <p class="text-muted text-center" style="padding:30px">← Seleccioná un cobrador para ver el detalle.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>