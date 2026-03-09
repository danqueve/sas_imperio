<?php
// ============================================================
// creditos/cronograma_print.php — Vista de Impresión del Cronograma
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    die("Crédito no especificado.");
}

$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono, cl.dni, cl.id AS cid,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    WHERE cr.id=?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) {
    die("Crédito no encontrado.");
}

$cuotas = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas->execute([$id]);
$lista_cuotas = $cuotas->fetchAll();

// Calcular pagadas
$pagadas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PAGADA'));
$total_cuotas = count($lista_cuotas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cronograma de Pagos - <?= e($cr['apellidos']) ?> (Crédito #<?= $id ?>)</title>
    <style>
        :root {
            --text-color: #111827;
            --border-color: #d1d5db;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: var(--text-color);
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            background: #e5e7eb; /* Fondo gris tipo escritorio */
            display: flex;
            justify-content: center;
        }
        /* Contenedor que simula hoja A4 */
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
            align-items: flex-end;
            border-bottom: 2px solid #111827;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .brand {
            font-size: 28px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            background: #f9fafb;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        .info-item:last-child { margin-bottom: 0; }
        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .info-value {
            font-size: 15px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        th {
            background-color: #f3f4f6;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            color: #374151;
            border-bottom: 2px solid var(--border-color);
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-pagada { border: 1px solid #10b981; color: #10b981; }
        .status-pendiente { border: 1px solid #6b7280; color: #6b7280; }
        .status-vencida { border: 1px solid #ef4444; color: #ef4444; }
        .status-parcial { border: 1px solid #f59e0b; color: #f59e0b; }

        .signature-area {
            margin-top: 60px;
            display: flex;
            justify-content: flex-end;
        }
        .signature-line {
            width: 300px;
            border-top: 1px solid #9ca3af;
            text-align: center;
            padding-top: 8px;
            font-size: 13px;
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
            @page { margin: 10mm; size: A4 portrait; }
            .info-grid { background: white !important; border: 1px solid #000 !important; }
            th { border-bottom: 2px solid #000 !important; }
        }
    </style>
</head>
<body>
<div class="a4-page">

    <div class="header">
        <div>
            <h1 class="brand">IMPERIO COMERCIAL</h1>
            <div style="font-size:14px; margin-top:4px;">Cronograma de Pagos</div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 20px; font-weight: bold;">Crédito #<?= $id ?></div>
            <div style="font-size: 12px; color: #6b7280;">Emitido: <?= date('d/m/Y') ?></div>
        </div>
    </div>

    <!-- Tarjeta de Identidad -->
    <div class="info-grid">
        <div>
            <div class="info-item">
                <span class="info-label">Cliente</span>
                <span class="info-value"><?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">DNI / Documento</span>
                <span class="info-value"><?= e($cr['dni'] ?: '—') ?></span>
            </div>
            <div class="info-item" style="margin-bottom:0">
                <span class="info-label">Teléfono de Contacto</span>
                <span class="info-value"><?= e($cr['telefono']) ?></span>
            </div>
        </div>
        <div>
            <div class="info-item">
                <span class="info-label">Artículo Financiado</span>
                <span class="info-value"><?= e($cr['articulo']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Condición Comercial</span>
                <span class="info-value">
                    Total: <?= formato_pesos($cr['monto_total']) ?> en <?= $cr['cant_cuotas'] ?> cuotas 
                    (<?= ucfirst($cr['frecuencia']) ?>)
                </span>
            </div>
            <div class="info-item" style="margin-bottom:0">
                <span class="info-label">Cobrador Asignado</span>
                <span class="info-value"><?= e($cr['cobrador_n'] . ' ' . $cr['cobrador_a']) ?></span>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 50px;">Cuota</th>
                <th style="width: 150px;">Vencimiento</th>
                <th class="text-right">Monto Base</th>
                <th>Estado</th>
                <th class="text-right">Abonado</th>
                <th style="width: 150px;" class="text-right">Fecha Pago</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lista_cuotas as $q): ?>
                <?php
                    $clase_estado = 'status-pendiente';
                    if ($q['estado'] === 'PAGADA') $clase_estado = 'status-pagada';
                    elseif ($q['estado'] === 'VENCIDA') $clase_estado = 'status-vencida';
                    elseif ($q['estado'] === 'PARCIAL') $clase_estado = 'status-parcial';
                ?>
                <tr>
                    <td class="fw-bold text-center"><?= $q['numero_cuota'] ?></td>
                    <td><?= date('d/m/Y', strtotime($q['fecha_vencimiento'])) ?></td>
                    <td class="text-right"><?= formato_pesos($q['monto_cuota']) ?></td>
                    <td>
                        <span class="status-badge <?= $clase_estado ?>">
                            <?= $q['estado'] ?>
                        </span>
                    </td>
                    <td class="text-right fw-bold">
                        <?php if ($q['estado'] === 'PAGADA'): ?>
                            <?= formato_pesos($q['monto_cuota'] + (float)$q['monto_mora']) ?>
                        <?php elseif ($q['estado'] === 'PARCIAL'): ?>
                            <?= formato_pesos((float)$q['saldo_pagado']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-right" style="color:#6b7280">
                        <?= $q['fecha_pago'] ? date('d/m/Y H:i', strtotime($q['fecha_pago'])) : '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 20px; font-size: 13px; color: #4b5563;">
        <strong>Progreso:</strong> <?= $pagadas ?> de <?= $total_cuotas ?> cuotas abonadas. 
        <?= $cr['estado'] === 'FINALIZADO' ? '<span style="color:#10b981;font-weight:bold">CRÉDITO SALDADO DENTRO DE LOS TÉRMINOS.</span>' : '' ?>
    </div>

    <!-- Firma Conformidad -->
    <?php if ($cr['estado'] !== 'FINALIZADO'): ?>
    <div class="signature-area">
        <div class="signature-line">
            Firma del Cliente (Conformidad)
        </div>
    </div>
    <?php endif; ?>

</div> <!-- end a4-page -->
    <script>
        // Auto-print removed per user request
    </script>
</body>
</html>
