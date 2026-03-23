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

        $cuota_id_rev = (int) $pc['cuota_id'];

        // 1. Marcar pago temporal como RECHAZADO (reversal)
        $pdo->prepare("UPDATE ic_pagos_temporales SET estado='RECHAZADO', observaciones='Revertido por admin' WHERE id=?")
            ->execute([$pc['pago_temp_id']]);

        // 2. Eliminar pago confirmado
        $pdo->prepare("DELETE FROM ic_pagos_confirmados WHERE id=?")
            ->execute([$pc_id]);

        // 3. Recalcular saldo_pagado real sumando los pagos confirmados que quedan para esta cuota
        $saldo_stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_total), 0) FROM ic_pagos_confirmados WHERE cuota_id = ?");
        $saldo_stmt->execute([$cuota_id_rev]);
        $saldo_restante = (float) $saldo_stmt->fetchColumn();

        // 4. Obtener datos de la cuota para determinar nuevo estado
        $venc = $pdo->prepare("SELECT fecha_vencimiento, monto_cuota, monto_mora FROM ic_cuotas WHERE id = ?");
        $venc->execute([$cuota_id_rev]);
        $cuota_row = $venc->fetch();

        if ($saldo_restante >= ($cuota_row['monto_cuota'] - 0.005)) {
            // Aún cubre el capital: sigue PAGADA (no debería ocurrir normalmente)
            $nuevo_estado_cuota = 'PAGADA';
        } elseif ($saldo_restante > 0.005) {
            // Pago parcial restante
            $nuevo_estado_cuota = 'PARCIAL';
        } else {
            // Sin saldo: PENDIENTE o VENCIDA según fecha
            $saldo_restante = 0.0;
            $nuevo_estado_cuota = (new DateTime($cuota_row['fecha_vencimiento'])) < new DateTime('today')
                ? 'VENCIDA' : 'PENDIENTE';
        }

        // Fase 5: Mantener mora congelada si hay saldo parcial; recalcular si queda sin pago
        $mora_guardar = ($nuevo_estado_cuota === 'PAGADA')
            ? (float) $cuota_row['monto_mora']                  // mantener congelada
            : ($saldo_restante > 0 ? (float) $cuota_row['monto_mora'] : 0.0); // parcial: mantener; sin saldo: reset

        // 5. Actualizar cuota con el saldo real recalculado
        $fecha_pago_v = ($nuevo_estado_cuota === 'PAGADA') ? date('Y-m-d') : null;
        $pdo->prepare("UPDATE ic_cuotas SET estado=?, saldo_pagado=?, monto_mora=?, fecha_pago=? WHERE id=?")
            ->execute([$nuevo_estado_cuota, $saldo_restante, $mora_guardar, $fecha_pago_v, $cuota_id_rev]);

        // 6. Recalcular estado del crédito (puede pasar FINALIZADO→EN_CURSO/MOROSO, o EN_CURSO→MOROSO)
        $cr_stmt = $pdo->prepare("SELECT estado FROM ic_creditos WHERE id=?");
        $cr_stmt->execute([$credito_id]);
        $estado_cr_actual = $cr_stmt->fetchColumn();
        if ($estado_cr_actual !== 'CANCELADO') {
            $venc_check = $pdo->prepare("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=? AND estado='VENCIDA'");
            $venc_check->execute([$credito_id]);
            $tiene_vencidas = (int) $venc_check->fetchColumn() > 0;
            $nuevo_cr = $tiene_vencidas ? 'MOROSO' : 'EN_CURSO';
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
