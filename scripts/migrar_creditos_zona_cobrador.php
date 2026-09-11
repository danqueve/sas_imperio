<?php
// ============================================================
// scripts/migrar_creditos_zona_cobrador.php
// ------------------------------------------------------------
// Migracion UNICA (one-time, parametrizada): completa una reasignacion
// de cobrador que quedo a medias — el cliente (ic_clientes.cobrador_id)
// ya fue movido al cobrador destino, pero sus creditos
// (ic_creditos.cobrador_id) todavia apuntan al cobrador origen, para
// los clientes de una o mas zonas puntuales.
//
// Caso real que motiva este script: silvadaniel -> cfk para las zonas
// Leales y Bella Vista. ic_clientes ya decia cfk para esos 37 clientes;
// 33 de sus creditos (EN_CURSO/MOROSO/FINALIZADO) seguian con
// cobrador_id de silvadaniel. Esto es distinto de admin/migrar_cobrador.php
// (que mueve TODOS los clientes/creditos de un cobrador entero, sin
// filtro de zona) — aca el filtro es por zona, y el cliente puede ya
// estar movido o no.
//
// Que mueve (siempre TODOS los estados de credito, mismo criterio que
// admin/migrar_cobrador.php):
//   - ic_clientes.cobrador_id  : clientes de la(s) zona(s) que todavia
//                                 estan en origen -> destino.
//   - ic_creditos.cobrador_id  : creditos (cualquier estado) de esa(s)
//                                 zona(s) que todavia apuntan a origen
//                                 -> destino.
// No toca: dia_cobro, fecha_vencimiento de cuotas, ni ic_pagos_confirmados/
// ic_pagos_temporales.cobrador_id (atribucion historica de quien cobro
// realmente cada pago — mismo criterio que admin/migrar_cobrador.php,
// que tampoco los toca).
//
// ── Uso ──────────────────────────────────────────────────────
//   php scripts\migrar_creditos_zona_cobrador.php --origen=silvadaniel --destino=cfk --zonas="Leales,Bella Vista"                      (dry-run, default)
//   php scripts\migrar_creditos_zona_cobrador.php --origen=silvadaniel --destino=cfk --zonas="Leales,Bella Vista" --commit --usuario-id=3
// ============================================================

declare(strict_types=1);

define('BASE_DIR', __DIR__ . '/..');
require_once BASE_DIR . '/config/conexion.php';
require_once BASE_DIR . '/config/funciones.php';

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
$origen_arg     = arg_valor($all_args, '--origen');
$destino_arg    = arg_valor($all_args, '--destino');
$zonas_arg      = arg_valor($all_args, '--zonas');
$usuario_id_arg = arg_valor($all_args, '--usuario-id');

if (!$origen_arg || !$destino_arg || !$zonas_arg) {
    log_msg('ERROR: faltan argumentos. Uso: --origen=<usuario> --destino=<usuario> --zonas="Zona1,Zona2"');
    exit(1);
}
$zonas = array_values(array_filter(array_map('trim', explode(',', $zonas_arg))));
if (empty($zonas)) {
    log_msg('ERROR: --zonas vacio tras parsear.');
    exit(1);
}

log_msg('Iniciando migracion de creditos por zona: origen=' . $origen_arg . ' destino=' . $destino_arg
    . ' zonas=[' . implode(', ', $zonas) . ']' . ($commit ? ' [COMMIT]' : ' [DRY-RUN]'));

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
    if (!$u || !in_array($u['rol'], ['admin', 'supervisor'], true) || !(int) $u['activo']) {
        log_msg('ERROR: --usuario-id no corresponde a un admin/supervisor activo.');
        exit(1);
    }
}

// ── 1. Resolver cobradores ───────────────────────────────────
function resolver_cobrador(PDO $pdo, string $usuario): array
{
    $stmt = $pdo->prepare("SELECT id, usuario, nombre, apellido FROM ic_usuarios WHERE usuario = ? AND rol = 'cobrador'");
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();
    if (!$u) {
        log_msg("ERROR: no se encontro un cobrador con usuario='$usuario'.");
        exit(1);
    }
    return $u;
}
$origen  = resolver_cobrador($pdo, $origen_arg);
$destino = resolver_cobrador($pdo, $destino_arg);
$origen_id  = (int) $origen['id'];
$destino_id = (int) $destino['id'];
if ($origen_id === $destino_id) {
    log_msg('ERROR: origen y destino no pueden ser el mismo cobrador.');
    exit(1);
}
log_msg("Origen:  {$origen['usuario']} ({$origen['apellido']}, {$origen['nombre']}) id=$origen_id");
log_msg("Destino: {$destino['usuario']} ({$destino['apellido']}, {$destino['nombre']}) id=$destino_id");

$zona_ph = implode(',', array_fill(0, count($zonas), '?'));

// ── 2. Clientes de esas zonas que todavia estan en origen ───
$clStmt = $pdo->prepare("SELECT id, cobrador_id, apellidos, nombres, zona FROM ic_clientes WHERE zona IN ($zona_ph) AND cobrador_id = ?");
$clStmt->execute([...$zonas, $origen_id]);
$clientes_a_mover = $clStmt->fetchAll();
log_msg('Clientes de esas zonas que todavia estan en origen: ' . count($clientes_a_mover));

// ── 3. Creditos (TODOS los estados) de esas zonas que todavia
//      apuntan a origen — sin importar si el cliente ya se movio ──
$crStmt = $pdo->prepare("
    SELECT cr.id, cr.cobrador_id, cr.estado, cr.monto_total, cl.apellidos, cl.nombres, cl.zona
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE cl.zona IN ($zona_ph) AND cr.cobrador_id = ?
    ORDER BY cl.zona, cl.apellidos, cr.id
");
$crStmt->execute([...$zonas, $origen_id]);
$creditos_a_mover = $crStmt->fetchAll();
log_msg('Creditos de esas zonas que todavia estan en origen: ' . count($creditos_a_mover));

$por_estado = [];
foreach ($creditos_a_mover as $c) $por_estado[$c['estado']] = ($por_estado[$c['estado']] ?? 0) + 1;
foreach ($por_estado as $estado => $n) log_msg("  estado=$estado: $n credito(s)");

log_msg('--- Muestra (primeras 15 filas) ---');
foreach (array_slice($creditos_a_mover, 0, 15) as $c) {
    log_msg("  credito_id={$c['id']} estado={$c['estado']} zona={$c['zona']} {$c['apellidos']}, {$c['nombres']} monto=" . number_format((float) $c['monto_total'], 2));
}

if (empty($clientes_a_mover) && empty($creditos_a_mover)) {
    log_msg('Nada para migrar (clientes y creditos ya estan todos en destino). Fin.');
    exit(0);
}

// ── 4. Grupo de control (no debe tocarse): creditos de origen en
//      OTRAS zonas — confirma que el filtro no se escapo de alcance ──
$ctrlStmt = $pdo->prepare("
    SELECT COUNT(*) FROM ic_creditos cr
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE cr.cobrador_id = ? AND cl.zona NOT IN ($zona_ph)
");
$ctrlStmt->execute([$origen_id, ...$zonas]);
$control_antes = (int) $ctrlStmt->fetchColumn();
log_msg("Grupo de control (creditos de origen en otras zonas, no debe cambiar): $control_antes");

// ── 5. Backup a disco (siempre, dry-run incluido) ───────────
$backupDir = dirname(realpath(BASE_DIR), 2) . '/backups_creditos';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$ts = date('Ymd_His');
$jsonPath = "$backupDir/migracion_zona_{$origen_arg}_a_{$destino_arg}_$ts.json";
$sqlPath  = "$backupDir/rollback_migracion_zona_{$origen_arg}_a_{$destino_arg}_$ts.sql";

file_put_contents($jsonPath, json_encode([
    'fecha_generado' => date('Y-m-d H:i:s'),
    'commit' => $commit,
    'origen' => $origen_arg,
    'origen_id' => $origen_id,
    'destino' => $destino_arg,
    'destino_id' => $destino_id,
    'zonas' => $zonas,
    'clientes_a_mover' => $clientes_a_mover,
    'creditos_a_mover' => $creditos_a_mover,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$sqlLines = ["-- Rollback de migracion_zona_{$origen_arg}_a_{$destino_arg}_$ts — generado " . date('Y-m-d H:i:s')];
foreach ($clientes_a_mover as $c) {
    $sqlLines[] = sprintf("UPDATE ic_clientes SET cobrador_id=%d WHERE id=%d AND cobrador_id=%d;", $origen_id, $c['id'], $destino_id);
}
foreach ($creditos_a_mover as $c) {
    $sqlLines[] = sprintf("UPDATE ic_creditos SET cobrador_id=%d WHERE id=%d AND cobrador_id=%d;", $origen_id, $c['id'], $destino_id);
}
file_put_contents($sqlPath, implode("\n", $sqlLines) . "\n");
log_msg("Backup JSON: $jsonPath");
log_msg("Rollback SQL: $sqlPath");

// ── 6. Ejecutar dentro de transaccion ───────────────────────
try {
    $pdo->beginTransaction();

    $updCliente = $pdo->prepare("UPDATE ic_clientes SET cobrador_id = ? WHERE id = ? AND cobrador_id = ?");
    $clientes_movidos = 0;
    foreach ($clientes_a_mover as $c) {
        $updCliente->execute([$destino_id, $c['id'], $origen_id]);
        $clientes_movidos += $updCliente->rowCount();
    }

    $updCredito = $pdo->prepare("UPDATE ic_creditos SET cobrador_id = ? WHERE id = ? AND cobrador_id = ?");
    $creditos_movidos = 0;
    foreach ($creditos_a_mover as $c) {
        $updCredito->execute([$destino_id, $c['id'], $origen_id]);
        $creditos_movidos += $updCredito->rowCount();
    }

    log_msg("Filas actualizadas — clientes: $clientes_movidos | creditos: $creditos_movidos");

    if ($clientes_movidos !== count($clientes_a_mover)) {
        throw new RuntimeException("Se esperaban " . count($clientes_a_mover) . " clientes movidos, se movieron $clientes_movidos (concurrencia?).");
    }
    if ($creditos_movidos !== count($creditos_a_mover)) {
        throw new RuntimeException("Se esperaban " . count($creditos_a_mover) . " creditos movidos, se movieron $creditos_movidos (concurrencia?).");
    }

    // ── 7. Verificacion post: cada id efectivamente en destino ──
    foreach (array_column($creditos_a_mover, 'id') as $cid) {
        $v = $pdo->prepare("SELECT cobrador_id FROM ic_creditos WHERE id = ?");
        $v->execute([$cid]);
        if ((int) $v->fetchColumn() !== $destino_id) {
            throw new RuntimeException("Verificacion post fallo en credito_id=$cid: no quedo en destino.");
        }
    }
    log_msg('Verificacion post-UPDATE de creditos: OK (' . count($creditos_a_mover) . ' filas, todas en destino)');

    // ── 8. Grupo de control no debe haber cambiado ──────────
    $ctrlStmt2 = $pdo->prepare("
        SELECT COUNT(*) FROM ic_creditos cr
        JOIN ic_clientes cl ON cl.id = cr.cliente_id
        WHERE cr.cobrador_id = ? AND cl.zona NOT IN ($zona_ph)
    ");
    $ctrlStmt2->execute([$origen_id, ...$zonas]);
    $control_despues = (int) $ctrlStmt2->fetchColumn();
    if ($control_despues !== $control_antes) {
        throw new RuntimeException("Grupo de control cambio de $control_antes a $control_despues — algo toco creditos fuera de alcance.");
    }
    log_msg("Grupo de control post-UPDATE: $control_despues (sin cambios, OK)");

    if (!$commit) {
        log_msg("[DRY-RUN] Todas las verificaciones OK. Se aplicarian: $clientes_movidos cliente(s), $creditos_movidos credito(s). ROLLBACK (nada se guarda).");
        $pdo->rollBack();
    } else {
        registrar_log($pdo, $usuario_id, 'MIGRACION_COBRADOR', 'sistema', 0,
            "Migracion parcial por zona [" . implode(', ', $zonas) . "]: "
            . "$clientes_movidos cliente(s) y $creditos_movidos credito(s) (todos los estados) "
            . "del cobrador #{$origen_id} ({$origen['apellido']}, {$origen['nombre']}) "
            . "al #{$destino_id} ({$destino['apellido']}, {$destino['nombre']}). Backup: " . basename($jsonPath));

        $pdo->commit();
        log_msg("=== COMMIT OK. $clientes_movidos cliente(s), $creditos_movidos credito(s). ===");
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_msg('ERROR: ' . $e->getMessage() . ' — ROLLBACK total. Ningun cambio se guardo.');
    exit(1);
}

log_msg('Fin.');
exit(0);
