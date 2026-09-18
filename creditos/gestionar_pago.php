<?php
// creditos/gestionar_pago.php — Anulación y solicitudes de baja de pagos confirmados
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}
verificar_csrf();

$pdo        = obtener_conexion();
$accion     = $_POST['accion'] ?? '';
$credito_id = (int) ($_POST['credito_id'] ?? 0);
$uid        = (int) $_SESSION['user_id'];
$back       = 'ver?id=' . $credito_id;

// ── Admin: revertir pago confirmado (directo si es super admin) ──
if ($accion === 'revertir_confirmado') {
    if (!es_admin()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Solo los administradores pueden revertir pagos.'];
        header('Location: ' . $back);
        exit;
    }

    $pc_id = (int) ($_POST['pago_conf_id'] ?? 0);
    if (!$pc_id || !$credito_id) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Datos inválidos.'];
        header('Location: ' . $back);
        exit;
    }

    if (!es_super_admin()) {
        // Admin regular: no ejecuta directo, esto se maneja en la acción
        // 'solicitar_autorizacion_revertir' (ver más abajo) — no debería
        // llegar acá porque el modal correspondiente ya postea a esa acción,
        // pero por si alguien fuerza el POST viejo, no lo dejamos ejecutar.
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Necesitás autorización de un super admin para revertir un pago. Usá "Solicitar Autorización".'];
        header('Location: ' . $back);
        exit;
    }

    // Verificar que el pago existe antes de mostrar el mensaje de éxito
    $chk = $pdo->prepare("SELECT id FROM ic_pagos_confirmados WHERE id = ?");
    $chk->execute([$pc_id]);
    if (!$chk->fetchColumn()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Pago no encontrado.'];
        header('Location: ' . $back);
        exit;
    }

    try {
        $nuevo_estado_cuota = ejecutar_reversion_pago_confirmado($pdo, $pc_id, $credito_id, $uid);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pago revertido. La cuota volvió a estado ' . $nuevo_estado_cuota . '.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al revertir el pago.'];
    }

    header('Location: ' . $back);
    exit;
}

// ── Admin regular: solicitar autorización de un super admin ─────
if ($accion === 'solicitar_autorizacion_revertir') {
    if (!es_admin() || es_super_admin()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Acceso denegado.'];
        header('Location: ' . $back);
        exit;
    }

    $pc_id  = (int) ($_POST['pago_conf_id'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');

    if (!$pc_id || !$credito_id || !$motivo) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Completá el motivo de la solicitud.'];
        header('Location: ' . $back);
        exit;
    }

    $chk = $pdo->prepare("
        SELECT pc.monto_total, cu.numero_cuota, cl.apellidos, cl.nombres
        FROM ic_pagos_confirmados pc
        JOIN ic_cuotas cu ON cu.id = pc.cuota_id
        JOIN ic_creditos cr2 ON cr2.id = cu.credito_id
        JOIN ic_clientes cl ON cl.id = cr2.cliente_id
        WHERE pc.id = ?
    ");
    $chk->execute([$pc_id]);
    $pc_info = $chk->fetch();
    if (!$pc_info) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Pago no encontrado.'];
        header('Location: ' . $back);
        exit;
    }

    $detalle_sol = 'Cliente: ' . $pc_info['apellidos'] . ', ' . $pc_info['nombres']
        . ' — Cuota #' . $pc_info['numero_cuota'] . ' — ' . formato_pesos($pc_info['monto_total']);

    $sol_id = crear_solicitud_autorizacion(
        $pdo, 'revertir_pago_confirmado', 'pago_confirmado', $pc_id,
        ['credito_id' => $credito_id], $motivo, $uid, $detalle_sol
    );
    registrar_log($pdo, $uid, 'SOLICITUD_AUTORIZACION_CREADA', 'pago_confirmado', $pc_id,
        'Solicitud #' . $sol_id . ' para revertir pago — Crédito #' . $credito_id . ' — Motivo: ' . $motivo);

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Solicitud enviada. Un super admin la va a revisar.'];
    header('Location: ' . $back);
    exit;
}

// ── Supervisor: solicitar reversa de pago confirmado ─────────
if ($accion === 'solicitar_baja_confirmado') {
    if (!es_supervisor() && !es_admin()) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Acceso denegado.'];
        header('Location: ' . $back);
        exit;
    }

    $pc_id  = (int) ($_POST['pago_conf_id'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');

    if (!$pc_id || !$motivo) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Completá el motivo de la solicitud.'];
        header('Location: ' . $back);
        exit;
    }

    $pdo->prepare("UPDATE ic_pagos_confirmados SET solicitud_baja=1, motivo_baja=? WHERE id=?")
        ->execute([$motivo, $pc_id]);

    registrar_log($pdo, $uid, 'SOLICITUD_BAJA_PAGO', 'pago_confirmado', $pc_id, $motivo);
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Solicitud enviada. El administrador revisará la reversa.'];

    header('Location: ' . $back);
    exit;
}

// Acción desconocida
header('Location: ' . $back);
exit;
