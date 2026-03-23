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
$cuota_id      = (int) ($_POST['cuota_id'] ?? 0);
$ef            = (float) ($_POST['monto_efectivo'] ?? 0);
$tr            = (float) ($_POST['monto_transferencia'] ?? 0);
$obs           = substr(trim($_POST['observaciones'] ?? ''), 0, 500);
$total         = $ef + $tr;
$es_cuota_pura = (int) ($_POST['es_cuota_pura'] ?? 0);

// Validar fecha de jornada: debe ser una de las fechas permitidas para este momento
$jornadas_ok       = jornadas_disponibles();
$fecha_jornada_sel = $_POST['fecha_jornada_sel'] ?? $jornadas_ok[0];
if (!in_array($fecha_jornada_sel, $jornadas_ok, true)) {
    $fecha_jornada_sel = $jornadas_ok[0];
}

if (!$cuota_id || $total <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Datos inválidos.'];
    header('Location: agenda');
    exit;
}

// Obtener credito_id e interes_moratorio; validar que pertenece al cobrador logueado
$stmt = $pdo->prepare("
    SELECT cu.credito_id, cr.interes_moratorio_pct
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cu.id = ? AND cr.cobrador_id = ?
");
$stmt->execute([$cuota_id, $_SESSION['user_id']]);
$row = $stmt->fetch();

if (!$row) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Cuota no encontrada o no pertenece a tus creditos.'];
    header('Location: agenda');
    exit;
}

$credito_id = (int) $row['credito_id'];
$pct_mora   = (float) $row['interes_moratorio_pct'];

// Obtener cuotas pendientes/vencidas/parciales del crédito, de más antigua a más nueva
$cuotas_stmt = $pdo->prepare("
    SELECT cu.id, cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           cu.saldo_pagado, cu.monto_mora, cu.estado,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt
            WHERE pt.cuota_id = cu.id AND pt.estado = 'PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    WHERE cu.credito_id = ? AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'PARCIAL', 'CAP_PAGADA')
    ORDER BY cu.numero_cuota ASC
");
$cuotas_stmt->execute([$credito_id]);
$cuotas_pendientes = $cuotas_stmt->fetchAll();

$remaining    = $total;
$ef_remaining = $ef;
$tr_remaining = $tr;
$cuotas_ok    = 0;

// Fase 2: transacción con FOR UPDATE para bloqueo atómico
$pdo->beginTransaction();

// Re-leer cuotas con bloqueo dentro de la transacción
$cuotas_lock = $pdo->prepare("
    SELECT cu.id, cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
           cu.saldo_pagado, cu.monto_mora, cu.estado,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt
            WHERE pt.cuota_id = cu.id AND pt.estado = 'PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    WHERE cu.credito_id = ? AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'PARCIAL', 'CAP_PAGADA')
    ORDER BY cu.numero_cuota ASC
    FOR UPDATE
");
$cuotas_lock->execute([$credito_id]);
$cuotas_pendientes = $cuotas_lock->fetchAll();

// Re-verificar pagos pendientes dentro de la transacción (atómico)
foreach ($cuotas_pendientes as $c) {
    if ((int) $c['pago_pen'] > 0) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Este crédito tiene pagos pendientes de aprobación.'];
        header('Location: agenda');
        exit;
    }
}

foreach ($cuotas_pendientes as $cuota) {
    if ($remaining <= 0.005) break;

    // Cuota pura: saltar mora congelada de CAP_PAGADA; se cobra en otra visita
    if ($es_cuota_pura && $cuota['estado'] === 'CAP_PAGADA') continue;

    $saldo_prev  = (float) ($cuota['saldo_pagado'] ?? 0);
    $mora_frozen = (float) $cuota['monto_mora'];
    $dias_atraso = dias_atraso_habiles($cuota['fecha_vencimiento']);

    // Usar mora congelada; si no existe aún, calcularla
    if ($mora_frozen <= 0) {
        $mora_frozen = calcular_mora($cuota['monto_cuota'], $dias_atraso, $pct_mora);
    }

    // Cuota pura (flag del cobrador) y aún no es CAP_PAGADA → tratar como si no hubiera mora
    $total_cuota = ($es_cuota_pura && $cuota['estado'] !== 'CAP_PAGADA')
        ? $cuota['monto_cuota']
        : $cuota['monto_cuota'] + $mora_frozen;
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
          (cuota_id, cobrador_id, monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada, mora_congelada, es_cuota_pura, fecha_jornada, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$cuota['id'], $_SESSION['user_id'], $pago_ef, $pago_tr, $pago_en_esta, $mora_en_esta, $mora_frozen, $es_cuota_pura, $fecha_jornada_sel, $obs]);

    registrar_log($pdo, $_SESSION['user_id'], 'PAGO_REGISTRADO', 'cuota', $cuota['id'],
        'Cuota #' . $cuota['numero_cuota'] . ' — Ef: ' . formato_pesos($pago_ef) . ' | Tr: ' . formato_pesos($pago_tr));

    $ef_remaining -= $pago_ef;
    $tr_remaining -= $pago_tr;
    $remaining    -= $pago_en_esta;
    $cuotas_ok++;
}

$pdo->commit();

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
