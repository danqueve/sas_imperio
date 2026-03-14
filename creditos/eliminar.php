<?php
// ============================================================
// creditos/eliminar.php — Eliminar un crédito (solo admin)
// Solo se permite si no existen pagos confirmados.
// Restaura stock del artículo si corresponde.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

if (!es_admin()) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Sin permiso para eliminar créditos.'];
    header('Location: index');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$id = (int) ($_POST['credito_id'] ?? 0);
if (!$id) {
    header('Location: index');
    exit;
}

$pdo = obtener_conexion();

// Cargar crédito
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();

if (!$cr) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Crédito no encontrado.'];
    header('Location: index');
    exit;
}

// Verificar que no tenga pagos confirmados
$chk = $pdo->prepare("
    SELECT COUNT(*) FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu ON pc.cuota_id = cu.id
    WHERE cu.credito_id = ?
");
$chk->execute([$id]);
$cant_confirmados = (int) $chk->fetchColumn();

if ($cant_confirmados > 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' =>
        "No se puede eliminar el crédito #{$id}: tiene {$cant_confirmados} pago(s) confirmado(s). " .
        "Si desea darlo de baja use la opción Finalizar."
    ];
    header("Location: ver?id=$id");
    exit;
}

try {
    $pdo->beginTransaction();

    // Obtener cuota_ids para borrar pagos_temporales
    $cuota_ids_stmt = $pdo->prepare("SELECT id FROM ic_cuotas WHERE credito_id = ?");
    $cuota_ids_stmt->execute([$id]);
    $cuota_ids = $cuota_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Borrar pagos temporales (no tienen CASCADE desde cuotas)
    if (!empty($cuota_ids)) {
        $ph = implode(',', array_fill(0, count($cuota_ids), '?'));
        $pdo->prepare("DELETE FROM ic_pagos_temporales WHERE cuota_id IN ($ph)")->execute($cuota_ids);
    }

    // Restaurar stock del artículo si corresponde
    if ($cr['articulo_id']) {
        $pdo->prepare("UPDATE ic_articulos SET stock = stock + 1 WHERE id = ?")
            ->execute([$cr['articulo_id']]);
    }

    // Eliminar crédito (ON DELETE CASCADE elimina ic_cuotas automáticamente)
    $pdo->prepare("DELETE FROM ic_creditos WHERE id = ?")->execute([$id]);

    registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_ELIMINADO', 'credito', $id,
        "Crédito #{$id} eliminado. Cliente: {$cr['apellidos']}, {$cr['nombres']}");

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Crédito #{$id} eliminado correctamente."];
    header('Location: index');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al eliminar el crédito: ' . $e->getMessage()];
    header("Location: ver?id=$id");
    exit;
}
