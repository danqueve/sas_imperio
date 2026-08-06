<?php
// ============================================================
// cron/snapshot_metas_semanales.php
// Script para ejecutar semanalmente vía Cron Job, lunes a la madrugada.
// Guarda en ic_historial_metas un snapshot de las 4 métricas de meta
// semanal (Meta Automática, Cobrado Real, Meta Fija Semanal, Cobrado
// Semanal Puro) de la semana que acaba de cerrar, por cada cobrador activo.
//
// ── Cron Job en Alma Linux ───────────────────────────────────
//   crontab -e
//   10 0 * * 1 /usr/bin/php /ruta/al/proyecto/cron/snapshot_metas_semanales.php >> /ruta/al/proyecto/logs/snapshot_metas.log 2>&1
//
// ── Modo prueba ─────────────────────────────────────────────
//   php cron/snapshot_metas_semanales.php --dry-run
//   (muestra los 4 valores por cobrador sin guardar nada)
//
// ── Semana puntual (por defecto: la semana pasada) ───────────
//   php cron/snapshot_metas_semanales.php --semana=2026-07-15
//   (cualquier fecha de la semana deseada; se normaliza al lunes)
// ============================================================

declare(strict_types=1);

define('BASE_DIR', __DIR__ . '/..');

require_once BASE_DIR . '/config/conexion.php';
require_once BASE_DIR . '/config/funciones.php';

// ── Argumentos CLI ───────────────────────────────────────────
// Compatible con PHP CLI y PHP CGI (argv puede no estar disponible en CGI)
$all_args = array_merge($argv ?? [], $_SERVER['argv'] ?? []);
$dry_run  = in_array('--dry-run', $all_args, true);

$semana_arg = null;
foreach ($all_args as $arg) {
    if (str_starts_with($arg, '--semana=')) {
        $semana_arg = substr($arg, 9);
    }
}

// ── Log de consola ───────────────────────────────────────────
function log_cron(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

log_cron('Iniciando snapshot de metas semanales' . ($dry_run ? ' [DRY-RUN]' : ''));

// ── Conexión DB ──────────────────────────────────────────────
$pdo = obtener_conexion();

// ── Semana a registrar (por defecto: la semana pasada) ───────
$semana_referencia = new DateTimeImmutable($semana_arg ?? '-7 days');
$dow          = (int) $semana_referencia->format('N');
$lunes_semana = $semana_referencia->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');

log_cron("Semana a registrar (lunes): $lunes_semana");

// ── Cobradores activos ────────────────────────────────────────
$cobradores = $pdo->query("
    SELECT id, nombre, apellido
    FROM ic_usuarios
    WHERE rol = 'cobrador' AND activo = 1
    ORDER BY apellido, nombre
")->fetchAll();

log_cron('Cobradores activos: ' . count($cobradores));

$registrados = 0;
foreach ($cobradores as $cob) {
    $cid    = (int) $cob['id'];
    $nombre = $cob['apellido'] . ', ' . $cob['nombre'];

    $s = calcular_snapshot_metas_semana($pdo, $cid, $semana_referencia);
    $resumen = "Meta Auto: {$s['meta_automatica']} | Cobrado Real: {$s['cobrado_real']} | "
        . "Meta Fija: {$s['meta_fija_semanal']} | Cobrado Puro: {$s['cobrado_semanal_puro']}";

    if ($dry_run) {
        log_cron("  [DRY-RUN] $nombre (id=$cid) — $resumen");
    } else {
        registrar_snapshot_metas_semana($pdo, $cid, $semana_referencia);
        log_cron("  Snapshot guardado: $nombre (id=$cid) — $resumen");
        $registrados++;
    }
}

if (!$dry_run) {
    log_cron("Snapshots registrados: $registrados");
}

log_cron('Fin.');
exit(0);
