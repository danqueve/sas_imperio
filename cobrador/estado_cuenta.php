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
if ($is_cob) {
    $cr_stmt = $pdo->prepare("
        SELECT cr.cant_cuotas, cr.frecuencia, cr.monto_cuota, cr.fecha_alta,
               COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
               cl.nombres, cl.apellidos
        FROM ic_creditos cr
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        WHERE cr.id = ? AND cr.cobrador_id = ?
    ");
    $cr_stmt->execute([$credito_id, $uid]);
} else {
    $cr_stmt = $pdo->prepare("
        SELECT cr.cant_cuotas, cr.frecuencia, cr.monto_cuota, cr.fecha_alta,
               COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
               cl.nombres, cl.apellidos
        FROM ic_creditos cr
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        WHERE cr.id = ?
    ");
    $cr_stmt->execute([$credito_id]);
}
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

// Historial de pagos confirmados
$hist_stmt = $pdo->prepare("
    SELECT cu.numero_cuota, pt.fecha_jornada,
           pt.monto_efectivo, pt.monto_transferencia, pt.monto_mora_cobrada, pt.monto_total,
           CONCAT(u.nombre, ' ', u.apellido) AS cobrador_nombre
    FROM ic_pagos_confirmados pc
    JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    JOIN ic_cuotas cu           ON cu.id = pc.cuota_id
    JOIN ic_usuarios u          ON u.id  = pt.cobrador_id
    WHERE cu.credito_id = ?
    ORDER BY pt.fecha_jornada ASC, pc.id ASC
");
$hist_stmt->execute([$credito_id]);
$hist_rows = $hist_stmt->fetchAll();

$pagos = [];
$hist_total = 0;
foreach ($hist_rows as $p) {
    $hist_total += (float) $p['monto_total'];
    $pagos[] = [
        'numero_cuota' => (int) $p['numero_cuota'],
        'fecha_fmt'    => date('d/m/Y', strtotime($p['fecha_jornada'])),
        'ef_fmt'       => (float)$p['monto_efectivo'] > 0 ? '$' . number_format((float)$p['monto_efectivo'], 0, ',', '.') : null,
        'tr_fmt'       => (float)$p['monto_transferencia'] > 0 ? '$' . number_format((float)$p['monto_transferencia'], 0, ',', '.') : null,
        'mora_fmt'     => (float)$p['monto_mora_cobrada'] > 0 ? '$' . number_format((float)$p['monto_mora_cobrada'], 0, ',', '.') : null,
        'total_fmt'    => '$' . number_format((float)$p['monto_total'], 0, ',', '.'),
        'cobrador'     => $p['cobrador_nombre'],
    ];
}

// Cuotas pagables — para selección interactiva en la agenda
$pag_stmt = $pdo->prepare("
    SELECT cu.id, cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           cu.estado, cu.monto_mora, cu.saldo_pagado,
           cr.interes_moratorio_pct,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt
            WHERE pt.cuota_id = cu.id AND pt.estado = 'PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cu.credito_id = ? AND cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
    ORDER BY cu.numero_cuota ASC
");
$pag_stmt->execute([$credito_id]);
$cuotas_pagables = [];
foreach ($pag_stmt->fetchAll() as $cp) {
    $dias   = dias_atraso_habiles($cp['fecha_vencimiento']);
    $mora   = ($cp['estado'] === 'CAP_PAGADA')
                ? (float) $cp['monto_mora']
                : calcular_mora((float)$cp['monto_cuota'], $dias, (float)$cp['interes_moratorio_pct']);
    $saldo  = (float)($cp['saldo_pagado'] ?? 0);
    $total  = ($cp['estado'] === 'CAP_PAGADA')
                ? $mora
                : max(0, (float)$cp['monto_cuota'] + $mora - $saldo);
    $cuotas_pagables[] = [
        'id'           => (int)  $cp['id'],
        'numero_cuota' => (int)  $cp['numero_cuota'],
        'monto_cuota'  => (float)$cp['monto_cuota'],
        'mora'         => round($mora, 2),
        'total'        => round($total, 2),
        'estado'       => $cp['estado'],
        'fecha_venc'   => date('d/m/Y', strtotime($cp['fecha_vencimiento'])),
        'pago_pen'     => (int)  $cp['pago_pen'],
    ];
}

echo json_encode([
    'credito' => [
        'articulo'    => $credito['articulo'],
        'cant_cuotas' => (int) $credito['cant_cuotas'],
        'frecuencia'  => $credito['frecuencia'],
        'monto_cuota' => number_format((float) $credito['monto_cuota'], 0, ',', '.'),
    ],
    'cuotas'          => $cuotas,
    'cuotas_pagables' => $cuotas_pagables,
    'resumen'         => $resumen,
    'pagos'           => $pagos,
    'hist_total'      => '$' . number_format($hist_total, 0, ',', '.'),
]);
