<?php
// ============================================================
// cobrador/agenda_print.php — Ficha Semanal de Cobros HTML Print-Ready
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo         = obtener_conexion();
$is_cobrador = es_cobrador();
$user_id     = $_SESSION['user_id'];

// ── Parámetros GET ─────────────────────────────────────────────
$cobrador_id = $is_cobrador ? $user_id : (int)($_GET['cobrador_id'] ?? 0);
$dias_sel    = array_map('intval', (array)($_GET['dias'] ?? [1,2,3,4,5,6]));
$dias_sel    = array_filter($dias_sel, fn($d) => $d >= 1 && $d <= 6);
sort($dias_sel);

if (!$cobrador_id) die('Seleccioná un cobrador.');
if (empty($dias_sel)) die('Seleccioná al menos un día.');

// Datos del cobrador
$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

$nombres_dia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles',
                4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

// ── Consulta: clientes activos del cobrador con cuotas pendientes
$placeholders = implode(',', array_fill(0, count($dias_sel), '?'));
$params = array_merge([$cobrador_id], $dias_sel);

$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.dia_cobro,
           cr.id AS credito_id,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_clientes cl
    JOIN ic_creditos cr  ON cr.cliente_id  = cl.id  AND cr.cobrador_id = ? AND cr.estado = 'EN_CURSO'
    JOIN ic_cuotas  cu   ON cu.credito_id  = cr.id  AND cu.estado IN ('PENDIENTE','VENCIDA')
    LEFT JOIN ic_articulos a  ON a.id            = cr.articulo_id
    WHERE cl.dia_cobro IN ($placeholders)
    ORDER BY cl.dia_cobro ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupar por dia_cobro — un registro por cliente (la cuota más atrasada)
$por_dia = [];
foreach ($dias_sel as $d) $por_dia[$d] = [];
$visto = [];
foreach ($rows as $r) {
    $clave = $r['dia_cobro'] . '-' . $r['cliente_id'];
    if (isset($visto[$clave])) continue;
    $visto[$clave] = true;
    $por_dia[$r['dia_cobro']][] = $r;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Agenda Semanal - <?= e($cobrador['nombre']) ?></title>
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
            background: #e5e7eb;
            display: flex;
            justify-content: center;
        }
        .a4-page {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            padding: 10mm 15mm;
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
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 30px;
        }
        th, td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        th {
            background-color: #f9fafb;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 10px;
            color: #4b5563;
        }
        
        /* Reduciendo altura para que entren más filas */
        tr { height: 32px; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .nowrap { white-space: nowrap; }

        .day-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 1px dashed #9ca3af;
            padding-bottom: 4px;
        }
        .day-total {
            font-size: 12px;
            font-weight: normal;
        }

        /* Checkbox box */
        .check-box {
            width: 14px;
            height: 14px;
            border: 1px solid #9ca3af;
            display: inline-block;
            vertical-align: middle;
        }

        .no-data {
            padding: 15px;
            text-align: center;
            color: #6b7280;
            font-style: italic;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 30px;
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
            /* Salto de página opcional entre días muy largos */
            tr { page-break-inside: avoid; }
            .day-section { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="a4-page">

    <div class="header">
        <div>
            <h1 class="brand">IMPERIO COMERCIAL</h1>
            <div style="font-size:14px; margin-top:4px;">Ficha Semanal de Cobros</div>
        </div>
        <div class="text-right">
            <div style="font-size: 14px;">Cobrador: <strong><?= e($cobrador['nombre'] . ' ' . $cobrador['apellido']) ?></strong></div>
            <div style="font-size: 12px; color: #6b7280; margin-top:4px;">
                Días: <?= implode(', ', array_map(fn($d) => substr($nombres_dia[$d], 0, 3), $dias_sel)) ?><br>
                Emisión: <?= date('d/m/Y H:i') ?>
            </div>
        </div>
    </div>

    <?php foreach ($dias_sel as $dia): ?>
        <?php 
        $clientes_dia = $por_dia[$dia]; 
        $total_dia = array_sum(array_column($clientes_dia, 'monto_cuota'));
        ?>
        
        <div class="day-section">
            <div class="day-title">
                <span><?= mb_strtoupper($nombres_dia[$dia]) ?> — <?= count($clientes_dia) ?> cuota(s)</span>
                <span class="day-total">Total del día: <strong><?= formato_pesos($total_dia) ?></strong></span>
            </div>

            <?php if (empty($clientes_dia)): ?>
                <div class="no-data">Sin créditos asignados para este día.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 25%;">Cliente</th>
                            <th style="width: 13%;">Teléfono</th>
                            <th style="width: 15%;">Zona</th>
                            <th style="width: 20%;">Artículo</th>
                            <th style="width: 10%;" class="text-center">Vencimiento</th>
                            <th style="width: 12%;" class="text-right">Monto</th>
                            <th style="width: 5%;" class="text-center">Ok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes_dia as $r): ?>
                            <tr>
                                <td class="fw-bold"><?= e(mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 40, '..')) ?></td>
                                <td><?= e($r['telefono']) ?></td>
                                <td><?= e(mb_strimwidth($r['zona'] ?: '-', 0, 20, '..')) ?></td>
                                <td><?= e(mb_strimwidth($r['articulo'], 0, 30, '..')) ?></td>
                                <td class="text-center nowrap">#<?= $r['numero_cuota'] ?> (<?= date('d/m', strtotime($r['fecha_vencimiento'])) ?>)</td>
                                <td class="text-right fw-bold nowrap"><?= formato_pesos($r['monto_cuota']) ?></td>
                                <td class="text-center">
                                    <div class="check-box"></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

</div>
    <script>
        // Auto-print removed per user request
    </script>
</body>
</html>
