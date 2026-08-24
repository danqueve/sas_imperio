<?php
// creditos/editar_vencimiento_cuota.php — Reprograma el vencimiento de una
// cuota puntual (gracia por artículo en reparación, etc.) y corre todas las
// cuotas siguientes del crédito la misma cantidad de días.
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones'); // admin o supervisor

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}
verificar_csrf();

$pdo         = obtener_conexion();
$cuota_id    = (int) ($_POST['cuota_id'] ?? 0);
$credito_id  = (int) ($_POST['credito_id'] ?? 0);
$nueva_fecha = trim($_POST['nueva_fecha'] ?? '');
$motivo      = trim($_POST['motivo'] ?? '');

if (!$cuota_id || !$credito_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nueva_fecha) || $motivo === '') {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Faltan datos obligatorios: nueva fecha y motivo del cambio.'];
    header('Location: ver?id=' . $credito_id);
    exit;
}

try {
    $pdo->beginTransaction();

    $cr_stmt = $pdo->prepare("SELECT id, estado, frecuencia, dia_cobro FROM ic_creditos WHERE id = ? FOR UPDATE");
    $cr_stmt->execute([$credito_id]);
    $cr = $cr_stmt->fetch();
    if (!$cr || !in_array($cr['estado'], ['EN_CURSO', 'MOROSO'], true)) {
        throw new Exception('El crédito no está activo.');
    }
    if ($cr['frecuencia'] === 'diario') {
        throw new Exception('No se puede reprogramar cuotas de un crédito diario.');
    }

    $cu_stmt = $pdo->prepare("SELECT * FROM ic_cuotas WHERE id = ? AND credito_id = ? FOR UPDATE");
    $cu_stmt->execute([$cuota_id, $credito_id]);
    $cuota = $cu_stmt->fetch();
    if (!$cuota || !in_array($cuota['estado'], ['PENDIENTE', 'VENCIDA', 'PARCIAL'], true)) {
        throw new Exception('La cuota no existe o no se puede reprogramar en su estado actual.');
    }

    $fecha_anterior = $cuota['fecha_vencimiento'];
    $delta_dias = (int) round((strtotime($nueva_fecha) - strtotime($fecha_anterior)) / 86400);
    if ($delta_dias === 0) {
        throw new Exception('La nueva fecha es igual a la actual.');
    }

    // No permitir que la nueva fecha quede antes (o igual) que la de la cuota anterior
    if ($cuota['numero_cuota'] > 1) {
        $prev_stmt = $pdo->prepare("SELECT fecha_vencimiento FROM ic_cuotas WHERE credito_id = ? AND numero_cuota = ?");
        $prev_stmt->execute([$credito_id, $cuota['numero_cuota'] - 1]);
        $fecha_prev = $prev_stmt->fetchColumn();
        if ($fecha_prev && $nueva_fecha <= $fecha_prev) {
            throw new Exception('La nueva fecha debe ser posterior al vencimiento de la cuota anterior (#'
                . ($cuota['numero_cuota'] - 1) . ': ' . date('d/m/Y', strtotime($fecha_prev)) . ').');
        }
    }

    // Cuotas a desplazar: la elegida y todas las siguientes
    $siguientes_stmt = $pdo->prepare("
        SELECT id FROM ic_cuotas WHERE credito_id = ? AND numero_cuota >= ? ORDER BY numero_cuota FOR UPDATE
    ");
    $siguientes_stmt->execute([$credito_id, $cuota['numero_cuota']]);
    $siguientes_ids = $siguientes_stmt->fetchAll(PDO::FETCH_COLUMN);

    $upd = $pdo->prepare("UPDATE ic_cuotas SET fecha_vencimiento = DATE_ADD(fecha_vencimiento, INTERVAL ? DAY) WHERE id = ?");
    foreach ($siguientes_ids as $sid) {
        $upd->execute([$delta_dias, $sid]);
    }

    // Las que quedaron VENCIDA pero cuya nueva fecha ya no es pasada, vuelven a PENDIENTE
    $pdo->prepare("
        UPDATE ic_cuotas SET estado = 'PENDIENTE'
        WHERE credito_id = ? AND numero_cuota >= ? AND estado = 'VENCIDA' AND fecha_vencimiento >= CURDATE()
    ")->execute([$credito_id, $cuota['numero_cuota']]);

    // Recalcular estado del crédito (mismo criterio que el resto del sistema)
    $chk = $pdo->prepare("
        SELECT
          SUM(CASE WHEN estado NOT IN ('PAGADA','CANCELADA') THEN 1 ELSE 0 END) AS pendientes,
          SUM(CASE WHEN estado='VENCIDA' OR (estado='PARCIAL' AND fecha_vencimiento < CURDATE()) THEN 1 ELSE 0 END) AS vencidas
        FROM ic_cuotas WHERE credito_id = ?
    ");
    $chk->execute([$credito_id]);
    $r = $chk->fetch();
    $nuevo_estado_cr = ((int) $r['vencidas'] > 0) ? 'MOROSO' : 'EN_CURSO';
    $pdo->prepare("UPDATE ic_creditos SET estado = ? WHERE id = ?")->execute([$nuevo_estado_cr, $credito_id]);

    $cant_desplazadas = count($siguientes_ids);
    $pdo->prepare("
        INSERT INTO ic_cuota_vencimiento_historial
            (credito_id, cuota_id, numero_cuota, fecha_anterior, fecha_nueva, dias_shift, cuotas_desplazadas, motivo, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $credito_id, $cuota_id, $cuota['numero_cuota'], $fecha_anterior, $nueva_fecha,
        $delta_dias, $cant_desplazadas, $motivo, $_SESSION['user_id'],
    ]);

    registrar_log($pdo, $_SESSION['user_id'], 'CUOTA_VENCIMIENTO_EDITADO', 'cuota', $cuota_id,
        'Cuota #' . $cuota['numero_cuota'] . ' ' . date('d/m/Y', strtotime($fecha_anterior)) . ' -> '
        . date('d/m/Y', strtotime($nueva_fecha)) . ' (' . $cant_desplazadas . ' cuotas desplazadas) - ' . mb_substr($motivo, 0, 120));

    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Vencimiento de la cuota #' . $cuota['numero_cuota']
        . ' actualizado. Se reprogramaron ' . $cant_desplazadas . ' cuota(s).'];
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('editar_vencimiento_cuota error: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Ocurrió un error al reprogramar la cuota. Intente nuevamente.'];
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
}
header('Location: ver?id=' . $credito_id);
exit;
