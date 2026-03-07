<?php
// cobrador/registrar_pago.php — POST handler para pagos temporales
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_pagos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: agenda');
    exit;
}

$pdo = obtener_conexion();
$cuota_id = (int) ($_POST['cuota_id'] ?? 0);
$ef       = (float) ($_POST['monto_efectivo'] ?? 0);
$tr       = (float) ($_POST['monto_transferencia'] ?? 0);
$total    = $ef + $tr;

if (!$cuota_id || $total <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Datos inválidos.'];
    header('Location: agenda');
    exit;
}

// Obtener credito_id e interes_moratorio de la cuota seleccionada
$stmt = $pdo->prepare("
    SELECT cu.credito_id, cr.interes_moratorio_pct
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cu.id = ?
");
$stmt->execute([$cuota_id]);
$row = $stmt->fetch();

if (!$row) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Cuota no encontrada.'];
    header('Location: agenda');
    exit;
}

$credito_id = (int) $row['credito_id'];
$pct_mora   = (float) $row['interes_moratorio_pct'];

// Obtener cuotas pendientes/vencidas/parciales del crédito, de más antigua a más nueva
$cuotas_stmt = $pdo->prepare("
    SELECT cu.id, cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           cu.saldo_pagado, cu.monto_mora,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt
            WHERE pt.cuota_id = cu.id AND pt.estado = 'PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    WHERE cu.credito_id = ? AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'PARCIAL')
    ORDER BY cu.numero_cuota ASC
");
$cuotas_stmt->execute([$credito_id]);
$cuotas_pendientes = $cuotas_stmt->fetchAll();

// Verificar si hay pagos pendientes en el crédito para evitar saltos y desincronización
foreach ($cuotas_pendientes as $c) {
    if ((int) $c['pago_pen'] > 0) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Este crédito tiene pagos pendientes de aprobación. No se pueden registrar nuevos pagos hasta que el supervisor los apruebe.'];
        header('Location: agenda');
        exit;
    }
}

$remaining    = $total;
$ef_remaining = $ef;
$tr_remaining = $tr;
$cuotas_ok    = 0;

foreach ($cuotas_pendientes as $cuota) {
    if ($remaining <= 0.005) break;

    $saldo_prev  = (float) ($cuota['saldo_pagado'] ?? 0);
    $mora_frozen = (float) $cuota['monto_mora'];
    $dias_atraso = dias_atraso_habiles($cuota['fecha_vencimiento']);

    // Usar mora congelada; si no existe aún, calcularla
    if ($mora_frozen <= 0) {
        $mora_frozen = calcular_mora($cuota['monto_cuota'], $dias_atraso, $pct_mora);
    }

    $total_cuota = $cuota['monto_cuota'] + $mora_frozen;
    $pendiente   = max(0, $total_cuota - $saldo_prev); // lo que falta pagar en esta cuota

    if ($pendiente <= 0.005) continue;

    $pago_en_esta = min($remaining, $pendiente);

    // Distribuir: primero efectivo, luego transferencia
    $pago_ef = min($pago_en_esta, $ef_remaining);
    $pago_tr = $pago_en_esta - $pago_ef;

    // Mora cobrada sólo si este pago completa la cuota
    $mora_en_esta = ($saldo_prev + $pago_en_esta >= $total_cuota - 0.005) ? $mora_frozen : 0.0;

    $pdo->prepare("
        INSERT INTO ic_pagos_temporales
          (cuota_id, cobrador_id, monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$cuota['id'], $_SESSION['user_id'], $pago_ef, $pago_tr, $pago_en_esta, $mora_en_esta]);

    registrar_log($pdo, $_SESSION['user_id'], 'PAGO_REGISTRADO', 'cuota', $cuota['id'],
        'Cuota #' . $cuota['numero_cuota'] . ' — Ef: ' . formato_pesos($pago_ef) . ' | Tr: ' . formato_pesos($pago_tr));

    $ef_remaining -= $pago_ef;
    $tr_remaining -= $pago_tr;
    $remaining    -= $pago_en_esta;
    $cuotas_ok++;
}

if ($cuotas_ok > 0) {
    $msg = $cuotas_ok > 1
        ? "Pago registrado para {$cuotas_ok} cuotas. Pendiente de aprobación."
        : 'Pago registrado correctamente. Pendiente de aprobación del supervisor.';
    $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
} else {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Todas las cuotas ya tienen un pago pendiente de aprobación.'];
}

header('Location: agenda');
exit;
