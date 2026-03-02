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
$historial = $pdo->prepare("SELECT COUNT(*) FROM ic_creditos WHERE cliente_id=?");
$historial->execute([$id]);
if ((int) $historial->fetchColumn() > 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'No se puede eliminar: el cliente posee historial de créditos. Puede editarlo y marcarlo como INACTIVO.'];
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM ic_clientes WHERE id=?")->execute([$id]);
registrar_log($pdo, $_SESSION['user_id'], 'CLIENTE_ELIMINADO', 'cliente', $id);
$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Cliente eliminado.'];
header('Location: index.php');
exit;
