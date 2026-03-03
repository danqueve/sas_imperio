<?php
// creditos/pagar_cuota.php — Pago directo por admin/supervisor (sin pasar por rendición)
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

if (!es_admin() && !es_supervisor()) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Acceso denegado.'];
    header('Location: index');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$pdo        = obtener_conexion();
$cuota_id   = (int) ($_POST['cuota_id'] ?? 0);
$credito_id = (int) ($_POST['credito_id'] ?? 0);
$ef         = (float) ($_POST['monto_efectivo'] ?? 0);
$tr         = (float) ($_POST['monto_transferencia'] ?? 0);
$total      = $ef + $tr;

if (!$cuota_id || !$credito_id || $total <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Datos inválidos.'];
    header('Location: ver?id=' . $credito_id);
    exit;
}

// Obtener cobrador e interes_moratorio del crédito
$stmt = $pdo->prepare("SELECT cobrador_id, interes_moratorio_pct, estado FROM ic_creditos WHERE id = ?");
$stmt->execute([$credito_id]);
$cr = $stmt->fetch();

if (!$cr || !in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Crédito no encontrado o no activo.'];
    header('Location: ver?id=' . $credito_id);
    exit;
}

$cobrador_id  = (int) $cr['cobrador_id'];
$pct_mora     = (float) $cr['interes_moratorio_pct'];
$aprobador_id = (int) $_SESSION['user_id'];
$fecha_hoy    = date('Y-m-d');

// Cuotas pendientes/vencidas del crédito, de más antigua a más nueva
$cuotas_stmt = $pdo->prepare("
    SELECT id, numero_cuota, monto_cuota, fecha_vencimiento
    FROM ic_cuotas
    WHERE credito_id = ? AND estado IN ('PENDIENTE', 'VENCIDA')
    ORDER BY numero_cuota ASC
");
$cuotas_stmt->execute([$credito_id]);
$cuotas_pendientes = $cuotas_stmt->fetchAll();

$remaining    = $total;
$ef_remaining = $ef;
$tr_remaining = $tr;
$cuotas_ok    = 0;

try {
    $pdo->beginTransaction();

    foreach ($cuotas_pendientes as $cuota) {
        if ($remaining <= 0.005) break;

        $dias_atraso = dias_atraso_habiles($cuota['fecha_vencimiento']);
        $mora_cuota  = calcular_mora($cuota['monto_cuota'], $dias_atraso, $pct_mora);
        $total_cuota = $cuota['monto_cuota'] + $mora_cuota;

        $pago_en_esta = min($remaining, $total_cuota);

        // Distribuir: primero efectivo, luego transferencia
        $pago_ef = min($pago_en_esta, $ef_remaining);
        $pago_tr = $pago_en_esta - $pago_ef;

        // Mora sólo si cubre el total de la cuota
        $mora_en_esta = ($pago_en_esta >= $total_cuota - 0.005) ? $mora_cuota : 0.0;

        // 1. Insertar pago_temporal ya aprobado (para mantener integridad referencial)
        $pdo->prepare("
            INSERT INTO ic_pagos_temporales
              (cuota_id, cobrador_id, monto_efectivo, monto_transferencia,
               monto_total, monto_mora_cobrada, estado)
            VALUES (?, ?, ?, ?, ?, ?, 'APROBADO')
        ")->execute([$cuota['id'], $cobrador_id, $pago_ef, $pago_tr, $pago_en_esta, $mora_en_esta]);
        $pago_temp_id = (int) $pdo->lastInsertId();

        // 2. Insertar pago confirmado
        $pdo->prepare("
            INSERT INTO ic_pagos_confirmados
              (pago_temp_id, cuota_id, cobrador_id, aprobador_id, fecha_pago,
               monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $pago_temp_id, $cuota['id'], $cobrador_id, $aprobador_id, $fecha_hoy,
            $pago_ef, $pago_tr, $pago_en_esta, $mora_en_esta,
        ]);

        // 3. Marcar cuota como PAGADA
        $pdo->prepare("UPDATE ic_cuotas SET estado='PAGADA', fecha_pago=? WHERE id=?")
            ->execute([$fecha_hoy, $cuota['id']]);

        registrar_log($pdo, $aprobador_id, 'PAGO_DIRECTO', 'cuota', $cuota['id'],
            'Cuota #' . $cuota['numero_cuota'] . ' — Ef: ' . formato_pesos($pago_ef) . ' | Tr: ' . formato_pesos($pago_tr));

        $ef_remaining -= $pago_ef;
        $tr_remaining -= $pago_tr;
        $remaining    -= $pago_en_esta;
        $cuotas_ok++;
    }

    // Verificar si el crédito quedó completamente pagado
    $check = $pdo->prepare("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id = ? AND estado != 'PAGADA'");
    $check->execute([$credito_id]);
    if ((int) $check->fetchColumn() === 0) {
        $pdo->prepare("UPDATE ic_creditos SET estado='FINALIZADO' WHERE id=?")
            ->execute([$credito_id]);
    }

    $pdo->commit();

    $msg = $cuotas_ok > 1
        ? "Pago directo registrado para {$cuotas_ok} cuotas."
        : 'Pago registrado correctamente.';
    $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al registrar el pago.'];
}

header('Location: ver?id=' . $credito_id);
exit;
