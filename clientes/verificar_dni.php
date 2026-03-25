<?php
// clientes/verificar_dni.php — Verificar si un DNI ya existe (AJAX)
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
verificar_sesion();

header('Content-Type: application/json');

$dni = trim($_GET['dni'] ?? '');
if ($dni === '') {
    echo json_encode(['existe' => false]);
    exit;
}

$pdo = obtener_conexion();
$stmt = $pdo->prepare("SELECT id, nombres, apellidos FROM ic_clientes WHERE dni = ? LIMIT 1");
$stmt->execute([$dni]);
$row = $stmt->fetch();

if ($row) {
    echo json_encode([
        'existe' => true,
        'id'     => (int)$row['id'],
        'nombre' => htmlspecialchars($row['apellidos'] . ', ' . $row['nombres'], ENT_QUOTES, 'UTF-8'),
    ]);
} else {
    echo json_encode(['existe' => false]);
}
