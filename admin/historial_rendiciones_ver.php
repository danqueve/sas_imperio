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
$cobrador_id     = (int) ($_GET['cobrador_id'] ?? 0);
$origen_sel      = in_array($_GET['origen'] ?? '', ['cobrador', 'manual']) ? $_GET['origen'] : 'cobrador';

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

// Obtener el detalle individual de pagos de esa fecha, cobrador y origen
$dstmt = $pdo->prepare("
    SELECT pc.*,
           cl.nombres, cl.apellidos, cl.id AS cliente_id,
           cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS apr_nombre, u.apellido AS apr_apellido,
           (SELECT COUNT(*)
            FROM ic_cuotas cu2
            JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
            WHERE cr2.cliente_id = cl.id
              AND cu2.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
              AND cr2.estado IN ('EN_CURSO','MOROSO')
              AND cu2.fecha_vencimiento < CURDATE()
              AND (cu2.monto_cuota - cu2.saldo_pagado) > 0
           ) AS cuotas_atrasadas_cliente
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu ON pc.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    LEFT JOIN ic_usuarios u ON pc.aprobador_id = u.id
    LEFT JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    WHERE pc.cobrador_id = ? AND DATE(pc.fecha_aprobacion) = ?
      AND IFNULL(pt.origen, 'cobrador') = ?
    ORDER BY pc.fecha_pago ASC
");
$dstmt->execute([$cobrador_id, $fecha_rendicion, $origen_sel]);
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
$topbar_actions = '<a href="historial_rendiciones" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver al Historial</a>'
    . ' <a href="historial_rendiciones_pdf?fecha=' . urlencode($fecha_rendicion) . '&cobrador_id=' . $cobrador_id . '&origen=' . urlencode($origen_sel) . '" target="_blank" class="btn-ic btn-danger btn-sm"><i class="fa fa-file-pdf"></i> PDF</a>';
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
        <i class="fa fa-<?= $origen_sel === 'manual' ? 'keyboard' : 'motorcycle' ?> kpi-icon"></i>
        <div class="kpi-label">Origen</div>
        <div class="kpi-value"><?= $origen_sel === 'manual' ? '<span style="color:#f59e0b">Manual (Admin)</span>' : '<span style="color:#3b82f6">Cobrador</span>' ?></div>
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
    
    <?php 
        $pagos_normal = [];
        $pagos_5plus  = [];
        foreach ($detalle_pagos as $p) {
            if ((int)$p['cuotas_atrasadas_cliente'] >= 5) {
                $pagos_5plus[] = $p;
            } else {
                $pagos_normal[] = $p;
            }
        }
        
        $secciones = [
            ['titulo' => 'Cobranza Normal (< 5 atrasadas)', 'icono' => '🟢', 'pagos' => $pagos_normal, 'bg_tot' => 'rgba(79,70,229,.05)'],
            ['titulo' => 'Morosos Críticos (5+ atrasadas)', 'icono' => '🔴', 'pagos' => $pagos_5plus, 'bg_tot' => 'rgba(239,68,68,.08)']
        ];
    ?>

    <?php if (empty($detalle_pagos)): ?>
        <p class="text-center text-muted" style="padding:24px">No hay pagos en esta rendición.</p>
    <?php else: ?>
        <?php foreach ($secciones as $sec): 
            if (empty($sec['pagos'])) continue;
            
            $sef = array_sum(array_column($sec['pagos'], 'monto_efectivo'));
            $str = array_sum(array_column($sec['pagos'], 'monto_transferencia'));
            $stot = $sef + $str;
        ?>
        <div style="padding:8px 16px;background:var(--bg-panel);font-weight:700;font-size:.85rem;color:var(--text-color);border-top:1px solid rgba(255,255,255,.05);border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:6px">
            <?= $sec['icono'] ?> <?= $sec['titulo'] ?>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic" style="margin-bottom:0">
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
                    <?php foreach ($sec['pagos'] as $dp): ?>
                        <tr>
                            <td class="text-muted nowrap">
                                <?= date('d/m/Y H:i', strtotime($dp['fecha_pago'])) ?>
                            </td>
                            <td>
                                <div class="fw-bold">
                                    <?= e($dp['apellidos'] . ', ' . $dp['nombres']) ?>
                                    <?php if ((int)$dp['cuotas_atrasadas_cliente'] >= 5): ?>
                                        <span style="font-size:.68rem;background:rgba(239,68,68,.15);color:var(--danger);padding:2px 5px;border-radius:4px;margin-left:4px" title="<?= $dp['cuotas_atrasadas_cliente'] ?> cuotas vencidas">
                                            ⚠ <?= $dp['cuotas_atrasadas_cliente'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
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
                                <?= formato_pesos($dp['monto_efectivo'] + $dp['monto_transferencia']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:<?= $sec['bg_tot'] ?>;font-weight:700">
                        <td colspan="4" style="text-align:right;padding-right:12px;font-size:.82rem">SUBTOTAL <?= mb_strtoupper($sec['titulo'], 'UTF-8') ?></td>
                        <td class="nowrap text-right" style="color:var(--success)"><?= formato_pesos($sef) ?></td>
                        <td class="nowrap text-right" style="color:var(--primary-light)"><?= formato_pesos($str) ?></td>
                        <td class="nowrap text-right" style="color:var(--accent)"><?= formato_pesos($stot) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
