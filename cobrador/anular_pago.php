<?php
// cobrador/anular_pago.php — Anula un pago PENDIENTE del cobrador
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_pagos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'cobrador/agenda');
    exit;
}
verificar_csrf();

$pdo   = obtener_conexion();
$pt_id = (int) ($_POST['pt_id'] ?? 0);
$uid   = (int) $_SESSION['user_id'];
$rol   = $_SESSION['rol'] ?? '';

if (!$pt_id) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'ID de pago inválido.'];
    header('Location: ' . BASE_URL . 'cobrador/agenda');
    exit;
}

// Admin y supervisor pueden anular cualquier pago PENDIENTE;
// cobrador solo puede anular los propios.
if (in_array($rol, ['admin', 'supervisor'], true)) {
    $stmt = $pdo->prepare("
        SELECT pt.id, pt.cobrador_id, pt.monto_total, cu.numero_cuota, cr.id AS credito_id, cl.apellidos, cl.nombres, cl.dni
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        JOIN ic_creditos cr ON cr.id = cu.credito_id
        JOIN ic_clientes cl ON cl.id = cr.cliente_id
        WHERE pt.id = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$pt_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT pt.id, pt.cobrador_id, pt.monto_total, cu.numero_cuota, cr.id AS credito_id, cl.apellidos, cl.nombres, cl.dni
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        JOIN ic_creditos cr ON cr.id = cu.credito_id
        JOIN ic_clientes cl ON cl.id = cr.cliente_id
        WHERE pt.id = ? AND pt.cobrador_id = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$pt_id, $uid]);
}
$row = $stmt->fetch();

if (!$row) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Pago no encontrado o ya fue aprobado por el supervisor.'];
    header('Location: ' . BASE_URL . 'cobrador/agenda');
    exit;
}

if (es_super_admin()) {
    try {
        $res = ejecutar_anulacion_pago_temporal($pdo, $pt_id, $uid);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pago de cuota #' . $res['numero_cuota'] . ' anulado correctamente.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al anular el pago.'];
    }
} else {
    $motivo = trim($_POST['motivo'] ?? '');
    if (!$motivo) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Completá el motivo de la solicitud.'];
    } else {
        $detalle_sol = 'Cliente: ' . $row['apellidos'] . ', ' . $row['nombres']
            . ' — Cuota #' . $row['numero_cuota'] . ' — ' . formato_pesos($row['monto_total']);
        $sol_id = crear_solicitud_autorizacion($pdo, 'anular_pago_temporal', 'pago_temporal', $pt_id, ['credito_id' => $row['credito_id']], $motivo, $uid, $detalle_sol);
        registrar_log($pdo, $uid, 'SOLICITUD_AUTORIZACION_CREADA', 'pago_temporal', $pt_id,
            'Solicitud #' . $sol_id . ' para anular pago — Cuota #' . $row['numero_cuota']
            . ' — Cliente: ' . $row['apellidos'] . ', ' . $row['nombres'] . ' — Motivo: ' . $motivo);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Solicitud enviada. Un super admin la va a revisar.'];
    }
}
header('Location: ' . BASE_URL . 'cobrador/agenda');
exit;
