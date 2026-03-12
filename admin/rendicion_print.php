<?php
// ============================================================
// admin/rendicion_print.php — Vista de Impresión de Rendición
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones'); // Mismo permiso que rendiciones.php

$pdo = obtener_conexion();
$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);

if (!$cobrador_id) {
    die("Cobrador no especificado.");
}

// Obtener datos del cobrador
$stmtCob = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$stmtCob->execute([$cobrador_id]);
$cobrador = $stmtCob->fetch();
if (!$cobrador) {
    die("Cobrador no encontrado.");
}
$nombre_cobrador = $cobrador['nombre'] . ' ' . $cobrador['apellido'];

// Obtener detalle de pagos
$dstmt = $pdo->prepare("
    SELECT pt.*, pt.solicitud_baja, pt.motivo_baja, pt.es_cuota_pura,
           cl.nombres, cl.apellidos,
           cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas cu ON pt.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE pt.cobrador_id = ? AND DATE(pt.fecha_registro) = ? AND pt.estado = 'PENDIENTE'
    ORDER BY pt.solicitud_baja DESC, pt.fecha_registro
");
$dstmt->execute([$cobrador_id, $fecha_sel]);
$detalle_pagos = $dstmt->fetchAll();

$total_efectivo        = array_sum(array_column($detalle_pagos, 'monto_efectivo'));
$total_transferencia   = array_sum(array_column($detalle_pagos, 'monto_transferencia'));
$total_general         = array_sum(array_column($detalle_pagos, 'monto_total'));
$total_mora_cobrada    = 0;
$total_mora_no_cobrada = 0;
foreach ($detalle_pagos as $p) {
    if ((int)$p['es_cuota_pura']) {
        $total_mora_no_cobrada += (float)$p['monto_mora_cobrada'];
    } else {
        $total_mora_cobrada += (float)$p['monto_mora_cobrada'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Rendición - <?= e($nombre_cobrador) ?> (<?= date('d/m/Y', strtotime($fecha_sel)) ?>)</title>
    <style>
        :root {
            --text-color: #1f2937;
            --border-color: #e5e7eb;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: var(--text-color);
            line-height: 1.5;
            margin: 0;
            padding: 20px;
            background: #e5e7eb;
            display: flex;
            justify-content: center;
        }
        .a4-page {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            padding: 15mm;
            box-sizing: border-box;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header-title {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }
        .header-meta {
            text-align: right;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        th {
            background-color: #f9fafb;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            color: #4b5563;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .fw-bold { font-weight: bold; }
        
        .totals-box {
            margin-top: 30px;
            width: 300px;
            float: right;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 15px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .totals-row.grand-total {
            font-size: 18px;
            font-weight: bold;
            border-top: 2px solid var(--border-color);
            padding-top: 8px;
            margin-top: 8px;
            margin-bottom: 0;
        }
        
        .signature-area {
            clear: both;
            margin-top: 100px;
            display: flex;
            justify-content: space-around;
        }
        .signature-line {
            width: 250px;
            border-top: 1px solid #9ca3af;
            text-align: center;
            padding-top: 8px;
            font-size: 14px;
            color: #4b5563;
        }

        @media print {
            body { 
                padding: 0; 
                margin: 0; 
                background: #fff;
                display: block; 
            }
            .a4-page {
                width: 100%;
                min-height: auto;
                box-shadow: none;
                padding: 0;
            }
            @page { margin: 15mm; size: A4 portrait; }
        }
    </style>
</head>
<body>
<div class="a4-page">

    <div class="header">
        <div>
            <h1 class="header-title">Imperio Comercial</h1>
            <div style="color:#6b7280; font-size:14px; margin-top:4px;">Reporte de Rendición de Cobranza</div>
        </div>
        <div class="header-meta">
            <div>Cobrador: <strong><?= e($nombre_cobrador) ?></strong></div>
            <div>Fecha Ingreso: <strong><?= date('d/m/Y', strtotime($fecha_sel)) ?></strong></div>
            <div>Impreso: <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <?php if (empty($detalle_pagos)): ?>
        <p class="text-center" style="padding: 40px; color: #6b7280; border: 1px dashed var(--border-color);">
            No nay pagos registrados para este cobrador en la fecha seleccionada.
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Cuota y Venc.</th>
                    <th>Artículo</th>
                    <th class="text-right">Efectivo</th>
                    <th class="text-right">Transf.</th>
                    <th class="text-right">Mora</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalle_pagos as $p): ?>
                    <tr>
                        <td class="fw-bold"><?= e($p['apellidos'] . ', ' . $p['nombres']) ?></td>
                        <td>#<?= $p['numero_cuota'] ?> (<?= date('d/m/y', strtotime($p['fecha_vencimiento'])) ?>)</td>
                        <td><?= e($p['articulo']) ?></td>
                        <td class="text-right nowrap"><?= formato_pesos($p['monto_efectivo']) ?></td>
                        <td class="text-right nowrap"><?= formato_pesos($p['monto_transferencia']) ?></td>
                        <td class="text-right nowrap">
                            <?php if ((int)$p['es_cuota_pura'] && $p['monto_mora_cobrada'] > 0): ?>
                                <span style="color:#d97706;font-style:italic"><?= formato_pesos($p['monto_mora_cobrada']) ?></span>
                                <span style="font-size:10px;color:#d97706;display:block">pendiente</span>
                            <?php else: ?>
                                <?= formato_pesos($p['monto_mora_cobrada']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-right nowrap fw-bold"><?= formato_pesos($p['monto_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Caja de Totales -->
        <div class="totals-box">
            <div class="totals-row">
                <span style="color:#6b7280;">Total Efectivo:</span>
                <span><?= formato_pesos($total_efectivo) ?></span>
            </div>
            <div class="totals-row">
                <span style="color:#6b7280;">Total Transferencias:</span>
                <span><?= formato_pesos($total_transferencia) ?></span>
            </div>
            <?php if ($total_mora_cobrada > 0): ?>
            <div class="totals-row">
                <span style="color:#6b7280;">Mora Cobrada:</span>
                <span><?= formato_pesos($total_mora_cobrada) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($total_mora_no_cobrada > 0): ?>
            <div class="totals-row">
                <span style="color:#d97706;">Mora No Cobrada:</span>
                <span style="color:#d97706;"><?= formato_pesos($total_mora_no_cobrada) ?></span>
            </div>
            <?php endif; ?>
            <div class="totals-row grand-total">
                <span>TOTAL RENDIDO:</span>
                <span><?= formato_pesos($total_general) ?></span>
            </div>
        </div>

        <!-- Área de Firmas -->
        <div class="signature-area">
            <div class="signature-line">
                Firma Administrador / Caja
            </div>
            <div class="signature-line">
                Firma Cobrador (<?= e($nombre_cobrador) ?>)
            </div>
        </div>
    <?php endif; ?>

</div>
    <script>
        // Auto-print removed per user request
    </script>
</body>
</html>
