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
$ef = (float) ($_POST['monto_efectivo'] ?? 0);
$tr = (float) ($_POST['monto_transferencia'] ?? 0);
$mora_cobranda = (float) ($_POST['monto_mora_cobrada'] ?? 0);
$total = $ef + $tr;

if (!$cuota_id || $total <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Datos inválidos.'];
    header('Location: agenda');
    exit;
}

// Verificar que la cuota existe y no tiene pago pendiente
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ic_pagos_temporales WHERE cuota_id=? AND estado='PENDIENTE'");
$stmt->execute([$cuota_id]);
if ((int) $stmt->fetchColumn() > 0) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Esta cuota ya tiene un pago registrado pendiente de aprobación.'];
    header('Location: agenda');
    exit;
}

$pdo->prepare("
    INSERT INTO ic_pagos_temporales
      (cuota_id, cobrador_id, monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada)
    VALUES (?,?,?,?,?,?)
")->execute([$cuota_id, $_SESSION['user_id'], $ef, $tr, $total, $mora_cobranda]);

registrar_log($pdo, $_SESSION['user_id'], 'PAGO_REGISTRADO', 'cuota', $cuota_id,
    'Efectivo: ' . formato_pesos($ef) . ' | Transferencia: ' . formato_pesos($tr));

$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pago registrado correctamente. Pendiente de aprobación del supervisor.'];
header('Location: agenda');
exit;
