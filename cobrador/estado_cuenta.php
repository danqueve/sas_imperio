<?php
// cobrador/estado_cuenta.php — Devuelve JSON con cronograma de cuotas de un crédito
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

header('Content-Type: application/json; charset=utf-8');

$pdo        = obtener_conexion();
$credito_id = (int) ($_GET['credito_id'] ?? 0);
$uid        = (int) $_SESSION['user_id'];
$is_cob     = es_cobrador();

if (!$credito_id) {
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

// Verificar acceso: cobrador solo ve sus créditos
$cond_cob = $is_cob ? "AND cr.cobrador_id = {$uid}" : '';
$cr_stmt  = $pdo->prepare("
    SELECT cr.cant_cuotas, cr.frecuencia, cr.monto_cuota, cr.fecha_alta,
           COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
           cl.nombres, cl.apellidos
    FROM ic_creditos cr
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE cr.id = ? {$cond_cob}
");
$cr_stmt->execute([$credito_id]);
$credito = $cr_stmt->fetch();

if (!$credito) {
    echo json_encode(['error' => 'No encontrado']);
    exit;
}

// Cuotas
$stmt = $pdo->prepare("
    SELECT numero_cuota, fecha_vencimiento, monto_cuota, monto_mora,
           estado, saldo_pagado, fecha_pago
    FROM ic_cuotas
    WHERE credito_id = ?
    ORDER BY numero_cuota ASC
");
$stmt->execute([$credito_id]);
$cuotas_raw = $stmt->fetchAll();

$labels = [
    'PAGADA'     => 'Pagada',
    'PENDIENTE'  => 'Pendiente',
    'VENCIDA'    => 'Atrasada',
    'PARCIAL'    => 'Parcial',
    'CAP_PAGADA' => 'Cap. Pag.',
];

$resumen = ['pagadas' => 0, 'pendientes' => 0, 'vencidas' => 0, 'parciales' => 0];
$cuotas  = [];

foreach ($cuotas_raw as $c) {
    switch ($c['estado']) {
        case 'PAGADA':     $resumen['pagadas']++;   break;
        case 'VENCIDA':    $resumen['vencidas']++;  break;
        case 'PENDIENTE':  $resumen['pendientes']++; break;
        default:           $resumen['parciales']++;  break;
    }
    $cuotas[] = [
        'numero_cuota'          => (int) $c['numero_cuota'],
        'fecha_vencimiento_fmt' => date('d/m/Y', strtotime($c['fecha_vencimiento'])),
        'fecha_pago_fmt'        => $c['fecha_pago'] ? date('d/m', strtotime($c['fecha_pago'])) : null,
        'monto_fmt'             => number_format((float) $c['monto_cuota'], 0, ',', '.'),
        'mora_fmt'              => (float) $c['monto_mora'] > 0 ? number_format((float) $c['monto_mora'], 0, ',', '.') : null,
        'estado'                => $c['estado'],
        'estado_label'          => $labels[$c['estado']] ?? $c['estado'],
    ];
}

echo json_encode([
    'credito' => [
        'articulo'    => $credito['articulo'],
        'cant_cuotas' => (int) $credito['cant_cuotas'],
        'frecuencia'  => $credito['frecuencia'],
        'monto_cuota' => number_format((float) $credito['monto_cuota'], 0, ',', '.'),
    ],
    'cuotas'  => $cuotas,
    'resumen' => $resumen,
]);
