<?php
// ============================================================
// admin/api_acceso_supervisor.php — Endpoint JSON polling
// Usado por auth/acceso_restringido.php para verificar si el
// admin ya otorgó acceso. NO usa verificar_sesion() para no
// devolver un redirect en lugar de JSON.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';

header('Content-Type: application/json; charset=utf-8');

// Validar que sea un supervisor logueado
if (empty($_SESSION['user_id']) || ($_SESSION['rol'] ?? '') !== 'supervisor') {
    http_response_code(401);
    echo json_encode(['tiene_acceso' => false]);
    exit;
}

$hora   = (int) date('G');
$dentro = ($hora >= SUPERVISOR_HORA_INICIO && $hora < SUPERVISOR_HORA_FIN);

if ($dentro) {
    echo json_encode(['tiene_acceso' => true]);
    exit;
}

// Fuera del horario: verificar extensión en DB
try {
    $pdo  = obtener_conexion();
    $stmt = $pdo->prepare(
        "SELECT acceso_extendido_hasta FROM ic_usuarios WHERE id=? AND activo=1 LIMIT 1"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $ext = $stmt->fetchColumn();

    $tiene_ext = ($ext && new DateTime($ext) > new DateTime());
    echo json_encode(['tiene_acceso' => $tiene_ext]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['tiene_acceso' => false]);
}
