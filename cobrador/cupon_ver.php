<?php
// ============================================================
// cobrador/cupon_ver.php — Sirve el PDF de un cupón de pago ya generado
// Único punto de acceso al archivo (nunca se sirve directo por URL,
// bloqueado en .htaccess) — chequea permiso e IDOR acá.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_pagos');

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) die('Cupón inválido.');

$stmt = $pdo->prepare("SELECT id, cobrador_id, ruta_archivo FROM ic_cupones_pago WHERE id = ?");
$stmt->execute([$id]);
$cupon = $stmt->fetch();

if (!$cupon) {
    http_response_code(404);
    die('Cupón no encontrado.');
}

// Un cobrador solo puede ver sus propios cupones (evita IDOR por enumeración de id)
if (es_cobrador() && (int) $cupon['cobrador_id'] !== (int) $_SESSION['user_id']) {
    http_response_code(403);
    die('No tenés acceso a este cupón.');
}

$ruta_absoluta = __DIR__ . '/../' . $cupon['ruta_archivo'];
if (empty($cupon['ruta_archivo']) || !file_exists($ruta_absoluta)) {
    http_response_code(404);
    die('El archivo del cupón no está disponible.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="cupon_pago_' . $id . '.pdf"');
header('Content-Length: ' . filesize($ruta_absoluta));
readfile($ruta_absoluta);
exit;
