<?php
// vendedores/eliminar.php (cambiar de estado lógico - no borrar realmente)
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id = (int)($_GET['id'] ?? 0);
$accion = $_GET['accion'] ?? '';

if (!$id || !in_array($accion, ['alta', 'baja'])) {
    header('Location: index.php');
    exit;
}

$nuevo_estado = ($accion === 'alta') ? 1 : 0;

$upd = $pdo->prepare("UPDATE ic_vendedores SET activo = ? WHERE id = ?");
$upd->execute([$nuevo_estado, $id]);

$log_msg = ($nuevo_estado) ? 'VENDEDOR_REACTIVADO' : 'VENDEDOR_DADO_BAJA';
registrar_log($pdo, $_SESSION['user_id'], $log_msg, 'vendedores', $id);

$_SESSION['flash'] = ['type' => 'success', 'msg' => ($nuevo_estado ? 'Vendedor reactivado.' : 'Vendedor dado de baja.')];
header('Location: index.php');
exit;
