<?php
// cobrador/registrar_intento.php — Registrar intento de cobro fallido
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método inválido']); exit;
}

$pdo       = obtener_conexion();
$cuota_id  = (int)($_POST['cuota_id'] ?? 0);
$motivo    = $_POST['motivo'] ?? '';
$obs       = trim($_POST['observacion'] ?? '');
$fecha_pr  = $_POST['fecha_promesa'] ?? null;
$motivos_validos = ['no_estaba','no_quiso','promesa_pago','otro'];

if (!$cuota_id || !in_array($motivo, $motivos_validos)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']); exit;
}

// Verificar que la cuota pertenece a un crédito del cobrador
$stmt = $pdo->prepare("
    SELECT cu.id FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cu.id = ? AND cr.cobrador_id = ?
");
$stmt->execute([$cuota_id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']); exit;
}

$fecha_pr = ($motivo === 'promesa_pago' && $fecha_pr) ? $fecha_pr : null;

$pdo->prepare("
    INSERT INTO ic_intentos_cobro (cuota_id, cobrador_id, motivo, observacion, fecha_intento, fecha_promesa)
    VALUES (?, ?, ?, ?, CURDATE(), ?)
")->execute([$cuota_id, $_SESSION['user_id'], $motivo, $obs ?: null, $fecha_pr]);

echo json_encode(['ok' => true]);
