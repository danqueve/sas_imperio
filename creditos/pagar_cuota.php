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

// Fecha de pago elegida (puede ser distinta a hoy para retroactivos)
$fecha_pago_input = trim($_POST['fecha_pago'] ?? '');
$fecha_hoy_real   = date('Y-m-d');
// Validar formato y que no sea una fecha futura
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago_input) || $fecha_pago_input > $fecha_hoy_real) {
    $fecha_pago_input = $fecha_hoy_real;
}

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
$fecha_hoy    = $fecha_pago_input; // usa la fecha elegida (puede ser retroactiva)

// Cuotas pendientes/vencidas/parciales del crédito, de más antigua a más nueva
$cuotas_stmt = $pdo->prepare("
    SELECT id, numero_cuota, monto_cuota, fecha_vencimiento, saldo_pagado, monto_mora
    FROM ic_cuotas
    WHERE credito_id = ? AND estado IN ('PENDIENTE', 'VENCIDA', 'PARCIAL')
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

        $saldo_prev  = (float) ($cuota['saldo_pagado'] ?? 0);
        $mora_frozen = (float) $cuota['monto_mora'];
        // Calcular mora usando la fecha de pago elegida (no necesariamente hoy)
        $dias_atraso = dias_atraso_habiles($cuota['fecha_vencimiento'], $fecha_hoy);

        // Congelar mora si aún no está fijada en la cuota
        if ($mora_frozen <= 0) {
            $mora_frozen = calcular_mora($cuota['monto_cuota'], $dias_atraso, $pct_mora);
        }

        $total_cuota = $cuota['monto_cuota'] + $mora_frozen;
        $pendiente   = max(0, $total_cuota - $saldo_prev);

        if ($pendiente <= 0.005) continue;

        $pago_en_esta = min($remaining, $pendiente);

        // Distribuir: primero efectivo, luego transferencia
        $pago_ef = min($pago_en_esta, $ef_remaining);
        $pago_tr = $pago_en_esta - $pago_ef;

        $nuevo_saldo  = $saldo_prev + $pago_en_esta;
        $nuevo_estado = ($nuevo_saldo >= $cuota['monto_cuota'] + $mora_frozen - 0.005) ? 'PAGADA' : 'PARCIAL';
        $fecha_pago_v = ($nuevo_estado === 'PAGADA') ? $fecha_hoy : null;
        $mora_en_esta = ($nuevo_estado === 'PAGADA') ? $mora_frozen : 0.0;
        
        // Si no está pagada totalmente, mantenemos la mora de la BD (así no congelamos si el pago es parcial)
        $monto_mora_guardar = ($nuevo_estado === 'PAGADA') ? $mora_frozen : (float) $cuota['monto_mora'];

        // 1. Insertar pago_temporal ya aprobado (para mantener integridad referencial)
        $pdo->prepare("
            INSERT INTO ic_pagos_temporales
              (cuota_id, cobrador_id, monto_efectivo, monto_transferencia,
               monto_total, monto_mora_cobrada, estado, fecha_jornada, origen)
            VALUES (?, ?, ?, ?, ?, ?, 'APROBADO', ?, 'manual')
        ")->execute([$cuota['id'], $cobrador_id, $pago_ef, $pago_tr, $pago_en_esta, $mora_en_esta, fecha_jornada()]);
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

        // 3. Actualizar cuota: PAGADA o PARCIAL según saldo acumulado
        $pdo->prepare("UPDATE ic_cuotas SET estado=?, saldo_pagado=?, monto_mora=?, fecha_pago=? WHERE id=?")
            ->execute([$nuevo_estado, $nuevo_saldo, $monto_mora_guardar, $fecha_pago_v, $cuota['id']]);

        registrar_log($pdo, $aprobador_id, 'PAGO_DIRECTO', 'cuota', $cuota['id'],
            'Cuota #' . $cuota['numero_cuota'] . ' [' . $nuevo_estado . '] — Ef: ' . formato_pesos($pago_ef) . ' | Tr: ' . formato_pesos($pago_tr));

        $ef_remaining -= $pago_ef;
        $tr_remaining -= $pago_tr;
        $remaining    -= $pago_en_esta;
        $cuotas_ok++;
    }

    // Recalcular estado del crédito (FINALIZADO / MOROSO / EN_CURSO)
    $check = $pdo->prepare("
        SELECT SUM(CASE WHEN estado != 'PAGADA' THEN 1 ELSE 0 END) AS pendientes,
               SUM(CASE WHEN estado = 'VENCIDA' THEN 1 ELSE 0 END) AS vencidas
        FROM ic_cuotas WHERE credito_id = ?
    ");
    $check->execute([$credito_id]);
    $counts = $check->fetch(PDO::FETCH_ASSOC);
    if ((int)$counts['pendientes'] === 0)    $nuevo_cr_estado = 'FINALIZADO';
    elseif ((int)$counts['vencidas'] > 0)    $nuevo_cr_estado = 'MOROSO';
    else                                     $nuevo_cr_estado = 'EN_CURSO';
    $pdo->prepare("UPDATE ic_creditos SET estado=? WHERE id=?")->execute([$nuevo_cr_estado, $credito_id]);

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
