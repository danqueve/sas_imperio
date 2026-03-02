<?php
// clientes/eliminar.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('eliminar_clientes');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

$pdo = obtener_conexion();
// Verificar que no tenga créditos en curso
$activos = $pdo->prepare("SELECT COUNT(*) FROM ic_creditos WHERE cliente_id=? AND estado='EN_CURSO'");
$activos->execute([$id]);
if ((int) $activos->fetchColumn() > 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'No se puede eliminar: el cliente tiene créditos activos.'];
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM ic_clientes WHERE id=?")->execute([$id]);
registrar_log($pdo, $_SESSION['user_id'], 'CLIENTE_ELIMINADO', 'cliente', $id);
$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Cliente eliminado.'];
header('Location: index.php');
exit;
