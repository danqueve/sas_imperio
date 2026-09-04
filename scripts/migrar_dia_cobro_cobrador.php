<?php
// ============================================================
// scripts/migrar_dia_cobro_cobrador.php
// ------------------------------------------------------------
// Migracion UNICA (one-time, parametrizada): cambia el dia_cobro de
// TODOS los clientes de un cobrador (ic_clientes.dia_cobro, la
// preferencia/default) y de sus creditos semanales activos
// (ic_creditos.dia_cobro, el que realmente arma la agenda semanal),
// y reprograma ic_cuotas.fecha_vencimiento de las cuotas semanales
// pendientes (no vencidas) para que caigan de verdad en el nuevo dia.
//
// Por que: generar_cuotas() nunca lee dia_cobro; las cuotas quedan
// fosilizadas en el dia de la semana de su fecha original para
// siempre. Cambiar solo el campo dia_cobro mueve al cliente a la
// pestana correcta en la vista semanal de la agenda, pero no mueve
// las fechas reales de vencimiento — sin este paso, el cliente puede
// seguir figurando como vencido otro dia en la agenda principal.
//
// Generaliza el precedente ya corrido en produccion para otros 3
// cobradores (git show b23bbfb:scripts/migracion_dia_cobro_cuotas.php,
// que asumia dia_cobro ya corregido en una tarea previa aparte y solo
// migraba fecha_vencimiento). Aca se hace todo en una sola transaccion
// atomica: menos ventanas de inconsistencia.
//
// Formula (igual que el precedente): shift_dias = ((dia_destino - dow_actual) + 7) % 7
// Siempre >= 0 -> nunca mueve una fecha al pasado. Se calcula POR CUOTA
// (no por credito), asi que realinea correctamente incluso las que ya
// hubieran derivado a otro dia por ediciones manuales previas.
//
// NO toca cuotas ya VENCIDA/vencidas hoy (mora ya acumulada = deuda
// real). Solo fecha_vencimiento >= hoy. NO toca creditos quincenales/
// mensuales/diarios (dia_cobro no tiene el mismo sentido semana a
// semana para esas frecuencias).
//
// ── Uso ──────────────────────────────────────────────────────
//   php scripts\migrar_dia_cobro_cobrador.php --cobrador=silvadaniel --dia=6                        (dry-run, default)
//   php scripts\migrar_dia_cobro_cobrador.php --cobrador=silvadaniel --dia=6 --commit --usuario-id=1
//   php scripts\migrar_dia_cobro_cobrador.php --cobrador=silvadaniel --dia=6 --commit --usuario-id=1 --limit=5   (smoke test)
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

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

$commit         = in_array('--commit', $all_args, true);
$cobrador_arg   = arg_valor($all_args, '--cobrador');
$dia_arg        = arg_valor($all_args, '--dia');
$usuario_id_arg = arg_valor($all_args, '--usuario-id');
$limit          = (int) (arg_valor($all_args, '--limit') ?: 0);

if (!$cobrador_arg) {
    log_msg('ERROR: falta --cobrador=<usuario> (el "usuario" de login del cobrador, ej. --cobrador=silvadaniel).');
    exit(1);
}
if (!$dia_arg || !ctype_digit($dia_arg) || (int)$dia_arg < 1 || (int)$dia_arg > 6) {
    log_msg('ERROR: falta o es invalido --dia=<1-6> (1=Lun ... 6=Sab).');
    exit(1);
}
$dia_destino = (int) $dia_arg;

log_msg('Iniciando migracion dia_cobro para cobrador=' . $cobrador_arg . ' dia_destino=' . $dia_destino
    . ($commit ? ' [COMMIT]' : ' [DRY-RUN]'));

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

// ── 1. Resolver cobrador objetivo ───────────────────────────
$stmt = $pdo->prepare("SELECT id, usuario, nombre, apellido FROM ic_usuarios WHERE usuario = ? AND rol = 'cobrador'");
$stmt->execute([$cobrador_arg]);
$cobrador = $stmt->fetch();
if (!$cobrador) {
    log_msg("ERROR: no se encontro un cobrador con usuario='$cobrador_arg'.");
    exit(1);
}
$cobrador_id = (int) $cobrador['id'];
log_msg("Cobrador objetivo: {$cobrador['usuario']} ({$cobrador['apellido']}, {$cobrador['nombre']}) id={$cobrador_id}");

// ── 2. Clientes a actualizar (ic_clientes.dia_cobro — todos, sin
//      importar frecuencia de sus creditos) ─────────────────
$clientesStmt = $pdo->prepare("SELECT id, dia_cobro FROM ic_clientes WHERE cobrador_id = ?");
$clientesStmt->execute([$cobrador_id]);
$clientes_todos = $clientesStmt->fetchAll();
$clientes_a_cambiar = array_values(array_filter($clientes_todos, fn($c) => (int)($c['dia_cobro'] ?? -1) !== $dia_destino));
log_msg('Clientes de este cobrador: ' . count($clientes_todos)
    . ' | ya en dia_destino: ' . (count($clientes_todos) - count($clientes_a_cambiar))
    . ' | a cambiar: ' . count($clientes_a_cambiar));

// ── 3. Creditos semanales activos a actualizar (ic_creditos.dia_cobro) ──
$creditosStmt = $pdo->prepare("
    SELECT id, dia_cobro FROM ic_creditos
    WHERE cobrador_id = ? AND estado IN ('EN_CURSO', 'MOROSO') AND frecuencia = 'semanal'
");
$creditosStmt->execute([$cobrador_id]);
$creditos_todos = $creditosStmt->fetchAll();
$creditos_a_cambiar = array_values(array_filter($creditos_todos, fn($c) => (int)($c['dia_cobro'] ?? -1) !== $dia_destino));
log_msg('Creditos semanales activos: ' . count($creditos_todos)
    . ' | ya en dia_destino: ' . (count($creditos_todos) - count($creditos_a_cambiar))
    . ' | a cambiar: ' . count($creditos_a_cambiar));

// Informativo: creditos no-semanales de este cobrador (no se tocan)
$noSemStmt = $pdo->prepare("
    SELECT frecuencia, COUNT(*) AS cant FROM ic_creditos
    WHERE cobrador_id = ? AND estado IN ('EN_CURSO', 'MOROSO') AND frecuencia != 'semanal'
    GROUP BY frecuencia
");
$noSemStmt->execute([$cobrador_id]);
foreach ($noSemStmt->fetchAll() as $ns) {
    log_msg("  (informativo, NO se toca) frecuencia={$ns['frecuencia']}: {$ns['cant']} credito(s)");
}

// ── 4. Universo candidato de cuotas a reprogramar ───────────
$sql = "
    SELECT cu.id AS cuota_id, cu.credito_id, cu.numero_cuota, cu.estado AS cuota_estado,
           cu.fecha_vencimiento AS fecha_actual, cu.monto_mora,
           cl.apellidos, cl.nombres
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE cr.cobrador_id = ?
      AND cr.frecuencia = 'semanal'
      AND cr.estado IN ('EN_CURSO', 'MOROSO')
      AND cu.estado IN ('PENDIENTE', 'PARCIAL', 'CAP_PAGADA')
      AND cu.fecha_vencimiento >= CURDATE()
    ORDER BY cr.id, cu.numero_cuota
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$cobrador_id]);
$filas = $stmt->fetchAll();
log_msg('Universo candidato de cuotas (no vencidas, semanal, activo): ' . count($filas));

// ── 5. Calcular shift/nueva_fecha por fila ──────────────────
$domingo_anomalos = 0;
foreach ($filas as &$f) {
    $dt = new DateTime($f['fecha_actual']);
    $dow = (int) $dt->format('N'); // 1=Lun...7=Dom
    if ($dow === 7) $domingo_anomalos++;
    $shift = (($dia_destino - $dow) + 7) % 7;
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

// ── 6. Deteccion de colisiones pre-existentes ───────────────
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

// ── 7. Invariante de no-retroceso ───────────────────────────
$hoy_str = date('Y-m-d');
foreach ($lote as $f) {
    if ($f['nueva_fecha'] < $hoy_str) {
        log_msg("BUG CRITICO: cuota_id={$f['cuota_id']} calculo nueva_fecha={$f['nueva_fecha']} anterior a hoy. Abortando TODO.");
        exit(1);
    }
}
log_msg('Invariante no-retroceso: OK (0 violaciones)');

// ── 8. Resumen ───────────────────────────────────────────────
$resumen = [];
foreach ($lote as $f) {
    $key = 'dow' . $f['dow_original'] . '->dia' . $dia_destino;
    $resumen[$key] = ($resumen[$key] ?? 0) + 1;
}
log_msg('--- Resumen del lote final a migrar (' . count($lote) . ' cuotas) ---');
foreach ($resumen as $k => $c) log_msg("  $k: $c");

log_msg('--- Muestra (primeras 15 filas) ---');
foreach (array_slice($lote, 0, 15) as $f) {
    log_msg("  cuota_id={$f['cuota_id']} credito_id={$f['credito_id']} {$f['apellidos']}, {$f['nombres']} "
        . "{$f['fecha_actual']} -> {$f['nueva_fecha']} (shift {$f['shift_dias']}d)");
}

if (empty($clientes_a_cambiar) && empty($creditos_a_cambiar) && empty($lote)) {
    log_msg('Nada para migrar (clientes, creditos y cuotas ya estan todos alineados). Fin.');
    exit(0);
}

// ── 9. Grupo de control (no se debe tocar) ──────────────────
function contar_control(PDO $pdo, int $cobrador_id): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ic_cuotas cu
        JOIN ic_creditos cr ON cr.id = cu.credito_id
        WHERE cr.cobrador_id = ?
          AND cr.frecuencia = 'semanal'
          AND (cu.estado = 'VENCIDA' OR (cu.estado = 'PARCIAL' AND cu.fecha_vencimiento < CURDATE()))
    ");
    $stmt->execute([$cobrador_id]);
    return (int) $stmt->fetchColumn();
}
$control_antes = contar_control($pdo, $cobrador_id);
log_msg("Grupo de control (VENCIDA / PARCIAL-vencida, no debe cambiar): $control_antes");

// ── 10. Backup a disco (siempre, dry-run incluido) ──────────
// Fuera del document root a proposito (contiene PII) — nunca dentro de BASE_DIR.
// Portable entre entornos: dos niveles arriba de la raiz REAL del proyecto
// (realpath resuelve el ".." de BASE_DIR antes de contar niveles). En WAMP
// local: c:/wamp64/www/creditos -> c:/wamp64/backups_creditos (fuera de
// c:/wamp64/www, que es lo que sirve Apache). En Nova:
// /home/usuario/public_html/creditos -> /home/usuario/backups_creditos
// (fuera de public_html, que es el document root ahi).
$backupDir = dirname(realpath(BASE_DIR), 2) . '/backups_creditos';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$ts = date('Ymd_His');
$jsonPath = "$backupDir/migracion_dia_cobro_{$cobrador_arg}_$ts.json";
$sqlPath  = "$backupDir/rollback_migracion_dia_cobro_{$cobrador_arg}_$ts.sql";

file_put_contents($jsonPath, json_encode([
    'fecha_generado' => date('Y-m-d H:i:s'),
    'commit' => $commit,
    'cobrador' => $cobrador_arg,
    'cobrador_id' => $cobrador_id,
    'dia_destino' => $dia_destino,
    'clientes_a_cambiar' => $clientes_a_cambiar,
    'creditos_a_cambiar' => $creditos_a_cambiar,
    'cuotas_a_cambiar' => $lote,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$sqlLines = ["-- Rollback de migracion_dia_cobro_{$cobrador_arg}_$ts — generado " . date('Y-m-d H:i:s')];
foreach ($clientes_a_cambiar as $c) {
    $valOrig = $c['dia_cobro'] === null ? 'NULL' : (int)$c['dia_cobro'];
    $sqlLines[] = sprintf("UPDATE ic_clientes SET dia_cobro=%s WHERE id=%d AND dia_cobro=%d;", $valOrig, $c['id'], $dia_destino);
}
foreach ($creditos_a_cambiar as $c) {
    $valOrig = $c['dia_cobro'] === null ? 'NULL' : (int)$c['dia_cobro'];
    $sqlLines[] = sprintf("UPDATE ic_creditos SET dia_cobro=%s WHERE id=%d AND dia_cobro=%d;", $valOrig, $c['id'], $dia_destino);
}
foreach ($lote as $f) {
    $sqlLines[] = sprintf(
        "UPDATE ic_cuotas SET fecha_vencimiento='%s' WHERE id=%d AND fecha_vencimiento='%s';",
        $f['fecha_actual'], $f['cuota_id'], $f['nueva_fecha']
    );
}
file_put_contents($sqlPath, implode("\n", $sqlLines) . "\n");
log_msg("Backup JSON: $jsonPath");
log_msg("Rollback SQL: $sqlPath");

// ── 11. Ejecutar dentro de transaccion ──────────────────────
try {
    $pdo->beginTransaction();

    // 11a. Clientes
    $updCliente = $pdo->prepare("UPDATE ic_clientes SET dia_cobro = ? WHERE id = ? AND (dia_cobro <=> ?)");
    $clientes_movidos = 0;
    foreach ($clientes_a_cambiar as $c) {
        $updCliente->execute([$dia_destino, $c['id'], $c['dia_cobro']]);
        $clientes_movidos += $updCliente->rowCount();
    }

    // 11b. Creditos
    $updCredito = $pdo->prepare("UPDATE ic_creditos SET dia_cobro = ? WHERE id = ? AND (dia_cobro <=> ?)");
    $creditos_movidos = 0;
    foreach ($creditos_a_cambiar as $c) {
        $updCredito->execute([$dia_destino, $c['id'], $c['dia_cobro']]);
        $creditos_movidos += $updCredito->rowCount();
    }

    // 11c. Cuotas
    $updCuota = $pdo->prepare("
        UPDATE ic_cuotas SET fecha_vencimiento = ?
        WHERE id = ? AND fecha_vencimiento = ? AND estado = ?
    ");
    $cuotas_movidas = 0;
    $omitidas_concurrencia = [];
    foreach ($lote as $f) {
        $updCuota->execute([$f['nueva_fecha'], $f['cuota_id'], $f['fecha_actual'], $f['cuota_estado']]);
        if ($updCuota->rowCount() !== 1) {
            $omitidas_concurrencia[] = $f;
            continue;
        }
        $cuotas_movidas++;
    }
    if ($omitidas_concurrencia) {
        log_msg('Omitidas por concurrencia (cambiaron entre el SELECT y el UPDATE): ' . count($omitidas_concurrencia)
            . ' [ids: ' . implode(',', array_column($omitidas_concurrencia, 'cuota_id')) . ']');
    }

    log_msg("Filas actualizadas — clientes: $clientes_movidos | creditos: $creditos_movidos | cuotas: $cuotas_movidas");

    // ── 12. Verificacion post: fecha efectivamente escrita ──
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
    log_msg("Verificacion post-UPDATE cuotas (fecha escrita correctamente): OK ($cuotas_movidas filas)");

    // ── 13. Grupo de control no debe haber cambiado ─────────
    $control_despues = contar_control($pdo, $cobrador_id);
    if ($control_despues !== $control_antes) {
        throw new RuntimeException("Grupo de control cambio de $control_antes a $control_despues — algo toco cuotas fuera de alcance.");
    }
    log_msg("Grupo de control post-UPDATE: $control_despues (sin cambios, OK)");

    // ── 14. Colisiones post-update ───────────────────────────
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

    // ── 15. Alineacion final: WEEKDAY+1 == dia_destino ──────
    $ids_migradas = array_column($lote, 'cuota_id');
    $ids_migradas = array_values(array_diff($ids_migradas, $ids_omitidas));
    if ($ids_migradas) {
        $ph4 = implode(',', array_fill(0, count($ids_migradas), '?'));
        $alinStmt = $pdo->prepare("
            SELECT COUNT(*) FROM ic_cuotas
            WHERE id IN ($ph4) AND (WEEKDAY(fecha_vencimiento) + 1) <> ?
        ");
        $alinStmt->execute([...$ids_migradas, $dia_destino]);
        $desalineadas = (int) $alinStmt->fetchColumn();
        if ($desalineadas > 0) {
            throw new RuntimeException("$desalineadas cuotas migradas quedaron desalineadas respecto al dia_destino.");
        }
    }
    log_msg('Verificacion de alineacion final (WEEKDAY = dia_destino): OK');

    if (!$commit) {
        log_msg("[DRY-RUN] Todas las verificaciones OK. Se aplicarian: $clientes_movidos cliente(s), "
            . "$creditos_movidos credito(s), $cuotas_movidas cuota(s). ROLLBACK (nada se guarda).");
        $pdo->rollBack();
    } else {
        registrar_log($pdo, $usuario_id, 'DIA_COBRO_MIGRADO_MASIVO', 'usuario', $cobrador_id,
            "Dia de cobro migrado a " . nombre_dia($dia_destino) . " para {$cobrador['usuario']}: "
            . "$clientes_movidos cliente(s), $creditos_movidos credito(s) semanal(es), $cuotas_movidas cuota(s) reprogramada(s). "
            . "Backup: " . basename($jsonPath));

        $pdo->commit();
        log_msg("=== COMMIT OK. $clientes_movidos cliente(s), $creditos_movidos credito(s), $cuotas_movidas cuota(s). ===");
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_msg('ERROR: ' . $e->getMessage() . ' — ROLLBACK total. Ningun cambio se guardo.');
    exit(1);
}

log_msg('Fin.');
exit(0);
