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
verificar_csrf();

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

// Verificar que no tenga pagos temporales PENDIENTE (efectivo ya cobrado por el cobrador,
// aun no aprobado) — eliminarlo borraría ese cobro sin dejar ningun rastro del dinero.
$chk_pend = $pdo->prepare("
    SELECT COUNT(*) FROM ic_pagos_temporales pt
    JOIN ic_cuotas cu ON pt.cuota_id = cu.id
    WHERE cu.credito_id = ? AND pt.estado = 'PENDIENTE'
");
$chk_pend->execute([$id]);
if ((int) $chk_pend->fetchColumn() > 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' =>
        "No se puede eliminar el crédito #{$id}: tiene pagos pendientes de aprobación. " .
        "Apruebe o rechace esa rendición antes de eliminar el crédito."
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

    // Borrar pagos temporales (no tienen CASCADE desde cuotas) — ya no pueden ser PENDIENTE,
    // solo quedan aca los RECHAZADO/APROBADO (estos ultimos ya bloqueados arriba por confirmados)
    if (!empty($cuota_ids)) {
        $ph = implode(',', array_fill(0, count($cuota_ids), '?'));
        $pdo->prepare("DELETE FROM ic_pagos_temporales WHERE cuota_id IN ($ph)")->execute($cuota_ids);
    }

    // Restaurar stock: articulo simple, o cada item si es un credito combo
    if ($cr['articulo_id']) {
        $pdo->prepare("UPDATE ic_articulos SET stock = stock + 1 WHERE id = ?")
            ->execute([$cr['articulo_id']]);
    }
    $combo_items = $pdo->prepare("
        SELECT articulo_id, cantidad FROM ic_credito_articulos
        WHERE credito_id = ? AND articulo_id IS NOT NULL
    ");
    $combo_items->execute([$id]);
    $upd_stock = $pdo->prepare("UPDATE ic_articulos SET stock = stock + ? WHERE id = ?");
    foreach ($combo_items->fetchAll() as $item) {
        $upd_stock->execute([(int) $item['cantidad'], (int) $item['articulo_id']]);
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
    error_log('creditos/eliminar error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al eliminar el crédito. Intente nuevamente.'];
    header("Location: ver?id=$id");
    exit;
}
