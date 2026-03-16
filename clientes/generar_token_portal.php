<?php
// ============================================================
// clientes/generar_token_portal.php — Genera token de portal para clientes legacy
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

if (!es_admin() && !es_supervisor()) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Sin permiso.'];
    header('Location: index');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$id = (int) ($_POST['cliente_id'] ?? 0);
if (!$id) {
    header('Location: index');
    exit;
}

$pdo = obtener_conexion();

// Verificar que el cliente existe y aún no tiene token
$chk = $pdo->prepare("SELECT id, token_acceso FROM ic_clientes WHERE id = ?");
$chk->execute([$id]);
$cl = $chk->fetch();

if (!$cl) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Cliente no encontrado.'];
    header('Location: index');
    exit;
}

if ($cl['token_acceso']) {
    // Ya tiene token; no hacer nada
    header('Location: ver?id=' . $id);
    exit;
}

// Generar token único (retry ante colisión por UNIQUE constraint)
do {
    $token = generar_token();
    $exists = $pdo->prepare("SELECT id FROM ic_clientes WHERE token_acceso = ?");
    $exists->execute([$token]);
} while ($exists->fetchColumn());

$pdo->prepare("UPDATE ic_clientes SET token_acceso = ? WHERE id = ? AND token_acceso IS NULL")
    ->execute([$token, $id]);

registrar_log($pdo, $_SESSION['user_id'], 'PORTAL_TOKEN_GENERADO', 'cliente', $id,
    "Token de portal generado para cliente #{$id}");

$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Link de portal generado correctamente.'];
header('Location: ver?id=' . $id);
exit;
