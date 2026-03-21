<?php
// cobrador/anular_pago.php — Anula un pago PENDIENTE del cobrador
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_pagos');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$pdo   = obtener_conexion();
$pt_id = (int) ($_POST['pt_id'] ?? 0);
$uid   = (int) $_SESSION['user_id'];

if (!$pt_id) {
    echo json_encode(['error' => 'ID inválido.']);
    exit;
}

// Verificar que pertenece al cobrador y está PENDIENTE (no aprobado)
$stmt = $pdo->prepare("
    SELECT pt.id, cu.numero_cuota
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas cu ON pt.cuota_id = cu.id
    WHERE pt.id = ? AND pt.cobrador_id = ? AND pt.estado = 'PENDIENTE'
");
$stmt->execute([$pt_id, $uid]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['error' => 'Pago no encontrado o ya fue aprobado por el supervisor.']);
    exit;
}

$pdo->prepare("
    DELETE FROM ic_pagos_temporales
    WHERE id = ? AND cobrador_id = ? AND estado = 'PENDIENTE'
")->execute([$pt_id, $uid]);

registrar_log($pdo, $uid, 'PAGO_ANULADO', 'pago_temporal', $pt_id,
    'Cuota #' . $row['numero_cuota'] . ' — pago anulado por el cobrador');

echo json_encode(['ok' => true]);
