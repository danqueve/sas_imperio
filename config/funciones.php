<?php
// ============================================================
// Sistema Imperio Comercial — Funciones de Negocio
// ============================================================

// ── Mora ─────────────────────────────────────────────────────

/**
 * Calcula mora prorateada a días hábiles.
 * 15% semanal / 6 días hábiles = 2.5% por día hábil de atraso.
 */
function calcular_mora(float $monto_cuota, int $dias_habiles_atraso, float $pct_mora_semanal = 15.0): float
{
    if ($dias_habiles_atraso <= 0)
        return 0.0;
    $pct_diario = $pct_mora_semanal / 6.0; // 2.5%
    return round($monto_cuota * ($pct_diario / 100) * $dias_habiles_atraso, 2);
}

/**
 * Cuenta días hábiles (Lun–Sáb) entre dos fechas (incluyendo extremos).
 */
function dias_habiles(DateTime $desde, DateTime $hasta): int
{
    if ($desde > $hasta)
        return 0;
    $count = 0;
    $current = clone $desde;
    while ($current <= $hasta) {
        $dow = (int) $current->format('N'); // 1=Lun, 7=Dom
        if ($dow !== 7) { // excluir domingo
            $count++;
        }
        $current->modify('+1 day');
    }
    return $count;
}

/**
 * Calcula días hábiles de atraso desde el vencimiento hasta hoy (o hasta $fecha_ref si se indica).
 */
function dias_atraso_habiles(string $fecha_vencimiento, ?string $fecha_ref = null): int
{
    $hoy  = $fecha_ref ? new DateTime($fecha_ref) : new DateTime('today');
    $venc = new DateTime($fecha_vencimiento);
    if ($hoy <= $venc)
        return 0;
    // Días hábiles desde el día siguiente al vencimiento hasta la fecha de referencia
    $desde = clone $venc;
    $desde->modify('+1 day');
    return dias_habiles($desde, $hoy);
}

// ── Cuotas ───────────────────────────────────────────────────

/**
 * Determina si una cuota está pagada en su totalidad o de forma parcial,
 * considerando un margen de tolerancia para errores de redondeo de centavos.
 */
function determinar_estado_cuota(float $monto_base, float $mora, float $saldo_pagado): string
{
    return ($saldo_pagado >= $monto_base + $mora - 0.005) ? 'PAGADA' : 'PARCIAL';
}


/**
 * Genera las cuotas de un crédito en la base de datos.
 */
function generar_cuotas(int $credito_id, array $d, PDO $pdo): bool
{
    $fecha = new DateTime($d['primer_vencimiento']);
    $stmt = $pdo->prepare("INSERT INTO ic_cuotas (credito_id, numero_cuota, fecha_vencimiento, monto_cuota) VALUES (?, ?, ?, ?)");

    for ($i = 1; $i <= $d['cant_cuotas']; $i++) {
        if ($i > 1) {
            switch ($d['frecuencia']) {
                case 'semanal':
                    $fecha->modify('+7 days');
                    break;
                case 'quincenal':
                    $fecha->modify('+15 days');
                    break;
                case 'mensual':
                    $fecha->modify('+1 month');
                    break;
            }
        }
        $stmt->execute([$credito_id, $i, $fecha->format('Y-m-d'), $d['monto_cuota']]);
    }
    return true;
}

// ── Aprobación de rendición ───────────────────────────────────

/**
 * Aprueba todos los pagos pendientes de un cobrador para una fecha dada.
 */
function aprobar_rendicion(int $cobrador_id, string $fecha, int $aprobador_id, PDO $pdo): array
{
    $resultado = ['aprobados' => 0, 'errores' => 0];

    $stmt = $pdo->prepare("
        SELECT pt.*, cu.credito_id, cu.monto_cuota, cu.saldo_pagado, cu.monto_mora,
               cu.fecha_vencimiento, cr.interes_moratorio_pct
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu ON pt.cuota_id = cu.id
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE pt.cobrador_id = ? AND DATE(pt.fecha_registro) = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$cobrador_id, $fecha]);
    $pagos = $stmt->fetchAll();

    foreach ($pagos as $pago) {
        try {
            $pdo->beginTransaction();

            // 1. Insertar en pagos_confirmados
            $ins = $pdo->prepare("
                INSERT INTO ic_pagos_confirmados
                    (pago_temp_id, cuota_id, cobrador_id, aprobador_id, fecha_pago,
                     monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $pago['id'],
                $pago['cuota_id'],
                $pago['cobrador_id'],
                $aprobador_id,
                $fecha,
                $pago['monto_efectivo'],
                $pago['monto_transferencia'],
                $pago['monto_total'],
                $pago['monto_mora_cobrada']
            ]);

            // 2. Calcular nuevo saldo y estado de la cuota
            $monto_base  = (float) $pago['monto_cuota'];
            $saldo_prev  = (float) ($pago['saldo_pagado'] ?? 0);
            $mora_frozen = (float) $pago['monto_mora'];

            // Congelar mora si aún no está fijada
            if ($mora_frozen <= 0) {
                $mora_frozen = calcular_mora(
                    $monto_base,
                    dias_atraso_habiles($pago['fecha_vencimiento']),
                    (float) $pago['interes_moratorio_pct']
                );
            }

            $nuevo_saldo  = $saldo_prev + (float) $pago['monto_total'];
            $nuevo_estado = determinar_estado_cuota($monto_base, $mora_frozen, $nuevo_saldo);
            $fecha_pago_v = ($nuevo_estado === 'PAGADA') ? $fecha : null;
            
            // Si es parcial, no congelamos la mora calculada hoy, mantenemos la que ya tenía la cuota
            $monto_mora_guardar = ($nuevo_estado === 'PAGADA') ? $mora_frozen : (float) $pago['monto_mora'];

            $pdo->prepare("UPDATE ic_cuotas SET estado=?, saldo_pagado=?, monto_mora=?, fecha_pago=? WHERE id=?")
                ->execute([$nuevo_estado, $nuevo_saldo, $monto_mora_guardar, $fecha_pago_v, $pago['cuota_id']]);

            // 3. Marcar pago temporal como APROBADO
            $pdo->prepare("UPDATE ic_pagos_temporales SET estado='APROBADO' WHERE id=?")
                ->execute([$pago['id']]);

            // 4. Recalcular estado del crédito (FINALIZADO, MOROSO o EN_CURSO)
            $cr_stmt = $pdo->prepare("SELECT estado FROM ic_creditos WHERE id=?");
            $cr_stmt->execute([$pago['credito_id']]);
            $estado_actual_cr = $cr_stmt->fetchColumn();

            if ($estado_actual_cr !== 'CANCELADO') {
                $check = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN estado != 'PAGADA' THEN 1 ELSE 0 END) as pendientes,
                        SUM(CASE WHEN estado = 'VENCIDA' THEN 1 ELSE 0 END) as vencidas
                    FROM ic_cuotas
                    WHERE credito_id = ?
                ");
                $check->execute([$pago['credito_id']]);
                $counts = $check->fetch(PDO::FETCH_ASSOC);

                if ((int) $counts['pendientes'] === 0) {
                    $nuevo_cr_estado = 'FINALIZADO';
                } elseif ((int) $counts['vencidas'] > 0) {
                    $nuevo_cr_estado = 'MOROSO';
                } else {
                    $nuevo_cr_estado = 'EN_CURSO';
                }
                $pdo->prepare("UPDATE ic_creditos SET estado=? WHERE id=?")
                    ->execute([$nuevo_cr_estado, $pago['credito_id']]);
            }

            $pdo->commit();
            $resultado['aprobados']++;
        } catch (Exception $e) {
            $pdo->rollBack();
            $resultado['errores']++;
        }
    }
    return $resultado;
}

// ── Helpers UI ───────────────────────────────────────────────

function whatsapp_url(string $telefono, string $mensaje = ''): string
{
    $tel = preg_replace('/[^0-9]/', '', $telefono);
    // Normalizar a formato internacional Argentina si empieza con 0
    if (strlen($tel) === 10 && $tel[0] === '0') {
        $tel = '549' . substr($tel, 1);
    } elseif (strlen($tel) === 11 && substr($tel, 0, 2) === '15') {
        $tel = '549' . substr($tel, 2);
    }
    return 'https://wa.me/' . $tel . ($mensaje ? '?text=' . urlencode($mensaje) : '');
}

function maps_url(string $coordenadas): string
{
    return 'https://www.google.com/maps?q=' . urlencode($coordenadas);
}

function formato_pesos(float $valor): string
{
    return '$ ' . number_format($valor, 0, ',', '.');
}

function generar_token(): string
{
    // 10 chars de alfabeto sin caracteres ambiguos (0/O, 1/I/L)
    $abc   = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $bytes = random_bytes(10);
    $token = '';
    for ($i = 0; $i < 10; $i++) {
        $token .= $abc[ord($bytes[$i]) % strlen($abc)];
    }
    return $token;
}

function nombre_dia(int $n): string
{
    return ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][$n] ?? '?';
}

function badge_estado_credito(string $estado): string
{
    $map = [
        'EN_CURSO' => 'success',
        'FINALIZADO' => 'secondary',
        'MOROSO' => 'danger',
        'CANCELADO' => 'warning',
    ];
    $color = $map[$estado] ?? 'light';
    return "<span class=\"badge bg-{$color}\">{$estado}</span>";
}

function badge_estado_cliente(string $estado): string
{
    $map = ['ACTIVO' => 'success', 'INACTIVO' => 'secondary', 'MOROSO' => 'danger'];
    $color = $map[$estado] ?? 'light';
    return "<span class=\"badge bg-{$color}\">{$estado}</span>";
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── Log de Actividades ────────────────────────────────────────

function registrar_log(
    PDO $pdo,
    int $usuario_id,
    string $accion,
    string $entidad,
    ?int $entidad_id = null,
    string $detalle = ''
): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare("
            INSERT INTO ic_log_actividades (usuario_id, accion, entidad, entidad_id, detalle, ip)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$usuario_id, $accion, $entidad, $entidad_id, $detalle ?: null, $ip]);
    } catch (Exception $e) {
        // El log nunca interrumpe el flujo principal
    }
}
