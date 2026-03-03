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

$pdo        = obtener_conexion();
$accion     = $_POST['accion'] ?? '';
$credito_id = (int) ($_POST['credito_id'] ?? 0);
$uid        = (int) $_SESSION['user_id'];
$back       = 'ver?id=' . $credito_id;

// ── Admin: revertir pago confirmado ──────────────────────────
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

    // Obtener datos del pago confirmado
    $stmt = $pdo->prepare("SELECT * FROM ic_pagos_confirmados WHERE id = ?");
    $stmt->execute([$pc_id]);
    $pc = $stmt->fetch();

    if (!$pc) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Pago no encontrado.'];
        header('Location: ' . $back);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Obtener fecha_vencimiento de la cuota para determinar nuevo estado
        $venc = $pdo->prepare("SELECT fecha_vencimiento FROM ic_cuotas WHERE id = ?");
        $venc->execute([$pc['cuota_id']]);
        $cuota_row = $venc->fetch();
        $nuevo_estado_cuota = (new DateTime($cuota_row['fecha_vencimiento'])) < new DateTime('today')
            ? 'VENCIDA' : 'PENDIENTE';

        // 2. Revertir cuota
        $pdo->prepare("UPDATE ic_cuotas SET estado=?, fecha_pago=NULL WHERE id=?")
            ->execute([$nuevo_estado_cuota, $pc['cuota_id']]);

        // 3. Marcar pago temporal como RECHAZADO (reversal)
        $pdo->prepare("UPDATE ic_pagos_temporales SET estado='RECHAZADO', observaciones='Revertido por admin' WHERE id=?")
            ->execute([$pc['pago_temp_id']]);

        // 4. Eliminar pago confirmado
        $pdo->prepare("DELETE FROM ic_pagos_confirmados WHERE id=?")
            ->execute([$pc_id]);

        // 5. Si el crédito estaba FINALIZADO, reactivarlo
        $cr_stmt = $pdo->prepare("SELECT estado FROM ic_creditos WHERE id=?");
        $cr_stmt->execute([$credito_id]);
        if ($cr_stmt->fetchColumn() === 'FINALIZADO') {
            $venc_check = $pdo->prepare("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=? AND estado='VENCIDA'");
            $venc_check->execute([$credito_id]);
            $nuevo_cr = (int) $venc_check->fetchColumn() > 0 ? 'MOROSO' : 'EN_CURSO';
            $pdo->prepare("UPDATE ic_creditos SET estado=? WHERE id=?")
                ->execute([$nuevo_cr, $credito_id]);
        }

        $pdo->commit();
        registrar_log($pdo, $uid, 'PAGO_REVERTIDO', 'pago_confirmado', $pc_id,
            'Cuota #' . $pc['cuota_id'] . ' — Crédito #' . $credito_id);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pago revertido. La cuota volvió a estado ' . $nuevo_estado_cuota . '.'];

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al revertir el pago.'];
    }

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
