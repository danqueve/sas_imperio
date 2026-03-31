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
               cu.fecha_vencimiento, cu.estado AS cuota_estado, cu.numero_cuota,
               cr.interes_moratorio_pct,
               COALESCE(cr.articulo_desc, a.descripcion, '') AS articulo_snap,
               cl.telefono AS cliente_tel, cl.nombres AS cliente_nombres
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu   ON pt.cuota_id    = cu.id
        JOIN ic_creditos cr ON cu.credito_id  = cr.id
        JOIN ic_clientes cl ON cr.cliente_id  = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE pt.cobrador_id = ? AND pt.fecha_jornada = ? AND pt.estado = 'PENDIENTE'
    ");
    $stmt->execute([$cobrador_id, $fecha]);
    $pagos = $stmt->fetchAll();

    foreach ($pagos as $pago) {
        try {
            $pdo->beginTransaction();

            // 1. Insertar en pagos_confirmados con snapshot completo
            $semana_lunes = calcular_semana_lunes($pago['fecha_jornada'] ?: $fecha);
            $ins = $pdo->prepare("
                INSERT INTO ic_pagos_confirmados
                    (pago_temp_id, cuota_id, cobrador_id, aprobador_id, fecha_pago,
                     monto_efectivo, monto_transferencia, monto_total, monto_mora_cobrada,
                     es_cuota_pura, observaciones, fecha_jornada, semana_lunes, origen,
                     monto_cuota_orig, numero_cuota, fecha_vcto_orig, articulo_snap)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            ]);

            // 2. Calcular nuevo saldo y estado de la cuota
            $monto_base  = (float) $pago['monto_cuota'];
            $saldo_prev  = (float) ($pago['saldo_pagado'] ?? 0);

            // Fase 1: usar mora congelada al momento del registro del cobrador
            // Fallback: cuota.monto_mora → recálculo (pagos legacy sin mora_congelada)
            $mora_frozen = (float) ($pago['mora_congelada'] ?? 0);
            if ($mora_frozen <= 0) {
                $mora_frozen = (float) $pago['monto_mora'];
            }
            if ($mora_frozen <= 0) {
                $mora_frozen = calcular_mora(
                    $monto_base,
                    dias_atraso_habiles($pago['fecha_vencimiento']),
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

            // 3. Marcar pago temporal como APROBADO
            $pdo->prepare("UPDATE ic_pagos_temporales SET estado='APROBADO' WHERE id=?")
                ->execute([$pago['id']]);

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
                        SUM(CASE WHEN estado = 'VENCIDA' THEN 1 ELSE 0 END) AS vencidas
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
            MAX(dias_atraso) AS max_atraso
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
            COUNT(CASE WHEN cu.monto_mora > 0 THEN 1 END)                            AS con_mora,
            COALESCE(cr.veces_refinanciado, 0)                                        AS refinanciado
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cu.credito_id = ?
    ");
    $stmt->execute([$credito_id]);
    $d = $stmt->fetch();
    if (!$d) return 1;

    $cv  = (int)   $d['cuotas_vencidas'];
    $avg = (float) $d['avg_atraso'];
    $cm  = (int)   $d['con_mora'];
    $ref = (int)   $d['refinanciado'];

    if ($ref >= 2 || $cv >= 4 || $avg > 30) return 4; // Crítico
    if ($ref >= 1 || $cv >= 2 || $avg > 14 || $cm >= 3) return 3; // Alto
    if ($cv >= 1 || $avg > 0 || $cm >= 1) return 2; // Moderado
    return 1; // Bajo
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

