<?php
// ============================================================
// admin/historial_rendiciones_ver.php — Ver detalle de rendición
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo = obtener_conexion();

$fecha_rendicion = $_GET['fecha'] ?? '';
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);

if (!$fecha_rendicion || !$cobrador_id) {
    header('Location: historial_rendiciones');
    exit;
}

// Datos del cobrador
$stmtCob = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$stmtCob->execute([$cobrador_id]);
$cobrador = $stmtCob->fetch();

if (!$cobrador) {
    die("Cobrador no encontrado.");
}

// Obtener el detalle individual de pagos de esa fecha y cobrador
$dstmt = $pdo->prepare("
    SELECT pt.*,
           cl.nombres, cl.apellidos,
           cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS apr_nombre, u.apellido AS apr_apellido
    FROM ic_pagos_confirmados pt
    JOIN ic_cuotas cu ON pt.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    LEFT JOIN ic_usuarios u ON pt.aprobador_id = u.id
    WHERE pt.cobrador_id = ? AND DATE(pt.fecha_aprobacion) = ?
    ORDER BY pt.fecha_pago ASC
");
$dstmt->execute([$cobrador_id, $fecha_rendicion]);
$detalle_pagos = $dstmt->fetchAll();

$total_efectivo = 0;
$total_transferencia = 0;
foreach ($detalle_pagos as $p) {
    $total_efectivo += (float) $p['monto_efectivo'];
    $total_transferencia += (float) $p['monto_transferencia'];
}
$total_rendido = $total_efectivo + $total_transferencia;
$aprobador_nombre = $detalle_pagos[0]['apr_nombre'] ?? '';
$aprobador_apellido = $detalle_pagos[0]['apr_apellido'] ?? '';

$page_title = 'Detalle de Rendición';
$page_current = 'rendiciones';
$topbar_actions = '<a href="historial_rendiciones" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver al Historial</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="kpi-grid mb-4">
    <div class="kpi-card" style="var(--kpi-color:var(--text-main))">
        <i class="fa fa-user-tag kpi-icon"></i>
        <div class="kpi-label">Cobrador</div>
        <div class="kpi-value"><?= e($cobrador['apellido'] . ' ' . $cobrador['nombre']) ?></div>
    </div>
    <div class="kpi-card" style="var(--kpi-color:var(--text-main))">
        <i class="fa fa-calendar-check kpi-icon"></i>
        <div class="kpi-label">Fecha de Aprobación</div>
        <div class="kpi-value text-primary"><?= date('d/m/Y', strtotime($fecha_rendicion)) ?></div>
    </div>
    <div class="kpi-card" style="var(--kpi-color:var(--text-main))">
        <i class="fa fa-user-shield kpi-icon"></i>
        <div class="kpi-label">Aprobado Por</div>
        <div class="kpi-value text-muted"><?= $aprobador_nombre ? e($aprobador_nombre . ' ' . $aprobador_apellido) : '—' ?></div>
    </div>
    <div class="kpi-card" style="var(--kpi-color:var(--success))">
        <i class="fa fa-hand-holding-dollar kpi-icon text-success"></i>
        <div class="kpi-label">Total Validado</div>
        <div class="kpi-value text-success"><?= formato_pesos($total_rendido) ?></div>
        <div class="kpi-sub" style="font-size:0.8rem">
            Ef: <?= formato_pesos($total_efectivo) ?> | Tr: <?= formato_pesos($total_transferencia) ?>
        </div>
    </div>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <h3 class="card-title">Comprobantes (<?= count($detalle_pagos) ?> recibos)</h3>
    </div>
    
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Fecha Cobro</th>
                    <th>Cliente</th>
                    <th>Artículo</th>
                    <th class="text-center">Cuota</th>
                    <th class="text-right">Efectivo</th>
                    <th class="text-right">Transferencia</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detalle_pagos)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:24px">No hay pagos en esta rendición.</td></tr>
                <?php else: ?>
                    <?php foreach ($detalle_pagos as $dp): ?>
                        <tr>
                            <td class="text-muted nowrap">
                                <?= date('d/m/Y H:i', strtotime($dp['fecha_pago'])) ?>
                            </td>
                            <td>
                                <div class="fw-bold"><?= e($dp['apellidos'] . ', ' . $dp['nombres']) ?></div>
                            </td>
                            <td class="text-muted"><?= e($dp['articulo']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary">#<?= $dp['numero_cuota'] ?></span>
                            </td>
                            <td class="text-right">
                                <?= $dp['monto_efectivo'] > 0 ? formato_pesos($dp['monto_efectivo']) : '—' ?>
                            </td>
                            <td class="text-right">
                                <?= $dp['monto_transferencia'] > 0 ? formato_pesos($dp['monto_transferencia']) : '—' ?>
                            </td>
                            <td class="text-right fw-bold" style="color:var(--success)">
                                <?= formato_pesos($dp['monto_total']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
