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
$rol   = $_SESSION['rol'] ?? '';

if (!$pt_id) {
    echo json_encode(['error' => 'ID inválido.']);
    exit;
}

// Admin y supervisor pueden anular cualquier pago PENDIENTE;
// cobrador solo puede anular los propios.
if (in_array($rol, ['admin', 'supervisor'], true)) {
    $stmt = $pdo->prepare("
        SELECT pt.id, pt.cobrador_id, cu.numero_cuota
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        WHERE pt.id = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$pt_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT pt.id, pt.cobrador_id, cu.numero_cuota
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        WHERE pt.id = ? AND pt.cobrador_id = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$pt_id, $uid]);
}
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['error' => 'Pago no encontrado o ya fue aprobado por el supervisor.']);
    exit;
}

$pdo->prepare("
    DELETE FROM ic_pagos_temporales
    WHERE id = ? AND estado = 'PENDIENTE'
")->execute([$pt_id]);

registrar_log($pdo, $uid, 'PAGO_ANULADO', 'pago_temporal', $pt_id,
    'Cuota #' . $row['numero_cuota'] . ' — pago anulado por ' . $rol);

echo json_encode(['ok' => true]);
