<?php
// ============================================================
// scripts/fix_fecha_pago_credito_1054.php
// ------------------------------------------------------------
// Corrección de UNA sola vez: 2 pagos del Crédito #1054 (cuotas #9 y #10)
// se cargaron hoy (2026-09-08) vía "Registrar pago directo"
// (creditos/pagar_cuota.php) pero quedaron con fecha_jornada='2026-07-06'
// — casi 2 meses antes de la fecha real de carga, dentro de la misma
// sesión donde el pago inmediatamente anterior (13 segundos antes) usó
// fecha_jornada='2026-08-27'. Confirmado con el usuario (dueño de la
// carga): fue un error, la fecha correcta es la de hoy.
//
// Corrige, para exactamente estos 5 registros (ids ya verificados
// contra la base real antes de escribir este script):
//   - ic_pagos_temporales.id IN (18334, 18335)      -> fecha_jornada
//   - ic_pagos_confirmados.id IN (17774, 17775)     -> fecha_pago, fecha_jornada, semana_lunes
//   - ic_cuotas.id = 19921 (cuota #9, quedó PAGADA) -> fecha_pago
// La cuota #10 (id 19922) sigue PARCIAL, su fecha_pago ya es NULL — no
// se toca. No se modifica ningún monto — solo la fecha atribuida.
//
// ── Uso ──────────────────────────────────────────────────────
//   php scripts\fix_fecha_pago_credito_1054.php                       (dry-run, default)
//   php scripts\fix_fecha_pago_credito_1054.php --commit --usuario-id=3
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
$usuario_id_arg = arg_valor($all_args, '--usuario-id');

$CREDITO_ID    = 1054;
$FECHA_CORRECTA = '2026-09-08';
$PT_IDS  = [18334, 18335];
$PC_IDS  = [17774, 17775];
$CUOTA_ID_PAGADA = 19921; // cuota #9

$pdo = obtener_conexion();

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
    if (!$u || !(int) $u['activo'] || !in_array($u['rol'], ['admin', 'supervisor'], true)) {
        log_msg('ERROR: --usuario-id no corresponde a un admin/supervisor activo.');
        exit(1);
    }
}

log_msg($commit ? 'MODO COMMIT — se va a escribir en la base.' : 'MODO DRY-RUN — no se escribe nada (usar --commit para aplicar).');

// ── Estado actual, verificado contra lo ya confirmado en la investigación ──
$pt_stmt = $pdo->prepare("SELECT * FROM ic_pagos_temporales WHERE id IN (" . implode(',', $PT_IDS) . ")");
$pt_stmt->execute();
$pt_rows = $pt_stmt->fetchAll();

$pc_stmt = $pdo->prepare("SELECT * FROM ic_pagos_confirmados WHERE id IN (" . implode(',', $PC_IDS) . ")");
$pc_stmt->execute();
$pc_rows = $pc_stmt->fetchAll();

$cu_stmt = $pdo->prepare("SELECT * FROM ic_cuotas WHERE id = ?");
$cu_stmt->execute([$CUOTA_ID_PAGADA]);
$cuota_row = $cu_stmt->fetch();

if (count($pt_rows) !== count($PT_IDS) || count($pc_rows) !== count($PC_IDS) || !$cuota_row) {
    log_msg('ERROR: no se encontraron los 5 registros esperados. Abortando sin tocar nada.');
    exit(1);
}

foreach ($pt_rows as $r) {
    if ($r['fecha_jornada'] !== '2026-07-06') {
        log_msg("ERROR: ic_pagos_temporales.id={$r['id']} ya no tiene fecha_jornada='2026-07-06' (tiene '{$r['fecha_jornada']}'). Algo cambió desde la investigación — abortando.");
        exit(1);
    }
}
foreach ($pc_rows as $r) {
    // fecha_pago es DATETIME (viene como '2026-07-06 00:00:00'), solo se compara la parte de fecha
    if ($r['fecha_jornada'] !== '2026-07-06' || substr((string) $r['fecha_pago'], 0, 10) !== '2026-07-06') {
        log_msg("ERROR: ic_pagos_confirmados.id={$r['id']} ya no tiene fecha_jornada/fecha_pago='2026-07-06'. Algo cambió desde la investigación — abortando.");
        exit(1);
    }
}
if ($cuota_row['fecha_pago'] !== '2026-07-06') {
    log_msg("ERROR: ic_cuotas.id={$CUOTA_ID_PAGADA} ya no tiene fecha_pago='2026-07-06' (tiene '{$cuota_row['fecha_pago']}'). Abortando.");
    exit(1);
}

log_msg('Verificación previa OK — los 5 registros tienen exactamente los valores esperados (fecha_jornada/fecha_pago = 2026-07-06).');

$semana_lunes_correcta = calcular_semana_lunes($FECHA_CORRECTA);
log_msg("Fecha correcta a aplicar: $FECHA_CORRECTA (semana_lunes=$semana_lunes_correcta)");

// ── Backup (siempre, incluso en dry-run) ──────────────────────
$backupDir = dirname(realpath(BASE_DIR), 2) . '/backups_creditos';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$ts = date('Ymd_His');
$jsonPath = "$backupDir/fix_fecha_1054_$ts.json";
$sqlPath  = "$backupDir/rollback_fix_fecha_1054_$ts.sql";

file_put_contents($jsonPath, json_encode([
    'fecha_generado' => date('Y-m-d H:i:s'),
    'credito_id'     => $CREDITO_ID,
    'ic_pagos_temporales'  => $pt_rows,
    'ic_pagos_confirmados' => $pc_rows,
    'ic_cuotas'            => [$cuota_row],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$rollback_sql  = "-- Rollback fix_fecha_pago_credito_1054.php ($ts)\n";
$rollback_sql .= "UPDATE ic_pagos_temporales SET fecha_jornada='2026-07-06' WHERE id IN (" . implode(',', $PT_IDS) . ");\n";
$rollback_sql .= "UPDATE ic_pagos_confirmados SET fecha_pago='2026-07-06', fecha_jornada='2026-07-06', semana_lunes='" . $pc_rows[0]['semana_lunes'] . "' WHERE id IN (" . implode(',', $PC_IDS) . ");\n";
$rollback_sql .= "UPDATE ic_cuotas SET fecha_pago='2026-07-06' WHERE id={$CUOTA_ID_PAGADA};\n";
file_put_contents($sqlPath, $rollback_sql);

log_msg("Backup escrito: $jsonPath");
log_msg("Rollback SQL escrito: $sqlPath");

if (!$commit) {
    log_msg('DRY-RUN: no se escribió nada en la base. Diff que se aplicaría:');
    foreach ($PT_IDS as $id) log_msg("  ic_pagos_temporales.id=$id: fecha_jornada '2026-07-06' -> '$FECHA_CORRECTA'");
    foreach ($PC_IDS as $id) log_msg("  ic_pagos_confirmados.id=$id: fecha_pago/fecha_jornada '2026-07-06' -> '$FECHA_CORRECTA', semana_lunes -> '$semana_lunes_correcta'");
    log_msg("  ic_cuotas.id=$CUOTA_ID_PAGADA: fecha_pago '2026-07-06' -> '$FECHA_CORRECTA'");
    log_msg('Correr de nuevo con --commit --usuario-id=<id> para aplicar.');
    exit(0);
}

// ── Conteos de control (deben quedar iguales — solo cambian valores, no filas) ──
$cnt_before_cuotas = (int) $pdo->query("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=$CREDITO_ID")->fetchColumn();
$cnt_before_pc      = (int) $pdo->query("
    SELECT COUNT(*) FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu ON cu.id = pc.cuota_id
    WHERE cu.credito_id=$CREDITO_ID
")->fetchColumn();

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE ic_pagos_temporales SET fecha_jornada = ? WHERE id IN (" . implode(',', $PT_IDS) . ")")
        ->execute([$FECHA_CORRECTA]);

    $pdo->prepare("UPDATE ic_pagos_confirmados SET fecha_pago = ?, fecha_jornada = ?, semana_lunes = ? WHERE id IN (" . implode(',', $PC_IDS) . ")")
        ->execute([$FECHA_CORRECTA, $FECHA_CORRECTA, $semana_lunes_correcta]);

    $pdo->prepare("UPDATE ic_cuotas SET fecha_pago = ? WHERE id = ?")
        ->execute([$FECHA_CORRECTA, $CUOTA_ID_PAGADA]);

    // Verificación post-escritura
    $pt_check = $pdo->query("SELECT id, fecha_jornada FROM ic_pagos_temporales WHERE id IN (" . implode(',', $PT_IDS) . ")")->fetchAll();
    foreach ($pt_check as $r) {
        if ($r['fecha_jornada'] !== $FECHA_CORRECTA) {
            throw new Exception("Verificación falló: ic_pagos_temporales.id={$r['id']} quedó en '{$r['fecha_jornada']}'");
        }
    }
    $pc_check = $pdo->query("SELECT id, fecha_pago, fecha_jornada, semana_lunes FROM ic_pagos_confirmados WHERE id IN (" . implode(',', $PC_IDS) . ")")->fetchAll();
    foreach ($pc_check as $r) {
        if (substr((string) $r['fecha_pago'], 0, 10) !== $FECHA_CORRECTA || $r['fecha_jornada'] !== $FECHA_CORRECTA || $r['semana_lunes'] !== $semana_lunes_correcta) {
            throw new Exception("Verificación falló: ic_pagos_confirmados.id={$r['id']} no quedó con los valores esperados");
        }
    }
    $cuota_check = $pdo->query("SELECT fecha_pago FROM ic_cuotas WHERE id=$CUOTA_ID_PAGADA")->fetch();
    if ($cuota_check['fecha_pago'] !== $FECHA_CORRECTA) {
        throw new Exception("Verificación falló: ic_cuotas.id=$CUOTA_ID_PAGADA no quedó con fecha_pago correcta");
    }

    $cnt_after_cuotas = (int) $pdo->query("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=$CREDITO_ID")->fetchColumn();
    $cnt_after_pc      = (int) $pdo->query("
        SELECT COUNT(*) FROM ic_pagos_confirmados pc
        JOIN ic_cuotas cu ON cu.id = pc.cuota_id
        WHERE cu.credito_id=$CREDITO_ID
    ")->fetchColumn();
    if ($cnt_after_cuotas !== $cnt_before_cuotas || $cnt_after_pc !== $cnt_before_pc) {
        throw new Exception("Verificación falló: cambió la cantidad de filas del crédito (cuotas $cnt_before_cuotas->$cnt_after_cuotas, confirmados $cnt_before_pc->$cnt_after_pc) — no debería haber cambiado ninguna.");
    }

    // detalle debe entrar en varchar(255) — se prueba con strlen() antes de insertar
    registrar_log(
        $pdo,
        $usuario_id,
        'FIX_FECHA_JORNADA_MANUAL',
        'credito',
        $CREDITO_ID,
        "Corregida fecha de 2026-07-06 a $FECHA_CORRECTA en pt#" . implode(',', $PT_IDS)
            . " pc#" . implode(',', $PC_IDS) . " y cuota#9($CUOTA_ID_PAGADA) - error de carga confirmado por el usuario"
    );

    $pdo->commit();
    log_msg('COMMIT OK. Los 5 registros quedaron con fecha ' . $FECHA_CORRECTA . '.');
} catch (Throwable $e) {
    $pdo->rollBack();
    log_msg('ERROR, se hizo rollback: ' . $e->getMessage());
    exit(1);
}
