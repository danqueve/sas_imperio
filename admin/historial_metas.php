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
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol = 'cobrador' ORDER BY apellido ASC, nombre ASC"
)->fetchAll();

if ($cobrador_id > 0) {
    $stmt = $pdo->prepare("
        SELECT semana_lunes, meta_automatica, cobrado_real, meta_fija_semanal, cobrado_semanal_puro
        FROM ic_historial_metas
        WHERE cobrador_id = ? AND semana_lunes BETWEEN ? AND ?
        ORDER BY semana_lunes ASC
    ");
    $stmt->execute([$cobrador_id, $desde, $hasta]);
} else {
    $stmt = $pdo->prepare("
        SELECT semana_lunes,
               SUM(meta_automatica)      AS meta_automatica,
               SUM(cobrado_real)         AS cobrado_real,
               SUM(meta_fija_semanal)    AS meta_fija_semanal,
               SUM(cobrado_semanal_puro) AS cobrado_semanal_puro
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
$tot_meta_auto    = 0.0;
$tot_cobrado_real = 0.0;
$tot_meta_fija    = 0.0;
$tot_cobrado_puro = 0.0;
foreach ($filas as $f) {
    $tot_meta_auto    += (float) $f['meta_automatica'];
    $tot_cobrado_real += (float) $f['cobrado_real'];
    $tot_meta_fija    += (float) $f['meta_fija_semanal'];
    $tot_cobrado_puro += (float) $f['cobrado_semanal_puro'];
}
$tot_pct_real = $tot_meta_auto > 0 ? min(100, round($tot_cobrado_real / $tot_meta_auto * 100)) : 0;
$tot_pct_puro = $tot_meta_fija > 0 ? min(100, round($tot_cobrado_puro / $tot_meta_fija * 100)) : 0;

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
                    <th class="text-right">Cobrado Real</th>
                    <th style="min-width:110px">%</th>
                    <th class="text-right">Meta Fija Semanal</th>
                    <th class="text-right">Cobrado (sin mora)</th>
                    <th style="min-width:110px">%</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $f):
                $meta_auto  = (float) $f['meta_automatica'];
                $cob_real   = (float) $f['cobrado_real'];
                $meta_fija  = (float) $f['meta_fija_semanal'];
                $cob_puro   = (float) $f['cobrado_semanal_puro'];
                $pct_real   = $meta_auto > 0 ? min(100, round($cob_real / $meta_auto * 100)) : 0;
                $pct_puro   = $meta_fija > 0 ? min(100, round($cob_puro / $meta_fija * 100)) : 0;
                $color_real = color_pct_meta($pct_real);
                $color_puro = color_pct_meta($pct_puro);
                $lunes_dt   = strtotime($f['semana_lunes']);
            ?>
                <tr>
                    <td>
                        <strong><?= date('d/m', $lunes_dt) ?> — <?= date('d/m/Y', strtotime('+5 days', $lunes_dt)) ?></strong>
                    </td>
                    <td class="text-right"><?= formato_pesos($meta_auto) ?></td>
                    <td class="text-right" style="font-weight:700;color:var(--success)"><?= formato_pesos($cob_real) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="flex:1;background:rgba(255,255,255,.1);border-radius:99px;height:7px;overflow:hidden">
                                <div style="width:<?= $pct_real ?>%;height:100%;background:<?= $color_real ?>;border-radius:99px"></div>
                            </div>
                            <span style="font-size:.78rem;font-weight:700;color:<?= $color_real ?>;min-width:36px;text-align:right"><?= $pct_real ?>%</span>
                        </div>
                    </td>
                    <td class="text-right"><?= formato_pesos($meta_fija) ?></td>
                    <td class="text-right" style="font-weight:700;color:var(--success)"><?= formato_pesos($cob_puro) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="flex:1;background:rgba(255,255,255,.1);border-radius:99px;height:7px;overflow:hidden">
                                <div style="width:<?= $pct_puro ?>%;height:100%;background:<?= $color_puro ?>;border-radius:99px"></div>
                            </div>
                            <span style="font-size:.78rem;font-weight:700;color:<?= $color_puro ?>;min-width:36px;text-align:right"><?= $pct_puro ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:800;border-top:2px solid var(--border)">
                    <td>TOTAL PERÍODO</td>
                    <td class="text-right"><?= formato_pesos($tot_meta_auto) ?></td>
                    <td class="text-right" style="color:var(--success)"><?= formato_pesos($tot_cobrado_real) ?></td>
                    <td style="color:<?= color_pct_meta($tot_pct_real) ?>"><?= $tot_pct_real ?>%</td>
                    <td class="text-right"><?= formato_pesos($tot_meta_fija) ?></td>
                    <td class="text-right" style="color:var(--success)"><?= formato_pesos($tot_cobrado_puro) ?></td>
                    <td style="color:<?= color_pct_meta($tot_pct_puro) ?>"><?= $tot_pct_puro ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
