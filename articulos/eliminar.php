<?php
// articulos/eliminar.php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');
$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}
$usados = $pdo->prepare("SELECT COUNT(*) FROM ic_creditos WHERE articulo_id=?");
$usados->execute([$id]);
if ((int) $usados->fetchColumn() > 0) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'No se puede eliminar: el artículo está asociado a créditos.'];
} else {
    $pdo->prepare("DELETE FROM ic_articulos WHERE id=?")->execute([$id]);
    registrar_log($pdo, $_SESSION['user_id'], 'ARTICULO_ELIMINADO', 'articulo', $id);
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Artículo eliminado.'];
}
header('Location: index.php');
exit;
