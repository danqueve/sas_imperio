<?php
// ============================================================
// cron/actualizar_estados.php
// Script para ejecutar diariamente a las 00:05 AM vía Cron Job.
// Marca cuotas PENDIENTE → VENCIDA y actualiza créditos a MOROSO.
//
// ── Cron Job en Alma Linux ───────────────────────────────────
//   crontab -e
//   5 0 * * * /usr/bin/php /ruta/al/proyecto/cron/actualizar_estados.php >> /ruta/al/proyecto/logs/estados.log 2>&1
//
// ── Modo prueba ─────────────────────────────────────────────
//   php cron/actualizar_estados.php --dry-run
//   (muestra qué cambiaría sin ejecutar los UPDATE)
// ============================================================

declare(strict_types=1);

define('BASE_DIR', __DIR__ . '/..');

require_once BASE_DIR . '/config/conexion.php';
require_once BASE_DIR . '/config/funciones.php';

// ── Modo dry-run ─────────────────────────────────────────────
// Compatible con PHP CLI y PHP CGI (argv puede no estar disponible en CGI)
$all_args = array_merge($argv ?? [], $_SERVER['argv'] ?? []);
$dry_run  = in_array('--dry-run', $all_args, true);

// ── Log de consola ───────────────────────────────────────────
function log_cron(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

log_cron('Iniciando actualización de estados' . ($dry_run ? ' [DRY-RUN]' : ''));

// ── Conexión DB ──────────────────────────────────────────────
$pdo = obtener_conexion();

// ── PASO A: Identificar cuotas PENDIENTE vencidas ────────────
$cuotas_stmt = $pdo->prepare("
    SELECT cu.id, cu.credito_id, cu.numero_cuota, cu.fecha_vencimiento,
           cl.apellidos, cl.nombres
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE cu.estado = 'PENDIENTE'
      AND cu.fecha_vencimiento < CURDATE()
      AND (cu.saldo_pagado IS NULL OR cu.saldo_pagado <= 0)
      AND cr.estado NOT IN ('FINALIZADO', 'CANCELADO')
    ORDER BY cu.fecha_vencimiento ASC
");
$cuotas_stmt->execute();
$cuotas_vencidas = $cuotas_stmt->fetchAll();

log_cron('Cuotas PENDIENTE con fecha vencida: ' . count($cuotas_vencidas));

if ($dry_run) {
    foreach ($cuotas_vencidas as $cu) {
        log_cron("  [DRY-RUN] Cuota #{$cu['numero_cuota']} (id={$cu['id']}) — {$cu['apellidos']}, {$cu['nombres']} — Vcto: {$cu['fecha_vencimiento']} → VENCIDA");
    }
} else {
    $updated = $pdo->exec("
        UPDATE ic_cuotas
        SET estado = 'VENCIDA'
        WHERE estado = 'PENDIENTE'
          AND fecha_vencimiento < CURDATE()
          AND (saldo_pagado IS NULL OR saldo_pagado <= 0)
    ");
    log_cron("Cuotas marcadas VENCIDA: $updated");
}

// ── PASO B: Identificar créditos activos con cuotas VENCIDA ──
$afectados_stmt = $pdo->query("
    SELECT DISTINCT cu.credito_id,
           CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
           COUNT(cu.id) AS cant_vencidas
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE (
        cu.estado = 'VENCIDA'
        OR (cu.estado = 'PARCIAL' AND cu.fecha_vencimiento < CURDATE())
    )
      AND cr.estado NOT IN ('FINALIZADO', 'CANCELADO', 'MOROSO')
    GROUP BY cu.credito_id
");
$afectados = $afectados_stmt->fetchAll();

log_cron('Créditos activos con cuotas VENCIDA (aún no MOROSO): ' . count($afectados));

if ($dry_run) {
    foreach ($afectados as $af) {
        log_cron("  [DRY-RUN] Crédito id={$af['credito_id']} — {$af['cliente']} — {$af['cant_vencidas']} cuota(s) vencida(s) → MOROSO");
    }
} else {
    $upd_cred = $pdo->prepare("
        UPDATE ic_creditos
        SET estado = 'MOROSO'
        WHERE id = ?
          AND estado NOT IN ('FINALIZADO', 'CANCELADO')
    ");
    $actualizados = 0;
    foreach ($afectados as $af) {
        $upd_cred->execute([$af['credito_id']]);
        $actualizados++;
    }
    log_cron("Créditos actualizados a MOROSO: $actualizados");
}

log_cron('Fin.');
exit(0);
