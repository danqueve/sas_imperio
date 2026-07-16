<?php
// ============================================================
// Sistema Imperio Comercial — Funciones de Negocio
// ============================================================

// ── Semana de cobro ───────────────────────────────────────────

/**
 * Devuelve el lunes que inicia la semana de cobro de una fecha de jornada.
 * Regla: Domingo (N=7) → lunes anterior; Lunes-Sábado → lunes de esa semana.
 */
function calcular_semana_lunes(string $fecha_jornada): string
{
    $d = new DateTime($fecha_jornada);
    $dow = (int) $d->format('N'); // 1=Lun … 7=Dom
    if ($dow === 7) {
        // Domingo pertenece a la semana que empezó el lunes anterior
        $d->modify('-6 days');
    } else {
        // Lunes (1) → resta 0; Martes (2) → resta 1; … Sábado (6) → resta 5
        $d->modify('-' . ($dow - 1) . ' days');
    }
    return $d->format('Y-m-d');
}

// ── Mora ─────────────────────────────────────────────────────

define('MORA_DIAS_GRACIA',  6);   // días hábiles libres de mora
define('MORA_UMBRAL_TOTAL', 10);  // a partir del día 11 → mora sobre todos los días

/**
 * Mora escalonada:
 *  0–6 días hábiles  → $0 (período de gracia)
 *  7–10 días hábiles → mora sobre (días − 6)
 *  11+ días hábiles  → mora sobre TODOS los días (sin descontar gracia)
 */
function calcular_mora(float $monto_cuota, int $dias_habiles_atraso, float $pct_mora_semanal = 15.0): float
{
    if ($dias_habiles_atraso <= MORA_DIAS_GRACIA)
        return 0.0;

    $pct_diario = $pct_mora_semanal / 6.0;

    $dias_efectivos = $dias_habiles_atraso <= MORA_UMBRAL_TOTAL
        ? $dias_habiles_atraso - MORA_DIAS_GRACIA
        : $dias_habiles_atraso;

    return round($monto_cuota * ($pct_diario / 100) * $dias_efectivos, 2);
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
 * Devuelve la fecha de jornada para un pago.
 * Corte: antes de las 10:00 AM → el pago pertenece a la jornada del día anterior.
 * Esto permite que los cobradores registren cobros de madrugada sin cortar la jornada.
 */
function fecha_jornada(?string $datetime = null): string
{
    $hora = (int) date('H', $datetime ? strtotime($datetime) : time());
    if ($hora < 10) {
        return date('Y-m-d', strtotime('-1 day'));
    }
    return date('Y-m-d');
}

/**
 * Avanza una fecha al siguiente vencimiento según la frecuencia del crédito.
 * Para diario: salta domingos automáticamente.
 */
function calcular_siguiente_vencimiento(string $base, string $frecuencia): string
{
    $f = new DateTime($base);
    if ($frecuencia === 'diario') {
        $f->modify('+1 day');
        while ((int)$f->format('N') === 7) {
            $f->modify('+1 day');
        }
    } else {
        match ($frecuencia) {
            'semanal'   => $f->modify('+7 days'),
            'quincenal' => $f->modify('+15 days'),
            default     => $f->modify('+1 month'),
        };
    }
    return $f->format('Y-m-d');
}

/**
 * Devuelve las fechas de jornada disponibles para registrar pagos en este momento.
 *
 * Reglas de negocio:
 *   - Semana hábil: Lunes a Sábado. Domingo NO es día de cobro.
 *   - Domingo (cualquier hora) → jornada del Sábado anterior.
 *   - Lunes antes de las 10 AM → jornada del Sábado (rinde cobros del Sábado).
 *   - Cualquier otro día antes de las 10 AM → jornada de ayer.
 *   - Resto → jornada de hoy.
 */
function jornadas_disponibles(): array
{
    $dow  = (int) date('N'); // 1=Lun … 7=Dom
    $hora = (int) date('H');

    // Domingo: siempre pertenece al sábado anterior
    if ($dow === 7) {
        return [date('Y-m-d', strtotime('-1 day'))]; // Sábado
    }

    // Lunes antes de las 10 AM: rinde el sábado
    if ($dow === 1 && $hora < 10) {
        return [date('Y-m-d', strtotime('-2 days'))]; // Sábado
    }

    // Cualquier otro día antes de las 10 AM: jornada de ayer
    if ($hora < 10) {
        return [date('Y-m-d', strtotime('-1 day'))];
    }

    return [date('Y-m-d')];
}


/**
 * Genera las cuotas de un crédito en la base de datos.
 */
function generar_cuotas(int $credito_id, array $d, PDO $pdo): bool
{
    $fecha = new DateTime($d['primer_vencimiento']);
    // Para frecuencia diaria: si el primer_vencimiento es domingo, avanzar al lunes
    if ($d['frecuencia'] === 'diario') {
        while ((int)$fecha->format('N') === 7) {
            $fecha->modify('+1 day');
        }
    }
    $monto_normal = (float) $d['monto_cuota'];
    $monto_ultima = isset($d['monto_ultima_cuota']) ? (float) $d['monto_ultima_cuota'] : $monto_normal;
    $stmt = $pdo->prepare("INSERT INTO ic_cuotas (credito_id, numero_cuota, fecha_vencimiento, monto_cuota) VALUES (?, ?, ?, ?)");

    for ($i = 1; $i <= $d['cant_cuotas']; $i++) {
        if ($i > 1) {
            switch ($d['frecuencia']) {
                case 'diario':
                    $fecha->modify('+1 day');
                    while ((int)$fecha->format('N') === 7) { // saltar domingo
                        $fecha->modify('+1 day');
                    }
                    break;
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
        $monto = ($i === (int)$d['cant_cuotas']) ? $monto_ultima : $monto_normal;
        $stmt->execute([$credito_id, $i, $fecha->format('Y-m-d'), $monto]);
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
        SELECT pt.id
        FROM ic_pagos_temporales pt
        WHERE pt.cobrador_id = ? AND pt.fecha_jornada = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$cobrador_id, $fecha]);
    $ids_pendientes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($ids_pendientes as $pago_id) {
        try {
            $pdo->beginTransaction();

            // Re-leer con FOR UPDATE y datos completos; verifica que siga PENDIENTE
            $lock = $pdo->prepare("
                SELECT pt.*, cu.credito_id, cu.monto_cuota, cu.saldo_pagado, cu.monto_mora,
                       cu.fecha_vencimiento, cu.estado AS cuota_estado, cu.numero_cuota,
                       cr.interes_moratorio_pct,
                       COALESCE(cr.articulo_desc, a.descripcion, '') AS articulo_snap,
                       cl.telefono AS cliente_tel, cl.nombres AS cliente_nombres,
                       cl.apellidos AS cliente_apellidos
                FROM ic_pagos_temporales pt
                JOIN ic_cuotas cu   ON pt.cuota_id    = cu.id
                JOIN ic_creditos cr ON cu.credito_id  = cr.id
                JOIN ic_clientes cl ON cr.cliente_id  = cl.id
                LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
                WHERE pt.id = ? AND pt.estado = 'PENDIENTE'
                FOR UPDATE
            ");
            $lock->execute([$pago_id]);
            $pago = $lock->fetch();

            if (!$pago) {
                $pdo->rollBack();
                continue; // Otro proceso ya lo aprobó
            }

            // 1. Insertar en pagos_confirmados con snapshot completo.
            // Si la fila ya existe (pago revertido, uq_pago_temp_id), se reutiliza
            // actualizando sus campos y limpiando el estado de reversa, en lugar de
            // lanzar un error de clave duplicada que bloquearía la re-aprobación.
            $semana_lunes = calcular_semana_lunes($pago['fecha_jornada'] ?: $fecha);
            $ins = $pdo->prepare("
                INSERT INTO ic_pagos_confirmados
                    (pago_temp_id, cuota_id, cobrador_id, aprobador_id, fecha_pago,
                     monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada,
                     es_cuota_pura, observaciones, fecha_jornada, semana_lunes, origen,
                     monto_cuota_orig, numero_cuota, fecha_vcto_orig, articulo_snap,
                     cliente_nombres_snap, cliente_apellidos_snap)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS nuevo
                ON DUPLICATE KEY UPDATE
                    cuota_id               = nuevo.cuota_id,
                    cobrador_id            = nuevo.cobrador_id,
                    aprobador_id           = nuevo.aprobador_id,
                    fecha_pago             = nuevo.fecha_pago,
                    monto_efectivo         = nuevo.monto_efectivo,
                    monto_transferencia    = nuevo.monto_transferencia,
                    monto_total            = nuevo.monto_total,
                    monto_mora_cobrada     = nuevo.monto_mora_cobrada,
                    es_cuota_pura          = nuevo.es_cuota_pura,
                    observaciones          = nuevo.observaciones,
                    fecha_jornada          = nuevo.fecha_jornada,
                    semana_lunes           = nuevo.semana_lunes,
                    origen                 = nuevo.origen,
                    monto_cuota_orig       = nuevo.monto_cuota_orig,
                    numero_cuota           = nuevo.numero_cuota,
                    fecha_vcto_orig        = nuevo.fecha_vcto_orig,
                    articulo_snap          = nuevo.articulo_snap,
                    cliente_nombres_snap   = nuevo.cliente_nombres_snap,
                    cliente_apellidos_snap = nuevo.cliente_apellidos_snap,
                    fecha_aprobacion       = NOW(),
                    revertido              = 0,
                    fecha_reversa          = NULL,
                    reverso_por            = NULL,
                    motivo_reversa         = NULL,
                    solicitud_baja         = 0,
                    motivo_baja            = NULL
            ");
            $ins->execute([
                $pago['id'],
                $pago['cuota_id'],
                $pago['cobrador_id'],
                $aprobador_id,
                $pago['fecha_registro'] ?: ($pago['fecha_jornada'] . ' 00:00:00'),
                $pago['monto_efectivo'],
                $pago['monto_transferencia'],
                $pago['monto_total'],
                $pago['monto_mora_cobrada'],
                // Snapshot
                (int)($pago['es_cuota_pura'] ?? 0),
                $pago['observaciones'] ?? null,
                $pago['fecha_jornada'],
                $semana_lunes,
                $pago['origen'] ?? 'cobrador',
                $pago['monto_cuota'],
                $pago['numero_cuota'],
                $pago['fecha_vencimiento'],
                $pago['articulo_snap'],
                $pago['cliente_nombres'],
                $pago['cliente_apellidos'],
            ]);

            // 2. Calcular nuevo saldo y estado de la cuota
            $monto_base  = (float) $pago['monto_cuota'];
            $saldo_prev  = (float) ($pago['saldo_pagado'] ?? 0);

            // Fase 1: usar mora congelada al momento del registro del cobrador
            // Fallback: cuota.monto_mora → recálculo usando fecha_jornada (no hoy)
            // IMPORTANTE: el fallback recalcula con fecha_jornada para no penalizar
            // pagos registrados en término pero aprobados días después.
            $mora_frozen = (float) ($pago['mora_congelada'] ?? 0);
            if ($mora_frozen <= 0) {
                $mora_frozen = (float) $pago['monto_mora'];
            }
            if ($mora_frozen <= 0) {
                $fecha_ref_mora = $pago['fecha_jornada'] ?: $fecha;
                $mora_frozen = calcular_mora(
                    $monto_base,
                    dias_atraso_habiles($pago['fecha_vencimiento'], $fecha_ref_mora),
                    (float) $pago['interes_moratorio_pct']
                );
            }

            $nuevo_saldo  = $saldo_prev + (float) $pago['monto_total'];
            $nuevo_estado = determinar_estado_cuota($monto_base, $mora_frozen, $nuevo_saldo);

            // Cuota pura: cobrador pagó solo el capital (es_cuota_pura=1) o
            // bien era CAP_PAGADA y se hizo un pago parcial de mora → mantener CAP_PAGADA
            if ($nuevo_estado === 'PARCIAL') {
                $capital_cubierto = ($nuevo_saldo >= $monto_base - 0.005);
                $era_cap_pagada   = ($pago['cuota_estado'] === 'CAP_PAGADA');
                if ($capital_cubierto && ((int) $pago['es_cuota_pura'] || $era_cap_pagada)) {
                    $nuevo_estado = 'CAP_PAGADA';
                }
            }

            $fecha_pago_v = ($nuevo_estado === 'PAGADA') ? $fecha : null;

            // Siempre congelar mora calculada en la cuota (Fase 3: evita recálculo con más días)
            $monto_mora_guardar = $mora_frozen;

            $pdo->prepare("UPDATE ic_cuotas SET estado=?, saldo_pagado=?, monto_mora=?, fecha_pago=? WHERE id=?")
                ->execute([$nuevo_estado, $nuevo_saldo, $monto_mora_guardar, $fecha_pago_v, $pago['cuota_id']]);

            // 3. Marcar pago temporal como APROBADO (AND estado='PENDIENTE' garantiza idempotencia)
            $upd = $pdo->prepare("UPDATE ic_pagos_temporales SET estado='APROBADO' WHERE id=? AND estado='PENDIENTE'");
            $upd->execute([$pago['id']]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                continue; // Ya fue procesado por concurrencia, no duplicar
            }

            // 4. Recalcular estado del crédito (FINALIZADO, MOROSO o EN_CURSO)
            $cr_stmt = $pdo->prepare("SELECT cr.estado, cr.cliente_id FROM ic_creditos cr WHERE cr.id=?");
            $cr_stmt->execute([$pago['credito_id']]);
            $cr_row = $cr_stmt->fetch();
            $estado_actual_cr = $cr_row ? $cr_row['estado'] : '';
            $cliente_id_cr    = $cr_row ? (int)$cr_row['cliente_id'] : 0;

            if ($estado_actual_cr !== 'CANCELADO') {
                $check = $pdo->prepare("
                    SELECT
                        SUM(CASE WHEN estado NOT IN ('PAGADA','CANCELADA') THEN 1 ELSE 0 END) AS pendientes,
                        SUM(CASE WHEN estado = 'VENCIDA'
                                 OR (estado = 'PARCIAL' AND fecha_vencimiento < CURDATE())
                            THEN 1 ELSE 0 END) AS vencidas
                    FROM ic_cuotas
                    WHERE credito_id = ?
                ");
                $check->execute([$pago['credito_id']]);
                $counts = $check->fetch(PDO::FETCH_ASSOC);

                if ((int)$counts['pendientes'] === 0) {
                    $nuevo_cr_estado = 'FINALIZADO';
                    // Auto-finalización: establecer fecha y motivo si aún no están seteados
                    $pdo->prepare("
                        UPDATE ic_creditos
                        SET estado = 'FINALIZADO',
                            fecha_finalizacion = COALESCE(fecha_finalizacion, ?),
                            motivo_finalizacion = COALESCE(motivo_finalizacion, 'PAGO_COMPLETO')
                        WHERE id = ?
                    ")->execute([$fecha, $pago['credito_id']]);
                } elseif ((int)$counts['vencidas'] > 0) {
                    $nuevo_cr_estado = 'MOROSO';
                    $pdo->prepare("UPDATE ic_creditos SET estado='MOROSO' WHERE id=?")
                        ->execute([$pago['credito_id']]);
                } else {
                    $nuevo_cr_estado = 'EN_CURSO';
                    $pdo->prepare("UPDATE ic_creditos SET estado='EN_CURSO' WHERE id=?")
                        ->execute([$pago['credito_id']]);
                }
            }

            $pdo->commit();

            // 5. Notificación WhatsApp — pago confirmado (fire-and-forget)
            if ($nuevo_estado === 'PAGADA' && !empty($pago['cliente_tel'])) {
                try {
                    static $wa_loaded = false;
                    if (!$wa_loaded) {
                        $wa_cfg = __DIR__ . '/whatsapp.php';
                        $wa_svc = __DIR__ . '/../services/WhatsAppService.php';
                        if (file_exists($wa_cfg) && file_exists($wa_svc)) {
                            require_once $wa_cfg;
                            require_once $wa_svc;
                            $wa_loaded = true;
                        }
                    }
                    if ($wa_loaded && defined('WA_ENABLED') && WA_ENABLED) {
                        (new WhatsAppService())->enviarTemplate(
                            $pago['cliente_tel'],
                            WA_TPL_PAGO,
                            WA_TPL_LANG,
                            [
                                $pago['cliente_nombres'],
                                formato_pesos((float) $pago['monto_total']),
                                (string) $pago['numero_cuota'],
                            ]
                        );
                    }
                } catch (Throwable $ignored) { /* silencioso — no afecta el flujo */ }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $resultado['errores']++;
        }
    }
    return $resultado;
}

/**
 * Aprueba TODOS los pagos pendientes de un cobrador en todas sus jornadas pendientes.
 * Llama a aprobar_rendicion() una vez por cada fecha_jornada distinta.
 *
 * @return array{aprobados:int, errores:int, jornadas_procesadas:int, fechas:string[]}
 */
function aprobar_todas_jornadas(int $cobrador_id, int $aprobador_id, PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT fecha_jornada
        FROM ic_pagos_temporales
        WHERE cobrador_id = ? AND estado = 'PENDIENTE'
        ORDER BY fecha_jornada ASC
    ");
    $stmt->execute([$cobrador_id]);
    $fechas = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $resultado = ['aprobados' => 0, 'errores' => 0, 'jornadas_procesadas' => 0, 'fechas' => $fechas];
    foreach ($fechas as $fecha) {
        $res = aprobar_rendicion($cobrador_id, $fecha, $aprobador_id, $pdo);
        $resultado['aprobados']           += $res['aprobados'];
        $resultado['errores']             += $res['errores'];
        $resultado['jornadas_procesadas'] += 1;
    }
    return $resultado;
}

// ── Reversa de rendición ─────────────────────────────────────

/**
 * Revierte lógicamente una rendición aprobada (cobrador + fecha + origen).
 *
 * - Marca ic_pagos_confirmados como revertido=1 (no borra físicamente).
 * - Vuelve ic_pagos_temporales a PENDIENTE para re-aprobación.
 * - Deshace los cambios en ic_cuotas (saldo, estado, fecha_pago).
 * - Recalcula ic_creditos.estado (puede salir de FINALIZADO).
 * - Toda la operación es atómica (una transacción para toda la rendición).
 *
 * @return array{ok:bool, cuotas:int, error:string}
 */
function revertir_rendicion(
    int    $cobrador_id,
    string $fecha,
    string $origen,
    int    $usuario_id,
    string $motivo,
    PDO    $pdo
): array {
    // Obtener todos los pagos confirmados del grupo (no revertidos)
    $stmtPagos = $pdo->prepare("
        SELECT pc.id, pc.pago_temp_id, pc.cuota_id, pc.monto_total, pc.fecha_aprobacion
        FROM ic_pagos_confirmados pc
        LEFT JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
        WHERE pc.cobrador_id = ?
          AND pc.fecha_jornada = ?
          AND IFNULL(pt.origen, 'cobrador') = ?
          AND pc.revertido = 0
        ORDER BY pc.id ASC
    ");
    $stmtPagos->execute([$cobrador_id, $fecha, $origen]);
    $pagos = $stmtPagos->fetchAll();

    if (empty($pagos)) {
        return ['ok' => false, 'cuotas' => 0, 'error' => 'No se encontraron pagos confirmados para esta rendición.'];
    }

    // ── Validaciones previas (fuera de transacción) ───────────
    foreach ($pagos as $pago) {
        // ¿Existe otro pago confirmado posterior sobre la misma cuota?
        $stmtPost = $pdo->prepare("
            SELECT COUNT(*) FROM ic_pagos_confirmados
            WHERE cuota_id = ? AND revertido = 0 AND fecha_aprobacion > ?
        ");
        $stmtPost->execute([$pago['cuota_id'], $pago['fecha_aprobacion']]);
        if ((int) $stmtPost->fetchColumn() > 0) {
            return ['ok' => false, 'cuotas' => 0,
                'error' => "La cuota ID {$pago['cuota_id']} tiene un cobro posterior. Revierta ese primero."];
        }

        // ¿El crédito está CANCELADO?
        $stmtCr = $pdo->prepare("
            SELECT cr.estado FROM ic_cuotas cu
            JOIN ic_creditos cr ON cr.id = cu.credito_id
            WHERE cu.id = ?
        ");
        $stmtCr->execute([$pago['cuota_id']]);
        $cr = $stmtCr->fetchColumn();
        if ($cr === 'CANCELADO') {
            return ['ok' => false, 'cuotas' => 0,
                'error' => "El crédito asociado a la cuota ID {$pago['cuota_id']} está CANCELADO y no puede revertirse."];
        }

        // ¿Cuota refinanciada después de este cobro?
        $stmtRef = $pdo->prepare("
            SELECT COUNT(*) FROM ic_historial_refinanciaciones hr
            JOIN ic_cuotas cu ON cu.credito_id = hr.credito_id
            WHERE cu.id = ? AND hr.fecha_refinanciacion > DATE(?)
        ");
        $stmtRef->execute([$pago['cuota_id'], $pago['fecha_aprobacion']]);
        if ((int) $stmtRef->fetchColumn() > 0) {
            return ['ok' => false, 'cuotas' => 0,
                'error' => "La cuota ID {$pago['cuota_id']} pertenece a un crédito refinanciado después del cobro."];
        }
    }

    // ── Transacción única para toda la rendición ──────────────
    try {
        $pdo->beginTransaction();

        $creditos_afectados = [];

        foreach ($pagos as $pago) {
            // 1. Marcar pago confirmado como revertido
            $pdo->prepare("
                UPDATE ic_pagos_confirmados
                SET revertido=1, fecha_reversa=NOW(), reverso_por=?, motivo_reversa=?
                WHERE id=?
            ")->execute([$usuario_id, $motivo, $pago['id']]);

            // 2. Recalcular cuota: restar monto y recalcular estado
            $stmtCuota = $pdo->prepare("
                SELECT cu.monto_cuota, cu.saldo_pagado, cu.monto_mora, cu.fecha_vencimiento,
                       cu.credito_id, cr.interes_moratorio_pct
                FROM ic_cuotas cu
                JOIN ic_creditos cr ON cr.id = cu.credito_id
                WHERE cu.id = ?
            ");
            $stmtCuota->execute([$pago['cuota_id']]);
            $cuota = $stmtCuota->fetch();

            $nuevo_saldo = (float) max(0, (float) $cuota['saldo_pagado'] - (float) $pago['monto_total']);
            $mora = (float) $cuota['monto_mora'];
            if ($mora <= 0) {
                // Usar fecha de aprobación como referencia para no inflar mora con días adicionales
                $fecha_ref_rev = $pago['fecha_aprobacion'] ?? date('Y-m-d');
                $mora = calcular_mora(
                    (float) $cuota['monto_cuota'],
                    dias_atraso_habiles($cuota['fecha_vencimiento'], $fecha_ref_rev),
                    (float) $cuota['interes_moratorio_pct']
                );
            }
            if ($nuevo_saldo <= 0.005) {
                // Saldo revertido a cero: restablecer según fecha de vencimiento
                $nuevo_estado = dias_atraso_habiles($cuota['fecha_vencimiento']) > 0 ? 'VENCIDA' : 'PENDIENTE';
            } else {
                $nuevo_estado = determinar_estado_cuota((float) $cuota['monto_cuota'], $mora, $nuevo_saldo);
            }
            $nueva_fecha_pago = ($nuevo_estado === 'PAGADA') ? date('Y-m-d') : null;

            $pdo->prepare("
                UPDATE ic_cuotas SET estado=?, saldo_pagado=?, fecha_pago=? WHERE id=?
            ")->execute([$nuevo_estado, $nuevo_saldo, $nueva_fecha_pago, $pago['cuota_id']]);

            // 3. Volver pago temporal a PENDIENTE
            $pdo->prepare("
                UPDATE ic_pagos_temporales SET estado='PENDIENTE' WHERE id=?
            ")->execute([$pago['pago_temp_id']]);

            $creditos_afectados[$cuota['credito_id']] = true;
        }

        // 4. Recalcular estado de cada crédito afectado
        foreach (array_keys($creditos_afectados) as $credito_id) {
            $stmtCr = $pdo->prepare("SELECT estado FROM ic_creditos WHERE id=?");
            $stmtCr->execute([$credito_id]);
            $estado_cr = $stmtCr->fetchColumn();

            if ($estado_cr === 'CANCELADO') continue;

            $check = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN estado NOT IN ('PAGADA','CANCELADA') THEN 1 ELSE 0 END) AS pendientes,
                    SUM(CASE WHEN estado = 'VENCIDA'
                             OR (estado = 'PARCIAL' AND fecha_vencimiento < CURDATE())
                        THEN 1 ELSE 0 END) AS vencidas
                FROM ic_cuotas WHERE credito_id = ?
            ");
            $check->execute([$credito_id]);
            $counts = $check->fetch(PDO::FETCH_ASSOC);

            if ((int) $counts['pendientes'] === 0) {
                // Sigue finalizado (otros pagos lo cerraron)
                $pdo->prepare("UPDATE ic_creditos SET estado='FINALIZADO' WHERE id=?")->execute([$credito_id]);
            } elseif ((int) $counts['vencidas'] > 0) {
                $pdo->prepare("
                    UPDATE ic_creditos
                    SET estado='MOROSO', fecha_finalizacion=NULL, motivo_finalizacion=NULL
                    WHERE id=?
                ")->execute([$credito_id]);
            } else {
                $pdo->prepare("
                    UPDATE ic_creditos
                    SET estado='EN_CURSO', fecha_finalizacion=NULL, motivo_finalizacion=NULL
                    WHERE id=?
                ")->execute([$credito_id]);
            }
        }

        // 5. Log de auditoría
        registrar_log($pdo, $usuario_id, 'RENDICION_REVERTIDA', 'rendicion', null,
            "cobrador=$cobrador_id fecha=$fecha origen=$origen cuotas=" . count($pagos) . " motivo=$motivo");

        $pdo->commit();
        return ['ok' => true, 'cuotas' => count($pagos), 'error' => ''];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('revertir_rendicion error: ' . $e->getMessage());
        return ['ok' => false, 'cuotas' => 0, 'error' => 'Error interno al revertir. Intente nuevamente.'];
    }
}

// ── Helpers UI ───────────────────────────────────────────────

function whatsapp_url(string $telefono, string $mensaje = ''): string
{
    $tel = preg_replace('/[^0-9]/', '', $telefono);
    if (!$tel) return '#';

    // Quitar prefijo de país si ya lo tiene (549... o 54...)
    if (substr($tel, 0, 3) === '549') {
        $tel = substr($tel, 3);
    } elseif (substr($tel, 0, 2) === '54') {
        $tel = substr($tel, 2);
    }

    // Quitar 0 inicial de discado local (ej: 0351XXXXXXX → 351XXXXXXX)
    if (strlen($tel) > 0 && $tel[0] === '0') {
        $tel = substr($tel, 1);
    }

    // Reconstruir: 54 (Argentina) + 9 (móvil) + característica + número
    $tel = '549' . $tel;

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
    return ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$n] ?? '?';
}

/**
 * Devuelve el label de una fecha_jornada para mostrar al usuario.
 * Si es domingo (N=7), se muestra como "Sáb (tardío) dd/mm/YYYY" porque el domingo
 * no es día hábil — esos pagos corresponden al sábado anterior.
 */
function label_jornada(string $fecha): string
{
    $dow = (int) date('N', strtotime($fecha));
    if ($dow === 7) {
        // Domingo → mostrar como Sábado tardío
        return 'Sáb (tardío) ' . date('d/m/Y', strtotime($fecha));
    }
    return nombre_dia($dow) . ' ' . date('d/m/Y', strtotime($fecha));
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

// ── Puntaje de pago ───────────────────────────────────────────

/**
 * Calcula el puntaje de pago de un crédito finalizad.
 * Examina si hubo cuotas vencidas con mora y refinanciaciones.
 * @return int 1=Excelente, 2=Bueno, 3=Regular, 4=Malo
 */
function calcular_puntaje_credito(int $credito_id, PDO $pdo): int
{
    // Contar cuotas con mora registrada
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cuotas,
            SUM(CASE WHEN monto_mora > 0 THEN 1 ELSE 0 END) AS cuotas_con_mora,
            MAX(CASE WHEN estado IN ('VENCIDA','PARCIAL','CAP_PAGADA') AND fecha_vencimiento < CURDATE()
                THEN DATEDIFF(CURDATE(), fecha_vencimiento) ELSE 0 END) AS max_atraso
        FROM ic_cuotas
        WHERE credito_id = ?
    ");
    $stmt->execute([$credito_id]);
    $r = $stmt->fetch();

    // Contar veces refinanciado
    $ref_stmt = $pdo->prepare("SELECT veces_refinanciado FROM ic_creditos WHERE id = ?");
    $ref_stmt->execute([$credito_id]);
    $veces_ref = (int)($ref_stmt->fetchColumn() ?? 0);

    $cuotas_con_mora = (int)($r['cuotas_con_mora'] ?? 0);
    $max_atraso      = (int)($r['max_atraso'] ?? 0);

    if ($veces_ref >= 2 || $cuotas_con_mora >= 3 || $max_atraso > 30) {
        return 4; // Malo
    }
    if ($veces_ref === 1 || $cuotas_con_mora >= 2 || $max_atraso > 14) {
        return 3; // Regular
    }
    if ($cuotas_con_mora === 1 || $max_atraso > 0) {
        return 2; // Bueno
    }
    return 1; // Excelente — sin mora en ninguna cuota
}

/**
 * Recalcula y guarda el puntaje promedio del cliente
 * basado en todos sus créditos finalizados con pago.
 */
function actualizar_puntaje_cliente(int $cliente_id, PDO $pdo): void
{
    try {
        // Si existe algún crédito cancelado, forzar puntaje = 4
        $bad_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM ic_creditos
            WHERE cliente_id = ? AND (motivo_finalizacion = 'CANCELADO' OR estado = 'CANCELADO')
        ");
        $bad_stmt->execute([$cliente_id]);
        if ((int)$bad_stmt->fetchColumn() > 0) {
            $pdo->prepare("UPDATE ic_clientes SET puntaje_pago = 4 WHERE id = ?")
                ->execute([$cliente_id]);
            return;
        }

        // Obtener todos los créditos finalizados con pago del cliente
        $stmt = $pdo->prepare("
            SELECT id FROM ic_creditos
            WHERE cliente_id = ?
              AND estado = 'FINALIZADO'
              AND motivo_finalizacion IN ('PAGO_COMPLETO', 'PAGO_COMPLETO_CON_MORA')
        ");
        $stmt->execute([$cliente_id]);
        $creditos = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($creditos)) return;

        $total      = count($creditos);
        $sin_mora   = 0;
        $suma_punt  = 0;

        foreach ($creditos as $cid) {
            $punt = calcular_puntaje_credito((int)$cid, $pdo);
            $suma_punt += $punt;
            if ($punt === 1) $sin_mora++;
        }

        $puntaje_promedio = (int)round($suma_punt / $total);
        $puntaje_promedio = min(4, max(1, $puntaje_promedio));

        $pdo->prepare("
            UPDATE ic_clientes
            SET puntaje_pago = ?,
                total_creditos_finalizados = ?,
                creditos_sin_mora = ?
            WHERE id = ?
        ")->execute([$puntaje_promedio, $total, $sin_mora, $cliente_id]);
    } catch (Exception $e) {
        // No interrumpir flujo principal
    }
}

/**
 * Etiqueta de puntaje de pago para UI.
 * @param int|null $puntaje
 * @return string HTML con badge coloreado
 */
function badge_puntaje_pago(?int $puntaje): string
{
    if ($puntaje === null) return '';
    $map = [
        1 => ['⭐⭐⭐ Excelente', 'success'],
        2 => ['⭐⭐ Bueno',      'primary'],
        3 => ['⭐ Regular',      'warning'],
        4 => ['Sin mora',       'danger'],
    ];
    [$label, $color] = $map[$puntaje] ?? ['—', 'secondary'];
    return "<span class=\"badge-ic badge-{$color}\">{$label}</span>";
}

/**
 * Genera la URL de WhatsApp con mensaje de felicitación por finalización de crédito.
 */
function whatsapp_finalizacion_url(string $telefono, string $nombre, string $articulo): string
{
    $mensaje = "¡Hola {$nombre}! 🎉 Tu crédito de {$articulo} está saldado completamente. "
             . "Fue un gusto trabajar con vos. Cuando quieras, tenemos nuevas opciones disponibles. ¡Gracias!";
    return whatsapp_url($telefono, $mensaje);
}

// ── Scoring predictivo para créditos activos ──────────────

/**
 * Función pura de scoring de riesgo. Centraliza los umbrales para todos los reportes.
 * $cv=cuotas vencidas, $avg=promedio días atraso, $cm=cuotas activas con mora, $ref=refinanciaciones
 */
function calcular_nivel_riesgo(int $cv, float $avg, int $cm, int $ref): int
{
    // Crítico: reincidente, O muchas cuotas Y ya >14 días promedio, O >30 días promedio
    if ($ref >= 2 || ($cv >= 4 && $avg > 14) || $avg > 30) return 4;
    // Alto: refinanciado, O 2+ cuotas Y >7 días promedio, O >14 días promedio, O 3+ cuotas activas con mora
    if ($ref >= 1 || ($cv >= 2 && $avg > 7) || $avg > 14 || $cm >= 3) return 3;
    // Moderado: cualquier cuota vencida o mora activa
    if ($cv >= 1 || $avg > 0 || $cm >= 1) return 2;
    return 1; // Bajo
}

/**
 * Calcula nivel de riesgo de un crédito EN CURSO.
 * Retorna 1 (Bajo) a 4 (Crítico).
 */
function calcular_riesgo_credito_activo(int $credito_id, PDO $pdo): int
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN cu.estado IN ('VENCIDA','PARCIAL') AND cu.fecha_vencimiento < CURDATE() THEN 1 END) AS cuotas_vencidas,
            COALESCE(AVG(CASE WHEN cu.fecha_vencimiento < CURDATE() AND cu.estado IN ('VENCIDA','PARCIAL')
                              THEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) END), 0) AS avg_atraso,
            COUNT(CASE WHEN cu.monto_mora > 0
                        AND cu.estado NOT IN ('PAGADA','CAP_PAGADA','CANCELADA') THEN 1 END) AS con_mora,
            COALESCE(cr.veces_refinanciado, 0) AS refinanciado
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cu.credito_id = ?
    ");
    $stmt->execute([$credito_id]);
    $d = $stmt->fetch();
    if (!$d) return 1;

    return calcular_nivel_riesgo(
        (int)$d['cuotas_vencidas'], (float)$d['avg_atraso'],
        (int)$d['con_mora'],        (int)$d['refinanciado']
    );
}

/**
 * Badge HTML para nivel de riesgo (1-4).
 */
function badge_riesgo(int $nivel): string
{
    $map = [
        1 => ['fa-shield-halved', 'var(--success)',  'Bajo'],
        2 => ['fa-exclamation',   'var(--info,#0ea5e9)', 'Moderado'],
        3 => ['fa-triangle-exclamation', '#f97316',  'Alto'],
        4 => ['fa-fire',          'var(--danger)',    'Crítico'],
    ];
    [$icon, $color, $label] = $map[$nivel] ?? $map[1];
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;font-size:.72rem;font-weight:700;background:' . $color . ';color:#fff">'
         . '<i class="fa ' . $icon . '"></i> ' . $label . '</span>';
}

