<?php
// ============================================================
// scripts/migracion_dia_cobro_cuotas.php
// ------------------------------------------------------------
// Migracion UNICA (one-time): realinea ic_cuotas.fecha_vencimiento
// de cuotas NO vencidas al dia_cobro real de su credito, para los
// cobradores cuyo esquema de visita cambio de Lun-Sab a Lun-Mie.
//
// Por que: generar_cuotas() nunca lee dia_cobro; las cuotas quedan
// fosilizadas en el dia de la semana de primer_vencimiento para
// siempre. Cuando se corrigio ic_creditos.dia_cobro (tarea anterior)
// las fechas de vencimiento ya generadas no se movieron, y los crons
// (actualizar_estados.php, recordatorio_mora.php) siguen comparando
// contra el dia viejo (jueves/viernes/sabado), donde el cobrador ya
// no visita.
//
// Formula (ver plan): shift_dias = ((dia_cobro - dow_original) + 7) % 7
// Siempre >= 0 -> nunca mueve una fecha al pasado. Como todas las
// cuotas semanales de un mismo credito comparten el mismo dow
// original, el mismo shift se aplica a todas por igual: preserva el
// espaciado de 7 dias sin colisiones.
//
// NO toca cuotas ya VENCIDA/vencidas hoy (mora ya acumulada = deuda
// real, decision confirmada con el cliente). Solo fecha_vencimiento >= hoy.
//
// ── Uso ──────────────────────────────────────────────────────
//   php scripts\migracion_dia_cobro_cuotas.php                          (dry-run, default)
//   php scripts\migracion_dia_cobro_cuotas.php --cobrador=sebadelga     (dry-run, un solo cobrador)
//   php scripts\migracion_dia_cobro_cuotas.php --commit --usuario-id=1  (aplica de verdad)
//   php scripts\migracion_dia_cobro_cuotas.php --commit --usuario-id=1 --cobrador=sebadelga
// ============================================================

declare(strict_types=1);

define('BASE_DIR', __DIR__ . '/..');
require_once BASE_DIR . '/config/conexion.php';
require_once BASE_DIR . '/config/funciones.php';

// ── Args ─────────────────────────────────────────────────────
$all_args = array_merge($argv ?? [], $_SERVER['argv'] ?? []);

function arg_valor(array $args, string $nombre): ?string
{
    foreach ($args as $a) {
        if (str_starts_with($a, $nombre . '=')) {
            return substr($a, strlen($nombre) + 1);
        }
    }
    return null;
}

$commit       = in_array('--commit', $all_args, true);
$solo_cobrador = arg_valor($all_args, '--cobrador');
$usuario_id_arg = arg_valor($all_args, '--usuario-id');
$limit        = (int) (arg_valor($all_args, '--limit') ?: 0);

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

log_msg('Iniciando migracion dia_cobro -> fecha_vencimiento' . ($commit ? ' [COMMIT]' : ' [DRY-RUN]'));

$pdo = obtener_conexion();

// ── 0. Validar usuario que ejecuta (solo si --commit) ───────
$usuario_id = 0;
if ($commit) {
    if (!$usuario_id_arg || !ctype_digit($usuario_id_arg)) {
        log_msg('ERROR: --commit requiere --usuario-id=<id> de un admin/supervisor valido.');
        exit(1);
    }
    $usuario_id = (int) $usuario_id_arg;
    $chk = $pdo->prepare("SELECT rol, activo FROM ic_usuarios WHERE id = ?");
    $chk->execute([$usuario_id]);
    $u = $chk->fetch();
    if (!$u || !in_array($u['rol'], ['admin', 'supervisor'], true) || !(int)$u['activo']) {
        log_msg('ERROR: --usuario-id no corresponde a un admin/supervisor activo.');
        exit(1);
    }
}

// ── 1. Resolver cobradores objetivo ─────────────────────────
$COBRADORES = $solo_cobrador ? [$solo_cobrador] : ['enzoteceira', 'jpbicego', 'sebadelga'];
$ph = implode(',', array_fill(0, count($COBRADORES), '?'));
$stmt = $pdo->prepare("SELECT id, usuario FROM ic_usuarios WHERE usuario IN ($ph) AND rol='cobrador'");
$stmt->execute($COBRADORES);
$usuarios_rows = $stmt->fetchAll();
if (count($usuarios_rows) !== count($COBRADORES)) {
    log_msg('ERROR: no se encontraron los ' . count($COBRADORES) . ' cobradores esperados. Encontrados: ' . count($usuarios_rows));
    exit(1);
}
$usuarios_por_nombre = [];
foreach ($usuarios_rows as $r) $usuarios_por_nombre[$r['usuario']] = (int) $r['id'];
$ids_cobradores = array_values($usuarios_por_nombre);
log_msg('Cobradores objetivo: ' . implode(', ', array_keys($usuarios_por_nombre)) . ' (ids: ' . implode(',', $ids_cobradores) . ')');

// ── 2. Universo candidato ────────────────────────────────────
$ph2 = implode(',', array_fill(0, count($ids_cobradores), '?'));
$sql = "
    SELECT cu.id AS cuota_id, cu.credito_id, cu.numero_cuota, cu.estado AS cuota_estado,
           cu.fecha_vencimiento AS fecha_actual, cu.monto_mora,
           cr.dia_cobro, u.usuario AS cobrador_usuario,
           cl.apellidos, cl.nombres
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_usuarios  u ON u.id  = cr.cobrador_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE u.id IN ($ph2)
      AND cr.frecuencia = 'semanal'
      AND cr.estado IN ('EN_CURSO', 'MOROSO')
      AND cu.estado IN ('PENDIENTE', 'PARCIAL', 'CAP_PAGADA')
      AND cu.fecha_vencimiento >= CURDATE()
      AND cr.dia_cobro IN (1, 2, 3)
    ORDER BY u.usuario, cr.id, cu.numero_cuota
";
$stmt = $pdo->prepare($sql);
$stmt->execute($ids_cobradores);
$filas = $stmt->fetchAll();
log_msg('Universo candidato (no vencidas, semanal, activo, dia_cobro Lun-Mie): ' . count($filas));

// Informativo: creditos de estos cobradores con dia_cobro fuera de la politica Lun-Mie (no se tocan)
$sqlFuera = "
    SELECT u.usuario, cr.dia_cobro, COUNT(*) AS cant_creditos
    FROM ic_creditos cr
    JOIN ic_usuarios u ON u.id = cr.cobrador_id
    WHERE u.id IN ($ph2)
      AND cr.frecuencia = 'semanal'
      AND cr.estado IN ('EN_CURSO', 'MOROSO')
      AND (cr.dia_cobro IS NULL OR cr.dia_cobro NOT IN (1, 2, 3))
    GROUP BY u.usuario, cr.dia_cobro
";
$stmtFuera = $pdo->prepare($sqlFuera);
$stmtFuera->execute($ids_cobradores);
$fueraPolitica = $stmtFuera->fetchAll();
if ($fueraPolitica) {
    log_msg('Creditos con dia_cobro fuera de Lun-Mie (informativo, NO se tocan, requieren corregir dia_cobro primero):');
    foreach ($fueraPolitica as $fp) {
        $dc = $fp['dia_cobro'] === null ? 'NULL' : $fp['dia_cobro'];
        log_msg("  {$fp['usuario']} dia_cobro=$dc: {$fp['cant_creditos']} credito(s)");
    }
}

// ── 3. Calcular shift/nueva_fecha por fila ──────────────────
$domingo_anomalos = 0;
foreach ($filas as &$f) {
    $dt = new DateTime($f['fecha_actual']);
    $dow = (int) $dt->format('N'); // 1=Lun...7=Dom
    if ($dow === 7) $domingo_anomalos++;
    $dia_cobro = (int) $f['dia_cobro'];
    $shift = (($dia_cobro - $dow) + 7) % 7;
    $f['dow_original'] = $dow;
    $f['shift_dias'] = $shift;
    $nueva = clone $dt;
    $nueva->modify("+{$shift} days");
    $f['nueva_fecha'] = $nueva->format('Y-m-d');
}
unset($f);

// Descartar ya alineadas
$lote = array_values(array_filter($filas, fn($f) => $f['shift_dias'] > 0));
log_msg('Ya alineadas (sin cambio): ' . (count($filas) - count($lote)));
log_msg('A migrar (antes de exclusiones): ' . count($lote));
if ($domingo_anomalos > 0) {
    log_msg("Nota: $domingo_anomalos cuota(s) con fecha_vencimiento en domingo detectadas — se corrigen igual que el resto.");
}

// Exclusion defensiva: PARCIAL/CAP_PAGADA con monto_mora > 0 (anomalia en cuota no vencida)
$excluidas_mora = array_values(array_filter($lote, fn($f) =>
    in_array($f['cuota_estado'], ['PARCIAL', 'CAP_PAGADA'], true) && (float)$f['monto_mora'] > 0
));
if ($excluidas_mora) {
    $ids_excl = array_column($excluidas_mora, 'cuota_id');
    $lote = array_values(array_filter($lote, fn($f) => !in_array($f['cuota_id'], $ids_excl, true)));
    log_msg('Excluidas por monto_mora>0 en cuota no vencida (anomalia, requiere revision manual): ' . count($excluidas_mora)
        . ' [ids: ' . implode(',', $ids_excl) . ']');
}

// ── 4. Deteccion de colisiones pre-existentes ───────────────
$credito_ids_lote = array_values(array_unique(array_column($lote, 'credito_id')));
$creditos_excluidos_colision = [];
if ($credito_ids_lote) {
    $ph3 = implode(',', array_fill(0, count($credito_ids_lote), '?'));
    $colStmt = $pdo->prepare("
        SELECT credito_id, fecha_vencimiento, COUNT(*) AS c
        FROM ic_cuotas
        WHERE credito_id IN ($ph3) AND estado IN ('PENDIENTE','PARCIAL','CAP_PAGADA','VENCIDA')
        GROUP BY credito_id, fecha_vencimiento
        HAVING c > 1
    ");
    $colStmt->execute($credito_ids_lote);
    foreach ($colStmt->fetchAll() as $c) {
        $creditos_excluidos_colision[(int)$c['credito_id']] = true;
    }
}
if ($creditos_excluidos_colision) {
    $lote = array_values(array_filter($lote, fn($f) => !isset($creditos_excluidos_colision[(int)$f['credito_id']])));
    log_msg('Creditos excluidos por colision pre-existente (fecha_vencimiento duplicada ya en BD): '
        . count($creditos_excluidos_colision) . ' [ids: ' . implode(',', array_keys($creditos_excluidos_colision)) . ']');
}

// smoke test opcional
if ($limit > 0) {
    $lote = array_slice($lote, 0, $limit);
    log_msg("Limitado a $limit filas por --limit (smoke test).");
}

// ── 5. Invariante de no-retroceso ───────────────────────────
$hoy_str = date('Y-m-d');
foreach ($lote as $f) {
    if ($f['nueva_fecha'] < $hoy_str) {
        log_msg("BUG CRITICO: cuota_id={$f['cuota_id']} calculo nueva_fecha={$f['nueva_fecha']} anterior a hoy. Abortando TODO.");
        exit(1);
    }
}
log_msg('Invariante no-retroceso: OK (0 violaciones)');

// ── 6. Resumen por cobrador y dia ───────────────────────────
$resumen = [];
foreach ($lote as $f) {
    $key = $f['cobrador_usuario'] . '|dow' . $f['dow_original'] . '->dow' . ($f['dia_cobro']);
    $resumen[$key] = ($resumen[$key] ?? 0) + 1;
}
log_msg('--- Resumen del lote final a migrar (' . count($lote) . ' cuotas) ---');
foreach ($resumen as $k => $c) log_msg("  $k: $c");

log_msg('--- Muestra (primeras 15 filas) ---');
foreach (array_slice($lote, 0, 15) as $f) {
    log_msg("  cuota_id={$f['cuota_id']} credito_id={$f['credito_id']} {$f['apellidos']}, {$f['nombres']} "
        . "({$f['cobrador_usuario']}) {$f['fecha_actual']} -> {$f['nueva_fecha']} (shift {$f['shift_dias']}d)");
}

if (empty($lote)) {
    log_msg('Nada para migrar. Fin.');
    exit(0);
}

// ── 7. Grupo de control (no se debe tocar) ──────────────────
function contar_control(PDO $pdo, array $ids_cobradores): int
{
    $ph = implode(',', array_fill(0, count($ids_cobradores), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ic_cuotas cu
        JOIN ic_creditos cr ON cr.id = cu.credito_id
        WHERE cr.cobrador_id IN ($ph)
          AND cr.frecuencia = 'semanal'
          AND (cu.estado = 'VENCIDA' OR (cu.estado = 'PARCIAL' AND cu.fecha_vencimiento < CURDATE()))
    ");
    $stmt->execute($ids_cobradores);
    return (int) $stmt->fetchColumn();
}
$control_antes = contar_control($pdo, $ids_cobradores);
log_msg("Grupo de control (VENCIDA / PARCIAL-vencida, no debe cambiar): $control_antes");

// ── 8. Backup a disco (siempre, dry-run incluido) ───────────
// Fuera del document root a proposito (contiene PII) — nunca dentro de BASE_DIR.
$backupDir = 'C:/wamp64/backups_creditos';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$ts = date('Ymd_His');
$jsonPath = "$backupDir/migracion_dia_cobro_cuotas_$ts.json";
$sqlPath  = "$backupDir/rollback_migracion_dia_cobro_cuotas_$ts.sql";

file_put_contents($jsonPath, json_encode([
    'fecha_generado' => date('Y-m-d H:i:s'),
    'commit' => $commit,
    'cobradores' => array_keys($usuarios_por_nombre),
    'total_filas' => count($lote),
    'filas' => $lote,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$sqlLines = ["-- Rollback de migracion_dia_cobro_cuotas_$ts — generado " . date('Y-m-d H:i:s')];
foreach ($lote as $f) {
    $sqlLines[] = sprintf(
        "UPDATE ic_cuotas SET fecha_vencimiento='%s' WHERE id=%d AND fecha_vencimiento='%s';",
        $f['fecha_actual'], $f['cuota_id'], $f['nueva_fecha']
    );
}
file_put_contents($sqlPath, implode("\n", $sqlLines) . "\n");
log_msg("Backup JSON: $jsonPath");
log_msg("Rollback SQL: $sqlPath");

// ── 9. Ejecutar dentro de transaccion ───────────────────────
try {
    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE ic_cuotas SET fecha_vencimiento = ?
        WHERE id = ? AND fecha_vencimiento = ? AND estado = ?
    ");

    $movidas_por_cobrador = array_fill_keys(array_keys($usuarios_por_nombre), 0);
    $omitidas_concurrencia = [];

    foreach ($lote as $f) {
        $upd->execute([$f['nueva_fecha'], $f['cuota_id'], $f['fecha_actual'], $f['cuota_estado']]);
        if ($upd->rowCount() !== 1) {
            $omitidas_concurrencia[] = $f;
            continue;
        }
        $movidas_por_cobrador[$f['cobrador_usuario']]++;
    }

    if ($omitidas_concurrencia) {
        log_msg('Omitidas por concurrencia (cambiaron entre el SELECT y el UPDATE): ' . count($omitidas_concurrencia)
            . ' [ids: ' . implode(',', array_column($omitidas_concurrencia, 'cuota_id')) . ']');
    }

    $total_migradas = array_sum($movidas_por_cobrador);

    // ── 10. Verificacion post: fecha efectivamente escrita ──
    $verifStmt = $pdo->prepare("SELECT fecha_vencimiento FROM ic_cuotas WHERE id = ?");
    $ids_omitidas = array_column($omitidas_concurrencia, 'cuota_id');
    foreach ($lote as $f) {
        if (in_array($f['cuota_id'], $ids_omitidas, true)) continue;
        $verifStmt->execute([$f['cuota_id']]);
        $actual = $verifStmt->fetchColumn();
        if ($actual !== $f['nueva_fecha']) {
            throw new RuntimeException("Verificacion post fallo en cuota_id={$f['cuota_id']}: esperado {$f['nueva_fecha']}, encontrado $actual");
        }
    }
    log_msg("Verificacion post-UPDATE (fecha escrita correctamente): OK ($total_migradas filas)");

    // ── 11. Grupo de control no debe haber cambiado ─────────
    $control_despues = contar_control($pdo, $ids_cobradores);
    if ($control_despues !== $control_antes) {
        throw new RuntimeException("Grupo de control cambio de $control_antes a $control_despues — algo toco cuotas fuera de alcance.");
    }
    log_msg("Grupo de control post-UPDATE: $control_despues (sin cambios, OK)");

    // ── 12. Colisiones post-update ───────────────────────────
    if ($credito_ids_lote) {
        $ph3 = implode(',', array_fill(0, count($credito_ids_lote), '?'));
        $colStmt2 = $pdo->prepare("
            SELECT credito_id, fecha_vencimiento, COUNT(*) c
            FROM ic_cuotas
            WHERE credito_id IN ($ph3) AND estado IN ('PENDIENTE','PARCIAL','CAP_PAGADA','VENCIDA')
            GROUP BY credito_id, fecha_vencimiento HAVING c > 1
        ");
        $colStmt2->execute($credito_ids_lote);
        $colisiones_post = $colStmt2->fetchAll();
        if ($colisiones_post) {
            throw new RuntimeException('Colision post-UPDATE detectada en credito_id='
                . implode(',', array_column($colisiones_post, 'credito_id')));
        }
    }
    log_msg('Verificacion de colisiones post-UPDATE: OK (0 duplicados)');

    // ── 13. Alineacion final: WEEKDAY+1 == dia_cobro ────────
    $ids_migradas = array_column($lote, 'cuota_id');
    $ids_migradas = array_values(array_diff($ids_migradas, $ids_omitidas));
    if ($ids_migradas) {
        $ph4 = implode(',', array_fill(0, count($ids_migradas), '?'));
        $alinStmt = $pdo->prepare("
            SELECT COUNT(*) FROM ic_cuotas cu
            JOIN ic_creditos cr ON cr.id = cu.credito_id
            WHERE cu.id IN ($ph4) AND (WEEKDAY(cu.fecha_vencimiento) + 1) <> cr.dia_cobro
        ");
        $alinStmt->execute($ids_migradas);
        $desalineadas = (int) $alinStmt->fetchColumn();
        if ($desalineadas > 0) {
            throw new RuntimeException("$desalineadas cuotas migradas quedaron desalineadas respecto a dia_cobro.");
        }
    }
    log_msg('Verificacion de alineacion final (WEEKDAY = dia_cobro): OK');

    if (!$commit) {
        log_msg("[DRY-RUN] Todas las verificaciones OK. Se aplicarian $total_migradas cambios. ROLLBACK (nada se guarda).");
        $pdo->rollBack();
    } else {
        foreach ($usuarios_por_nombre as $nombre => $uid) {
            if ($movidas_por_cobrador[$nombre] === 0) continue;
            registrar_log($pdo, $usuario_id, 'CUOTAS_REPROGRAMADAS_MASIVO', 'usuario', $uid,
                "Reprogramacion fecha_vencimiento a dia_cobro: {$movidas_por_cobrador[$nombre]} cuotas movidas (semanal). "
                . "Motivo: cambio esquema de visita a Lun-Mie.");
        }
        $detalle_total = implode(' ', array_map(
            fn($k, $v) => "$k=$v",
            array_keys($movidas_por_cobrador), $movidas_por_cobrador
        ));
        registrar_log($pdo, $usuario_id, 'CUOTAS_REPROGRAMADAS_MASIVO', 'sistema', null,
            "Total $total_migradas cuotas migradas. $detalle_total. Backup: " . basename($jsonPath));

        $pdo->commit();
        log_msg("=== COMMIT OK. $total_migradas cuotas reprogramadas. ===");
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_msg('ERROR: ' . $e->getMessage() . ' — ROLLBACK total. Ningun cambio se guardo.');
    exit(1);
}

log_msg('Fin.');
exit(0);
