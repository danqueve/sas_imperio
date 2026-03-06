<?php
// ventas/eliminar.php — Solo admin. Restaura stock.
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_rol('admin');

$pdo = obtener_conexion();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) {
    header('Location: index');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM ic_ventas WHERE id=?");
$stmt->execute([$id]);
$venta = $stmt->fetch();

if (!$venta) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Venta no encontrada.'];
    header('Location: index');
    exit;
}

try {
    $pdo->beginTransaction();

    // Restaurar stock
    $pdo->prepare("UPDATE ic_articulos SET stock = stock + ? WHERE id=?")
        ->execute([$venta['cantidad'], $venta['articulo_id']]);

    // Eliminar venta
    $pdo->prepare("DELETE FROM ic_ventas WHERE id=?")->execute([$id]);

    $pdo->commit();
    registrar_log($pdo, $_SESSION['user_id'], 'VENTA_ELIMINADA', 'venta', $id,
        $venta['articulo_desc'] . ' x' . $venta['cantidad'] . ' — stock restaurado');
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Venta eliminada y stock restaurado.'];
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al eliminar: ' . $e->getMessage()];
}

header('Location: index');
exit;
