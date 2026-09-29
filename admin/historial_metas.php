<?php
// ============================================================
// admin/historial_metas.php — Balance mensual de Metas
// Lee los snapshots semanales de ic_historial_metas (generados por
// cron/snapshot_metas_semanales.php o el botón manual de admin/metas.php)
// y arma un balance por período: Meta Automática vs Cobrado Real, y
// Meta Fija Semanal vs Cobrado (sin mora).
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_estadisticas');

$pdo = obtener_conexion();

function color_pct_meta(int $pct): string
{
    return $pct >= 100 ? '#d4a017' : ($pct >= 70 ? 'var(--success)' : ($pct >= 40 ? '#f97316' : 'var(--danger)'));
}

// ── Filtros ────────────────────────────────────────────────
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$desde       = trim($_GET['desde'] ?? date('Y-m-01'));
$hasta       = trim($_GET['hasta'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta))  $hasta = date('Y-m-d');
if ($desde > $hasta) $desde = $hasta;

$todos_cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol = 'cobrador' AND activo = 1 ORDER BY apellido ASC, nombre ASC"
)->fetchAll();

// meta_objetivo/origen_meta solo existen para snapshots tomados DESPUES de
// esta migración — para filas viejas (meta_objetivo NULL) se muestra
// meta_automatica como objetivo con la etiqueta "criterio anterior", sin
// inferir si esa semana tuvo o no un override (no se sabe).
if ($cobrador_id > 0) {
    $stmt = $pdo->prepare("
        SELECT semana_lunes, meta_automatica, meta_objetivo, origen_meta,
               cobrado_real, meta_fija_semanal, cobrado_efectivo, cobrado_transferencia
        FROM ic_historial_metas
        WHERE cobrador_id = ? AND semana_lunes BETWEEN ? AND ?
        ORDER BY semana_lunes ASC
    ");
    $stmt->execute([$cobrador_id, $desde, $hasta]);
} else {
    $stmt = $pdo->prepare("
        SELECT semana_lunes,
               SUM(meta_automatica)                          AS meta_automatica,
               SUM(COALESCE(meta_objetivo, meta_automatica)) AS meta_objetivo,
               SUM(meta_objetivo IS NULL)                    AS cant_criterio_anterior,
               SUM(cobrado_real)          AS cobrado_real,
               SUM(meta_fija_semanal)     AS meta_fija_semanal,
               SUM(cobrado_efectivo)      AS cobrado_efectivo,
               SUM(cobrado_transferencia) AS cobrado_transferencia
        FROM ic_historial_metas
        WHERE semana_lunes BETWEEN ? AND ?
        GROUP BY semana_lunes
        ORDER BY semana_lunes ASC
    ");
    $stmt->execute([$desde, $hasta]);
}
$filas = $stmt->fetchAll();

$label_cobrador = 'Todos los cobradores (suma semanal)';
foreach ($todos_cobradores as $tc) {
    if ((int) $tc['id'] === $cobrador_id) {
        $label_cobrador = $tc['apellido'] . ', ' . $tc['nombre'];
        break;
    }
}

// ── Totales del período ──────────────────────────────────────
$tot_meta_auto      = 0.0;
$tot_meta_objetivo  = 0.0;
$tot_cobrado_real   = 0.0;
$tot_meta_fija      = 0.0;
$tot_efectivo       = 0.0;
$tot_transferencia  = 0.0;
$tot_criterio_anterior = 0;
foreach ($filas as $f) {
    // Modo por-cobrador: meta_objetivo puede venir NULL de la fila cruda
    // (fila vieja, anterior a la migración) — cae a meta_automatica. Modo
    // "todos" ya viene resuelto por el COALESCE de la query.
    $meta_objetivo_fila = $f['meta_objetivo'] !== null ? (float) $f['meta_objetivo'] : (float) $f['meta_automatica'];
    $tot_meta_auto     += (float) $f['meta_automatica'];
    $tot_meta_objetivo += $meta_objetivo_fila;
    $tot_cobrado_real  += (float) $f['cobrado_real'];
    $tot_meta_fija     += (float) $f['meta_fija_semanal'];
    $tot_efectivo      += (float) $f['cobrado_efectivo'];
    $tot_transferencia += (float) $f['cobrado_transferencia'];
    // Cuenta SEMANAS (filas), no cobradores — en modo "todos" una semana
    // con 1 o más cobradores en criterio anterior sigue siendo 1 semana
    // marcada, no se suma una vez por cada cobrador de esa semana.
    $fila_es_criterio_anterior = ($cobrador_id > 0)
        ? ($f['meta_objetivo'] === null)
        : ((int) $f['cant_criterio_anterior'] > 0);
    if ($fila_es_criterio_anterior) $tot_criterio_anterior++;
}
$tot_pct_objetivo = $tot_meta_objetivo > 0 ? min(100, round($tot_cobrado_real / $tot_meta_objetivo * 100)) : 0;

$qs_pdf = http_build_query(['cobrador_id' => $cobrador_id, 'desde' => $desde, 'hasta' => $hasta]);

$page_title     = 'Historial de Metas';
$page_current   = 'historial_metas';
$topbar_actions = '<a href="metas" class="btn-ic btn-ghost btn-sm"><i class="fa fa-bullseye"></i> Metas Semanales</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="card-ic mb-4">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <select name="cobrador_id">
            <option value="0" <?= $cobrador_id === 0 ? 'selected' : '' ?>>Todos los cobradores (suma semanal)</option>
            <?php foreach ($todos_cobradores as $tc): ?>
                <option value="<?= $tc['id'] ?>" <?= $cobrador_id === (int) $tc['id'] ? 'selected' : '' ?>>
                    <?= e($tc['apellido'] . ', ' . $tc['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="desde" value="<?= e($desde) ?>" max="<?= date('Y-m-d') ?>">
        <span style="color:var(--text-muted)">—</span>
        <input type="date" name="hasta" value="<?= e($hasta) ?>" max="<?= date('Y-m-d') ?>">
        <button type="submit" class="btn-ic btn-primary btn-sm"><i class="fa fa-search"></i> Filtrar</button>
        <a href="historial_metas_pdf?<?= $qs_pdf ?>" class="btn-ic btn-ghost btn-sm" target="_blank">
            <i class="fa fa-file-pdf"></i> Exportar PDF
        </a>
    </form>
    <div style="font-size:.8rem;color:var(--text-muted);margin-top:10px">
        <i class="fa fa-info-circle"></i>
        Los snapshots se registran automáticamente cada lunes (cron) o manualmente desde
        <a href="metas" style="color:var(--primary)">Metas Semanales</a>. Si falta una semana, es porque no se generó su snapshot.
        <?php if ($tot_criterio_anterior > 0): ?>
            <br><i class="fa fa-clock-rotate-left"></i>
            <?= $tot_criterio_anterior ?> semana(s) de este período son de antes de que se empezara a guardar el objetivo efectivo (override manual) —
            para esas, "Meta Objetivo" muestra la Meta Automática como referencia, marcada <em>criterio anterior</em>.
        <?php endif; ?>
    </div>
</div>

<?php if (empty($filas)): ?>
    <div class="card-ic"><p class="text-muted text-center" style="padding:30px">No hay snapshots registrados en este período.</p></div>
<?php else: ?>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-chart-line"></i> Balance — <?= e($label_cobrador) ?></span>
        <span style="font-size:.78rem;color:var(--text-muted)">
            <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Semana</th>
                    <th class="text-right">Meta Automática</th>
                    <th class="text-right">Meta Objetivo</th>
                    <th class="text-right">Cobrado Real</th>
                    <th style="min-width:110px">%</th>
                    <th class="text-right">Meta Fija Semanal</th>
                    <th class="text-right">Efectivo</th>
                    <th class="text-right">Transferencia</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $f):
                $meta_auto     = (float) $f['meta_automatica'];
                $es_criterio_anterior = ($cobrador_id > 0)
                    ? ($f['meta_objetivo'] === null)
                    : ((int) $f['cant_criterio_anterior'] > 0);
                $meta_objetivo = $f['meta_objetivo'] !== null ? (float) $f['meta_objetivo'] : $meta_auto;
                $cob_real      = (float) $f['cobrado_real'];
                $meta_fija     = (float) $f['meta_fija_semanal'];
                $efectivo      = (float) $f['cobrado_efectivo'];
                $transferencia = (float) $f['cobrado_transferencia'];
                $pct_obj       = $meta_objetivo > 0 ? min(100, round($cob_real / $meta_objetivo * 100)) : 0;
                $color_obj     = color_pct_meta($pct_obj);
                $lunes_dt      = strtotime($f['semana_lunes']);
            ?>
                <tr>
                    <td>
                        <strong><?= date('d/m', $lunes_dt) ?> — <?= date('d/m/Y', strtotime('+5 days', $lunes_dt)) ?></strong>
                    </td>
                    <td class="text-right"><?= formato_pesos($meta_auto) ?></td>
                    <td class="text-right">
                        <span style="font-weight:700"><?= formato_pesos($meta_objetivo) ?></span>
                        <?php if ($es_criterio_anterior): ?>
                            <div class="text-muted" style="font-size:.68rem">criterio anterior</div>
                        <?php elseif ($cobrador_id > 0): ?>
                            <div class="text-muted" style="font-size:.68rem"><?= $f['origen_meta'] === 'MANUAL' ? 'Manual' : 'Automática' ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-right" style="font-weight:700;color:var(--success)"><?= formato_pesos($cob_real) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="flex:1;background:rgba(255,255,255,.1);border-radius:99px;height:7px;overflow:hidden">
                                <div style="width:<?= $pct_obj ?>%;height:100%;background:<?= $color_obj ?>;border-radius:99px"></div>
                            </div>
                            <span style="font-size:.78rem;font-weight:700;color:<?= $color_obj ?>;min-width:36px;text-align:right"><?= $pct_obj ?>%</span>
                        </div>
                    </td>
                    <td class="text-right"><?= formato_pesos($meta_fija) ?></td>
                    <td class="text-right"><?= formato_pesos($efectivo) ?></td>
                    <td class="text-right"><?= formato_pesos($transferencia) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:800;border-top:2px solid var(--border)">
                    <td>TOTAL PERÍODO</td>
                    <td class="text-right"><?= formato_pesos($tot_meta_auto) ?></td>
                    <td class="text-right"><?= formato_pesos($tot_meta_objetivo) ?></td>
                    <td class="text-right" style="color:var(--success)"><?= formato_pesos($tot_cobrado_real) ?></td>
                    <td style="color:<?= color_pct_meta($tot_pct_objetivo) ?>"><?= $tot_pct_objetivo ?>%</td>
                    <td class="text-right"><?= formato_pesos($tot_meta_fija) ?></td>
                    <td class="text-right"><?= formato_pesos($tot_efectivo) ?></td>
                    <td class="text-right"><?= formato_pesos($tot_transferencia) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
