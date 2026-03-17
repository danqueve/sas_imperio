<?php
// clientes/resolver_maps.php — Resuelve una URL corta de Google Maps y extrae lat,lng
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
verificar_sesion();

header('Content-Type: application/json; charset=utf-8');

$url = trim($_GET['url'] ?? '');

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'URL inválida']);
    exit;
}

// Solo URLs de Google Maps
if (!preg_match('#^https://(maps\.app\.goo\.gl|goo\.gl/maps|www\.google\.com/maps)#', $url)) {
    echo json_encode(['error' => 'URL no reconocida']);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(['error' => 'cURL no disponible en el servidor']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY         => true,   // Solo HEAD, sin body
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GoogleMapsResolver/1.0)',
    CURLOPT_SSL_VERIFYPEER => false,
]);
curl_exec($ch);
$final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$err       = curl_errno($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'No se pudo resolver la URL']);
    exit;
}

// Patrón 1: /@lat,lng,zoom
if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $final_url, $m)) {
    echo json_encode(['lat' => $m[1], 'lng' => $m[2]]);
    exit;
}

// Patrón 2: ?q=lat,lng o &q=lat,lng
if (preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $final_url, $m)) {
    echo json_encode(['lat' => $m[1], 'lng' => $m[2]]);
    exit;
}

// Patrón 3: !3dlat!4dlng (datos embebidos)
if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $final_url, $m)) {
    echo json_encode(['lat' => $m[1], 'lng' => $m[2]]);
    exit;
}

echo json_encode(['error' => 'No se encontraron coordenadas en la URL']);
