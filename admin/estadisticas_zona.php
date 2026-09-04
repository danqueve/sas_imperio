<?php
// ============================================================
// admin/estadisticas_zona.php — Cobro por zona para cobradores
// con clientes repartidos en mas de una zona
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Período (mismo patrón que admin/estadisticas_cobrador.php) ──
$hoy            = date('Y-m-d');
$primer_dia_mes = date('Y-m-01');

$desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
    ? $_GET['desde'] : $primer_dia_mes;
$hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
    ? $_GET['hasta'] : $hoy;
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

$mes_ant_ini = date('Y-m-01', strtotime('first day of last month'));
$mes_ant_fin = date('Y-m-t',  strtotime('first day of last month'));
$trim_ini    = date('Y-m-01', strtotime('-2 months'));

$periodo_activo = 'custom';
if ($desde === $primer_dia_mes && $hasta === $hoy) $periodo_activo = 'mes';
elseif ($desde === $mes_ant_ini && $hasta === $mes_ant_fin) $periodo_activo = 'mes_ant';
elseif ($desde === $trim_ini    && $hasta === $hoy) $periodo_activo = 'trimestre';

// ── Cobrador seleccionado ────────────────────────────────────
$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);

// ── Lista de cobradores + cantidad de zonas distintas ────────
$cobradores = $pdo->query("
    SELECT u.id, u.nombre, u.apellido,
           COUNT(DISTINCT cl.zona) AS zonas_distintas
    FROM ic_usuarios u
    LEFT JOIN ic_clientes cl ON cl.cobrador_id = u.id
                            AND cl.zona IS NOT NULL AND cl.zona <> ''
    WHERE u.rol = 'cobrador' AND u.activo = 1
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY zonas_distintas DESC, u.apellido, u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$cobradores_multizona = array_values(array_filter($cobradores, fn($c) => (int)$c['zonas_distintas'] > 1));

// ── Datos por zona del cobrador seleccionado ─────────────────
$nombre_cob   = '';
$zonas_cob    = [];
$total_cobrado_cob  = 0.0;
$total_estimado_cob = 0.0;
$total_faltante_cob = 0.0;

if ($cobrador_id > 0) {
    foreach ($cobradores as $c) {
        if ((int)$c['id'] === $cobrador_id) {
            $nombre_cob = e($c['apellido'] . ', ' . $c['nombre']);
            break;
        }
    }

    if ($nombre_cob !== '') {
        $zonas_cob = obtener_estadisticas_zona($pdo, $cobrador_id, $desde, $hasta);
        uasort($zonas_cob, fn($a, $b) => $b['cobrado'] <=> $a['cobrado']);

        foreach ($zonas_cob as $dz) {
            $total_cobrado_cob  += $dz['cobrado'];
            $total_estimado_cob += $dz['estimado'];
            $total_faltante_cob += $dz['faltante'];
        }
    }
}

$total_cobrado_periodo = max(0, $total_estimado_cob - $total_faltante_cob);
$pct_periodo_total = $total_estimado_cob > 0 ? round($total_cobrado_periodo / $total_estimado_cob * 100) : null;

$page_title   = 'Cobranza por Zona';
$page_current = 'estadisticas_zona';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── FILTROS ───────────────────────────────────────────────── -->
<div class="card-ic mb-4">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div style="flex:1;min-width:220px">
            <label style="display:block;font-size:.75rem;color:var(--text-muted);margin-bottom:5px;font-weight:600">
                <i class="fa fa-user-tie"></i> Cobrador
            </label>
            <select name="cobrador_id" style="width:100%">
                <option value="">— Seleccionar cobrador —</option>
                <?php foreach ($cobradores as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $cobrador_id === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['apellido'] . ', ' . $c['nombre']) ?>
                        <?= (int)$c['zonas_distintas'] > 0 ? ' (' . $c['zonas_distintas'] . ' zona' . ($c['zonas_distintas'] != 1 ? 's' : '') . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:var(--text-muted);margin-bottom:5px;font-weight:600">Desde</label>
            <input type="date" name="desde" value="<?= e($desde) ?>" max="<?= $hoy ?>" style="min-width:140px">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:var(--text-muted);margin-bottom:5px;font-weight:600">Hasta</label>
            <input type="date" name="hasta" value="<?= e($hasta) ?>" max="<?= $hoy ?>" style="min-width:140px">
        </div>
        <div style="display:flex;gap:6px;padding-top:18px">
            <button type="submit" class="btn-ic btn-primary btn-sm">
                <i class="fa fa-search"></i> Ver
            </button>
        </div>
    </form>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <span style="font-size:.75rem;color:var(--text-muted);line-height:28px">Período rápido:</span>
        <?php $base_url = '?cobrador_id=' . $cobrador_id; ?>
        <a href="<?= $base_url ?>&desde=<?= $primer_dia_mes ?>&hasta=<?= $hoy ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes' ? 'btn-primary' : 'btn-ghost' ?>">Mes actual</a>
        <a href="<?= $base_url ?>&desde=<?= $mes_ant_ini ?>&hasta=<?= $mes_ant_fin ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes_ant' ? 'btn-primary' : 'btn-ghost' ?>">Mes anterior</a>
        <a href="<?= $base_url ?>&desde=<?= $trim_ini ?>&hasta=<?= $hoy ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'trimestre' ? 'btn-primary' : 'btn-ghost' ?>">Último trimestre</a>
    </div>
</div>

<?php if ($cobrador_id <= 0 || $nombre_cob === ''): ?>
<!-- ── PANTALLA INICIAL: COBRADORES CON MAS DE UNA ZONA ─────── -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-map-location-dot"></i> Cobradores con más de una zona</span>
    </div>
    <?php if (empty($cobradores_multizona)): ?>
        <div style="padding:20px;color:var(--text-muted)">Ningún cobrador activo tiene clientes en más de una zona por ahora.</div>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;padding:16px">
        <?php foreach ($cobradores_multizona as $c): ?>
        <a href="?cobrador_id=<?= $c['id'] ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
           style="flex:1;min-width:200px;max-width:260px;text-decoration:none;
                  background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.08);
                  border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;
                  transition:border-color .2s,background .2s"
           onmouseover="this.style.borderColor='var(--primary)';this.style.background='rgba(60,80,224,.08)'"
           onmouseout="this.style.borderColor='rgba(255,255,255,.08)';this.style.background='rgba(0,0,0,.2)'">
            <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);
                        display:flex;align-items:center;justify-content:center;
                        font-weight:800;font-size:.85rem;flex-shrink:0">
                <?= strtoupper(mb_substr($c['nombre'],0,1).mb_substr($c['apellido'],0,1)) ?>
            </div>
            <div>
                <div style="font-weight:700;font-size:.88rem;color:var(--text)">
                    <?= e($c['apellido'].', '.$c['nombre']) ?>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">
                    <?= $c['zonas_distintas'] ?> zonas · Ver detalle <i class="fa fa-arrow-right"></i>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ── DETALLE DEL COBRADOR SELECCIONADO ──────────────────────── -->

<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap">
    <div style="width:48px;height:48px;border-radius:50%;background:var(--primary);
                display:flex;align-items:center;justify-content:center;
                font-weight:900;font-size:1.1rem;flex-shrink:0">
        <?php foreach ($cobradores as $c) if ((int)$c['id'] === $cobrador_id):
            echo strtoupper(mb_substr($c['nombre'],0,1).mb_substr($c['apellido'],0,1));
        endif; ?>
    </div>
    <div>
        <div style="font-size:1.1rem;font-weight:800"><?= $nombre_cob ?></div>
        <div style="font-size:.75rem;color:var(--text-muted)">
            <?= count($zonas_cob) ?> zona<?= count($zonas_cob) !== 1 ? 's' : '' ?> ·
            <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
        </div>
    </div>
</div>

<?php if (empty($zonas_cob)): ?>
    <div class="card-ic" style="padding:20px;color:var(--text-muted)">
        Sin cobros ni cuotas con vencimiento registrados para este cobrador en el período elegido.
    </div>
<?php else: ?>

<!-- KPIs: grupo principal (reconcilia siempre: Cobrado + Faltante = Estimado) -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:10px">
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Estimado del período</div>
        <div style="font-size:1.3rem;font-weight:800"><?= formato_pesos($total_estimado_cob) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Cobrado del período</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--success)"><?= formato_pesos($total_cobrado_periodo) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Faltante</div>
        <div style="font-size:1.3rem;font-weight:800;color:<?= $total_faltante_cob > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>"><?= formato_pesos($total_faltante_cob) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">% Éxito del período</div>
        <?php if ($pct_periodo_total !== null): ?>
        <div style="font-size:1.3rem;font-weight:800;color:<?= $pct_periodo_total >= 80 ? 'var(--success)' : ($pct_periodo_total >= 50 ? 'var(--warning)' : 'var(--danger)') ?>">
            <?= $pct_periodo_total ?>%
        </div>
        <?php else: ?>
        <div style="font-size:1.3rem;font-weight:800;color:var(--text-muted)">—</div>
        <?php endif; ?>
    </div>
</div>

<!-- KPIs: grupo secundario — caja real (puede incluir deuda de otros períodos) -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:16px;
            padding-top:12px;border-top:1px dashed rgba(255,255,255,.1)">
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">
            Caja Total Recibida
            <i class="fa fa-circle-info" style="opacity:.6"
               title="Todo lo que entró en el período. Puede incluir cobros de cuotas vencidas en otros períodos, o no incluir cuotas de este período pagadas manualmente por un admin/supervisor — por eso no se resta contra Estimado/Faltante."></i>
        </div>
        <div style="font-size:1.3rem;font-weight:800"><?= formato_pesos($total_cobrado_cob) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Zonas</div>
        <div style="font-size:1.3rem;font-weight:800"><?= count($zonas_cob) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Zona con más cobro</div>
        <div style="font-size:1.05rem;font-weight:800"><?= e((string) array_key_first($zonas_cob)) ?></div>
    </div>
</div>

<!-- Tabla Cobrado por Zona -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-table-columns"></i> Cobrado por Zona</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic" style="min-width:980px">
            <thead>
                <tr>
                    <th>Zona</th>
                    <th style="text-align:right">Clientes</th>
                    <th style="text-align:right">Cuotas cobradas</th>
                    <th style="text-align:right">Estimado</th>
                    <th style="text-align:right">Cobrado (del período)</th>
                    <th style="text-align:right">Faltante</th>
                    <th style="text-align:right">% Éxito</th>
                    <th style="text-align:right;border-left:1px dashed rgba(255,255,255,.15)"
                        title="Caja real del período — puede no coincidir con 'Cobrado (del período)', ver nota abajo">Caja Total</th>
                    <th style="text-align:center">PDF</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($zonas_cob as $zona => $dz):
                    $qs_pdf = http_build_query(['cobrador_id' => $cobrador_id, 'desde' => $desde, 'hasta' => $hasta, 'zona' => $zona]);
                    $color_pct = $dz['pct_periodo'] === null ? null
                        : ($dz['pct_periodo'] >= 80 ? 'var(--success)' : ($dz['pct_periodo'] >= 50 ? 'var(--warning)' : 'var(--danger)'));
                ?>
                <tr>
                    <td style="font-weight:600"><?= e($zona) ?></td>
                    <td style="text-align:right"><?= number_format($dz['clientes'], 0, ',', '.') ?></td>
                    <td style="text-align:right"><?= number_format($dz['cuotas_cobradas'], 0, ',', '.') ?></td>
                    <td style="text-align:right"><?= formato_pesos($dz['estimado']) ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--success)"><?= formato_pesos($dz['cobrado_periodo']) ?></td>
                    <td style="text-align:right;color:<?= $dz['faltante'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>">
                        <?= formato_pesos($dz['faltante']) ?>
                    </td>
                    <?php if ($color_pct !== null): ?>
                    <td style="text-align:right;font-weight:700;color:<?= $color_pct ?>"><?= $dz['pct_periodo'] ?>%</td>
                    <?php else: ?>
                    <td style="text-align:right;color:var(--text-muted)" title="Nada vencía en esta zona en el período elegido">—</td>
                    <?php endif; ?>
                    <td style="text-align:right;border-left:1px dashed rgba(255,255,255,.15)"><?= formato_pesos($dz['cobrado']) ?></td>
                    <td style="text-align:center;white-space:nowrap">
                        <a href="estadisticas_zona_resumen_pdf?<?= $qs_pdf ?>" target="_blank" title="PDF Resumen">
                            <i class="fa fa-file-pdf" style="color:var(--text-muted)"></i>
                        </a>
                        <a href="estadisticas_zona_cobrados_pdf?<?= $qs_pdf ?>" target="_blank" title="PDF Clientes Cobrados" style="margin-left:8px">
                            <i class="fa fa-file-invoice-dollar" style="color:var(--success)"></i>
                        </a>
                        <a href="estadisticas_zona_faltantes_pdf?<?= $qs_pdf ?>" target="_blank" title="PDF Clientes Faltantes" style="margin-left:8px">
                            <i class="fa fa-file-circle-exclamation" style="color:var(--danger)"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:800;border-top:2px solid rgba(255,255,255,.12)">
                    <td>TOTAL</td>
                    <td></td>
                    <td></td>
                    <td style="text-align:right"><?= formato_pesos($total_estimado_cob) ?></td>
                    <td style="text-align:right;color:var(--success)"><?= formato_pesos($total_cobrado_periodo) ?></td>
                    <td style="text-align:right;color:var(--danger)"><?= formato_pesos($total_faltante_cob) ?></td>
                    <td style="text-align:right"><?= $pct_periodo_total ?? '—' ?><?= $pct_periodo_total !== null ? '%' : '' ?></td>
                    <td style="text-align:right;border-left:1px dashed rgba(255,255,255,.15)"><?= formato_pesos($total_cobrado_cob) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <div style="padding:10px 4px 2px;font-size:.75rem;color:var(--text-muted)">
            <i class="fa fa-circle-info"></i>
            "Cobrado (del período)" y "Faltante" siempre suman "Estimado". "Caja Total" es la plata real
            que entró en el período y puede no coincidir: incluye cobros de cuotas vencidas en otros
            períodos, o no incluye cuotas de este período pagadas manualmente por un admin/supervisor.
        </div>
    </div>
</div>

<!-- Gráfico -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-chart-simple"></i> Monto cobrado por zona</span>
    </div>
    <div style="padding:16px;position:relative;height:<?= max(260, count($zonas_cob) * 34) ?>px">
        <canvas id="chartZonas"></canvas>
    </div>
</div>

<?php
$labels_zona = array_keys($zonas_cob);
$vals_zona   = array_map(fn($z) => round($z['cobrado'], 2), array_values($zonas_cob));
$json_lz = json_encode($labels_zona, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$json_vz = json_encode($vals_zona);
?>
<?php endif; ?>
<?php endif; ?>

<?php
$page_scripts = '';
if ($cobrador_id > 0 && !empty($zonas_cob)) {
    $page_scripts = <<<SCRIPTS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = 'rgba(255,255,255,.6)';
Chart.defaults.borderColor = 'rgba(255,255,255,.07)';
Chart.defaults.font.family = "'Sarabun', sans-serif";

(function() {
    const ctx = document.getElementById('chartZonas');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$json_lz},
            datasets: [{
                label: 'Cobrado',
                data: {$json_vz},
                backgroundColor: 'rgba(60,80,224,.75)',
                borderColor: '#3C50E0',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' \$ ' + Number(ctx.raw).toLocaleString('es-AR', { maximumFractionDigits: 0 })
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { font: { size: 11 } } },
                y: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });
})();
</script>
SCRIPTS;
}
require_once __DIR__ . '/../views/layout_footer.php';
?>
