<?php
// ============================================================
// cron/recordatorio_mora.php
// Script para ejecutar diariamente a las 9:00 AM vía Cron Job.
// Envía recordatorio de mora a clientes con exactamente
// 2 días calendario de atraso en su cuota.
//
// ── Cron Job en Alma Linux ───────────────────────────────────
//   crontab -e
//   0 9 * * * /usr/bin/php /ruta/al/proyecto/cron/recordatorio_mora.php >> /ruta/al/proyecto/logs/cron.log 2>&1
//
// ── Template requerido en Meta Business Manager ─────────────
//   Nombre: recordatorio_mora
//   Categoría: Utility
//   Idioma: Español (es)
//   Cuerpo del mensaje:
//     "Hola {{1}}, le recordamos que su cuota #{{2}} de {{3}} venció hace 2 días.
//      El monto pendiente (incluye mora) es de {{4}}.
//      Comuníquese con su cobrador para regularizar su situación. ¡Gracias!"
//   Parámetros: 1=nombres, 2=numero_cuota, 3=articulo, 4=monto_total
//
// ── Modo prueba ─────────────────────────────────────────────
//   php cron/recordatorio_mora.php --dry-run
//   (muestra a quién se enviaría, sin enviar realmente)
// ============================================================

declare(strict_types=1);

define('BASE_DIR', __DIR__ . '/..');

require_once BASE_DIR . '/config/conexion.php';
require_once BASE_DIR . '/config/funciones.php';
require_once BASE_DIR . '/config/whatsapp.php';
require_once BASE_DIR . '/services/WhatsAppService.php';

// ── Modo dry-run ─────────────────────────────────────────────
$dry_run = in_array('--dry-run', $argv ?? [], true);

// ── Log de consola ───────────────────────────────────────────
function log_cron(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

log_cron('Iniciando recordatorio de mora' . ($dry_run ? ' [DRY-RUN]' : ''));

// ── Conexión DB ──────────────────────────────────────────────
$pdo = obtener_conexion();

// ── Consulta: cuotas con exactamente 2 días de atraso ───────
// DATEDIFF(CURDATE(), fecha_vencimiento) = 2 → vencieron hace 2 días calendario
$stmt = $pdo->prepare("
    SELECT
        cl.telefono,
        cl.nombres,
        cl.apellidos,
        cu.numero_cuota,
        cu.monto_cuota,
        cu.monto_mora,
        cu.fecha_vencimiento,
        cr.interes_moratorio_pct,
        COALESCE(cr.articulo_desc, art.descripcion, 'artículo') AS articulo
    FROM ic_cuotas cu
    JOIN ic_creditos cr  ON cu.credito_id  = cr.id
    JOIN ic_clientes cl  ON cr.cliente_id  = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE cu.estado IN ('VENCIDA', 'PENDIENTE')
      AND DATEDIFF(CURDATE(), cu.fecha_vencimiento) = 2
      AND cr.estado = 'EN_CURSO'
      AND cl.telefono IS NOT NULL
      AND cl.telefono != ''
    ORDER BY cl.apellidos ASC
");
$stmt->execute();
$cuotas = $stmt->fetchAll();

log_cron('Cuotas encontradas con 2 días de atraso: ' . count($cuotas));

if (empty($cuotas)) {
    log_cron('Sin cuotas para notificar. Fin.');
    exit(0);
}

// ── Enviar mensajes ──────────────────────────────────────────
$wa       = new WhatsAppService();
$enviados = 0;
$errores  = 0;

foreach ($cuotas as $c) {
    $nombre      = trim($c['nombres'] . ' ' . $c['apellidos']);
    $num_cuota   = (string) $c['numero_cuota'];
    $articulo    = $c['articulo'];

    // Calcular mora si aún no está congelada
    $mora = (float) $c['monto_mora'] > 0
        ? (float) $c['monto_mora']
        : calcular_mora(
            (float) $c['monto_cuota'],
            dias_atraso_habiles($c['fecha_vencimiento']),
            (float) $c['interes_moratorio_pct']
        );

    $total = formato_pesos((float) $c['monto_cuota'] + $mora);

    if ($dry_run) {
        log_cron("  [DRY-RUN] {$nombre} ({$c['telefono']}) — Cuota #{$num_cuota} de {$articulo} — Total: {$total}");
        $enviados++;
        continue;
    }

    $ok = $wa->enviarTemplate(
        $c['telefono'],
        WA_TPL_RECORDATORIO,
        WA_TPL_LANG,
        [$nombre, $num_cuota, $articulo, $total]
    );

    if ($ok) {
        log_cron("  OK  {$nombre} ({$c['telefono']}) — Cuota #{$num_cuota}");
        $enviados++;
    } else {
        log_cron("  ERR {$nombre} ({$c['telefono']}) — Cuota #{$num_cuota} — Ver logs/whatsapp.log");
        $errores++;
    }

    // Pequeña pausa entre envíos (evitar rate limiting de Meta: ~80 msg/seg)
    usleep(200_000); // 0.2 segundos
}

log_cron("Fin. Enviados: {$enviados} | Errores: {$errores}");
exit($errores > 0 ? 1 : 0);
