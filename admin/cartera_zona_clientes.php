<?php
// ============================================================
// admin/cartera_zona_clientes.php — Detalle por crédito de una zona
// puntual de admin/cartera_zona.php (drill-down al hacer click en
// una tarjeta de zona). Mismo cobrador/periodo/cohorte que la
// pantalla madre, un registro por crédito.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$hoy            = date('Y-m-d');
$primer_dia_mes = date('Y-m-01');

$cobrador_id    = (int) ($_GET['cobrador_id'] ?? 0);
$zona           = trim($_GET['zona'] ?? '');
$modo_historico = ($_GET['historico'] ?? '') === '1';

if ($modo_historico) {
    $desde = null;
    $hasta = null;
} else {
    $desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
        ? $_GET['desde'] : $primer_dia_mes;
    $hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
        ? $_GET['hasta'] : $hoy;
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
}

if (!$cobrador_id || $zona === '') {
    die('Faltan parámetros (cobrador o zona).');
}

$cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cs->execute([$cobrador_id]);
$cob = $cs->fetch();
if (!$cob) die('Cobrador no encontrado.');
$cob_label = $cob['apellido'] . ', ' . $cob['nombre'];

$creditos = obtener_creditos_cartera_zona($pdo, $cobrador_id, $zona, $desde, $hasta);
if (empty($creditos)) {
    die('Sin créditos para esta combinación de cobrador, zona y período.');
}

$total_clientes   = count(array_unique(array_column($creditos, 'cliente_id')));
$clientes_activos = array_filter($creditos, fn($c) => in_array($c['estado'], ['EN_CURSO', 'MOROSO'], true));
$total_clientes_activos = count(array_unique(array_column($clientes_activos, 'cliente_id')));
$total_otorgado   = array_sum(array_column($creditos, 'monto_otorgado'));
$total_cobrado    = array_sum(array_column($creditos, 'cobrado'));
$total_devolucion = array_sum(array_column($creditos, 'devolucion'));
$total_incobrable = array_sum(array_column($creditos, 'incobrable'));
$total_faltante   = max(0, $total_otorgado - $total_cobrado - $total_devolucion - $total_incobrable);
$total_atraso     = array_sum(array_column($creditos, 'atraso'));
$pct_cobro_total  = $total_otorgado > 0 ? round($total_cobrado / $total_otorgado * 100) : 0;
$pct_atraso_total = $total_otorgado > 0 ? round($total_atraso  / $total_otorgado * 100) : 0;

// ── Query string compartido (para volver, y para CSV/PDF) ────
$qs_base = $modo_historico
    ? http_build_query(['cobrador_id' => $cobrador_id, 'historico' => 1])
    : http_build_query(['cobrador_id' => $cobrador_id, 'desde' => $desde, 'hasta' => $hasta]);
$qs_zona = $qs_base . '&zona=' . urlencode($zona);

// ── Export CSV ─────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $zona);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cartera_zona_clientes_cob' . $cobrador_id . '_' . $slug . '_' . ($desde ?? 'historico') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Cliente', 'DNI', 'Telefono', 'Articulo', 'Fecha Alta', 'Valor Total', 'Cobrado', 'Devolucion', 'Incobrable', 'Faltante', 'Atraso', 'Estado'], ';', '"', '\\');
    foreach ($creditos as $c) {
        fputcsv($out, [
            sanear_csv_formula($c['apellidos'] . ', ' . $c['nombres']),
            $c['dni'] ?: '',
            sanear_csv_formula($c['telefono'] ?: ''),
            sanear_csv_formula($c['articulo']),
            date('d/m/Y', strtotime($c['fecha_alta'])),
            number_format($c['monto_otorgado'], 2, ',', '.'),
            number_format($c['cobrado'], 2, ',', '.'),
            number_format($c['devolucion'], 2, ',', '.'),
            number_format($c['incobrable'], 2, ',', '.'),
            number_format($c['faltante'], 2, ',', '.'),
            number_format($c['atraso'], 2, ',', '.'),
            $c['estado'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$page_title   = 'Cartera por Zona — Detalle';
$page_current = 'cartera_zona';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="margin-bottom:16px">
    <a href="cartera_zona?<?= $qs_base ?>" style="font-size:.82rem;color:var(--text-muted);text-decoration:none">
        <i class="fa fa-arrow-left"></i> Volver a Cartera por Zona
    </a>
</div>

<div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px">
    <div>
        <div style="font-size:1.1rem;font-weight:800"><?= e($cob_label) ?> · Zona: <?= e($zona) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted)">
            <?= count($creditos) ?> crédito<?= count($creditos) != 1 ? 's' : '' ?> ·
            <?= $total_clientes_activos ?> cliente<?= $total_clientes_activos != 1 ? 's' : '' ?> con crédito activo hoy
            (<?= $total_clientes ?> distinto<?= $total_clientes != 1 ? 's' : '' ?> en este listado) ·
            <?php if ($modo_historico): ?>
                Toda la cartera (histórico completo)
            <?php else: ?>
                Créditos otorgados <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
        <a href="?<?= $qs_zona ?>&export=csv" class="btn-ic btn-success btn-sm" title="Exportar a CSV">
            <i class="fa fa-file-csv"></i> CSV
        </a>
        <a href="cartera_zona_clientes_pdf?<?= $qs_zona ?>" target="_blank" class="btn-ic btn-ghost btn-sm" title="Exportar a PDF">
            <i class="fa fa-file-pdf"></i> PDF
        </a>
    </div>
</div>

<!-- Franja de totales — debe coincidir exacto con la tarjeta de zona de la pantalla madre -->
<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;padding:16px 20px;
            background:var(--dark-bg);border-radius:12px;border:1px solid var(--dark-border)">
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">Valor Total</div>
        <div style="font-size:1.1rem;font-weight:800"><?= formato_pesos($total_otorgado) ?></div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">Cobrado</div>
        <div style="font-size:1.1rem;font-weight:800"><?= formato_pesos($total_cobrado) ?></div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">Devolución</div>
        <div style="font-size:1.1rem;font-weight:800;color:<?= $total_devolucion > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>"><?= formato_pesos($total_devolucion) ?></div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">Incobrable</div>
        <div style="font-size:1.1rem;font-weight:800;color:<?= $total_incobrable > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>"><?= formato_pesos($total_incobrable) ?></div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">Faltante</div>
        <div style="font-size:1.1rem;font-weight:800"><?= formato_pesos($total_faltante) ?></div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">Atraso</div>
        <div style="font-size:1.1rem;font-weight:800;color:<?= $total_atraso > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>"><?= formato_pesos($total_atraso) ?></div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">% Cobro</div>
        <div style="font-size:1.1rem;font-weight:800"><?= $pct_cobro_total ?>%</div>
    </div>
    <div>
        <div style="font-size:.7rem;color:var(--text-muted)">% Atraso</div>
        <div style="font-size:1.1rem;font-weight:800"><?= $pct_atraso_total ?>%</div>
    </div>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-users"></i> Créditos de la zona</span>
    </div>
    <div class="table-responsive">
        <table class="table-ic">
            <thead>
                <tr>
                    <th class="text-center">#</th>
                    <th>Cliente</th>
                    <th>Teléfono</th>
                    <th>Artículo</th>
                    <th class="text-center">Fecha Alta</th>
                    <th class="text-right">Valor Total</th>
                    <th class="text-right">Cobrado</th>
                    <th class="text-right">Devolución</th>
                    <th class="text-right">Incobrable</th>
                    <th class="text-right">Faltante</th>
                    <th class="text-right">Atraso</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Crédito</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($creditos as $i => $c): ?>
                <tr>
                    <td class="text-center"><?= $i + 1 ?></td>
                    <td>
                        <a href="../clientes/ver?id=<?= $c['cliente_id'] ?>" target="_blank" style="color:var(--text);text-decoration:none">
                            <?= e($c['apellidos'] . ', ' . $c['nombres']) ?>
                        </a>
                    </td>
                    <td><?= e($c['telefono'] ?: '—') ?></td>
                    <td><?= e(mb_strimwidth($c['articulo'], 0, 40, '…')) ?></td>
                    <td class="text-center"><?= date('d/m/Y', strtotime($c['fecha_alta'])) ?></td>
                    <td class="text-right"><?= formato_pesos($c['monto_otorgado']) ?></td>
                    <td class="text-right"><?= formato_pesos($c['cobrado']) ?></td>
                    <td class="text-right" style="color:<?= $c['devolucion'] > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>"><?= formato_pesos($c['devolucion']) ?></td>
                    <td class="text-right" style="color:<?= $c['incobrable'] > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>"><?= formato_pesos($c['incobrable']) ?></td>
                    <td class="text-right"><?= formato_pesos($c['faltante']) ?></td>
                    <td class="text-right" style="color:<?= $c['atraso'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>"><?= formato_pesos($c['atraso']) ?></td>
                    <td class="text-center"><?= badge_estado_credito($c['estado']) ?></td>
                    <td class="text-center">
                        <a href="../creditos/ver?id=<?= $c['credito_id'] ?>" target="_blank" class="btn-ic btn-ghost btn-sm" title="Ver crédito">
                            <i class="fa fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--bg-card);border-top:2px solid var(--border)">
                    <td colspan="5" class="text-right fw-bold">TOTALES</td>
                    <td class="text-right fw-bold"><?= formato_pesos($total_otorgado) ?></td>
                    <td class="text-right fw-bold"><?= formato_pesos($total_cobrado) ?></td>
                    <td class="text-right fw-bold"><?= formato_pesos($total_devolucion) ?></td>
                    <td class="text-right fw-bold"><?= formato_pesos($total_incobrable) ?></td>
                    <td class="text-right fw-bold"><?= formato_pesos($total_faltante) ?></td>
                    <td class="text-right fw-bold" style="color:var(--danger)"><?= formato_pesos($total_atraso) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
