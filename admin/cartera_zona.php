<?php
// ============================================================
// admin/cartera_zona.php — Cartera por zona para cobradores con
// clientes repartidos en mas de una zona. A diferencia de
// estadisticas_zona.php (flujo de caja por periodo, segun
// vencimiento de cuotas), este reporte es por cohorte: el rango de
// fechas elige que creditos entran (por fecha_alta), y las metricas
// reflejan el estado ACTUAL de esos creditos.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Período (cohorte por fecha_alta) — o histórico completo sin fechas ──
$hoy            = date('Y-m-d');
$primer_dia_mes = date('Y-m-01');

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

// Valores a mostrar en los inputs de fecha (el histórico no tiene fecha propia)
$desde_input = $desde ?? $primer_dia_mes;
$hasta_input = $hasta ?? $hoy;

$mes_ant_ini = date('Y-m-01', strtotime('first day of last month'));
$mes_ant_fin = date('Y-m-t',  strtotime('first day of last month'));
$trim_ini    = date('Y-m-01', strtotime('-2 months'));

$periodo_activo = 'custom';
if ($modo_historico) $periodo_activo = 'historico';
elseif ($desde === $primer_dia_mes && $hasta === $hoy) $periodo_activo = 'mes';
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
$nombre_cob = '';
$zonas_cob  = [];

if ($cobrador_id > 0) {
    foreach ($cobradores as $c) {
        if ((int)$c['id'] === $cobrador_id) {
            $nombre_cob = e($c['apellido'] . ', ' . $c['nombre']);
            break;
        }
    }
    if ($nombre_cob !== '') {
        $zonas_cob = obtener_cartera_por_zona($pdo, $cobrador_id, $desde, $hasta);
    }
}

$total_clientes   = array_sum(array_column($zonas_cob, 'clientes'));
$total_importe    = array_sum(array_column($zonas_cob, 'importe_total'));
$total_atraso     = array_sum(array_column($zonas_cob, 'atraso'));
$total_devolucion = array_sum(array_column($zonas_cob, 'devolucion'));
$total_otorgado   = array_sum(array_column($zonas_cob, 'monto_otorgado'));
$total_cobrado    = array_sum(array_column($zonas_cob, 'cobrado'));
$total_faltante   = array_sum(array_column($zonas_cob, 'faltante'));
$pct_cobro_total  = $total_otorgado > 0 ? round($total_cobrado / $total_otorgado * 100) : 0;
$pct_atraso_total = $total_otorgado > 0 ? round($total_atraso  / $total_otorgado * 100) : 0;

// ── Export CSV ─────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv' && $cobrador_id > 0 && !empty($zonas_cob)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cartera_zona_cob' . $cobrador_id . '_' . ($desde ?? 'historico') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Zona', 'Clientes', 'Importe Total', 'Cobrado', 'Faltante', 'Atraso', 'Devolucion Articulos', '% Cobro', '% Atraso'], ';', '"', '\\');
    foreach ($zonas_cob as $zona => $dz) {
        fputcsv($out, [
            $zona, $dz['clientes'],
            number_format($dz['importe_total'], 2, ',', '.'),
            number_format($dz['cobrado'], 2, ',', '.'),
            number_format($dz['faltante'], 2, ',', '.'),
            number_format($dz['atraso'], 2, ',', '.'),
            number_format($dz['devolucion'], 2, ',', '.'),
            $dz['pct_cobro'] . '%', $dz['pct_atraso'] . '%',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$page_title   = 'Cartera por Zona';
$page_current = 'cartera_zona';
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
            <input type="date" name="desde" value="<?= e($desde_input) ?>" max="<?= $hoy ?>" style="min-width:140px">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:var(--text-muted);margin-bottom:5px;font-weight:600">Hasta</label>
            <input type="date" name="hasta" value="<?= e($hasta_input) ?>" max="<?= $hoy ?>" style="min-width:140px">
        </div>
        <div style="display:flex;gap:6px;padding-top:18px">
            <button type="submit" class="btn-ic btn-primary btn-sm">
                <i class="fa fa-search"></i> Ver
            </button>
        </div>
    </form>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <span style="font-size:.75rem;color:var(--text-muted);line-height:28px">Créditos otorgados —</span>
        <?php $base_url = '?cobrador_id=' . $cobrador_id; ?>
        <a href="<?= $base_url ?>&desde=<?= $primer_dia_mes ?>&hasta=<?= $hoy ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes' ? 'btn-primary' : 'btn-ghost' ?>">Mes actual</a>
        <a href="<?= $base_url ?>&desde=<?= $mes_ant_ini ?>&hasta=<?= $mes_ant_fin ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes_ant' ? 'btn-primary' : 'btn-ghost' ?>">Mes anterior</a>
        <a href="<?= $base_url ?>&desde=<?= $trim_ini ?>&hasta=<?= $hoy ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'trimestre' ? 'btn-primary' : 'btn-ghost' ?>">Último trimestre</a>
        <a href="<?= $base_url ?>&historico=1"
           class="btn-ic btn-sm <?= $periodo_activo === 'historico' ? 'btn-primary' : 'btn-ghost' ?>">General (sin fechas)</a>
    </div>
    <div style="font-size:.75rem;color:var(--text-muted);margin-top:8px">
        <?php if ($modo_historico): ?>
            <i class="fa fa-info-circle"></i> Modo general: se muestra toda la cartera del cobrador en cada zona,
            sin importar cuándo se otorgó el crédito.
        <?php else: ?>
            <i class="fa fa-info-circle"></i> El período elige qué créditos entran (según su fecha de alta) — los montos
            reflejan el estado actual de esos créditos, no solo lo que pasó dentro del rango.
        <?php endif; ?>
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
        <a href="?cobrador_id=<?= $c['id'] ?><?= $modo_historico ? '&historico=1' : '&desde=' . urlencode($desde) . '&hasta=' . urlencode($hasta) ?>"
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
            <?php if ($modo_historico): ?>
                Toda la cartera (histórico completo)
            <?php else: ?>
                Créditos otorgados <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (empty($zonas_cob)): ?>
    <div class="card-ic" style="padding:20px;color:var(--text-muted)">
        <?= $modo_historico ? 'Sin créditos registrados para este cobrador.' : 'Sin créditos otorgados para este cobrador en el período elegido.' ?>
    </div>
<?php else: ?>

<?php
function color_atraso(int $pct): string {
    return $pct <= 15 ? '#22c55e' : ($pct <= 35 ? '#f59e0b' : '#ef4444');
}
function color_cobro(int $pct): string {
    return $pct >= 70 ? '#22c55e' : ($pct >= 40 ? '#f59e0b' : '#ef4444');
}
$color_hero = color_atraso($pct_atraso_total);
?>

<!-- Hero: % Atraso General como protagonista + resto compacto al costado -->
<div style="display:flex;gap:28px;align-items:center;flex-wrap:wrap;margin-bottom:20px;
            padding:20px 26px;background:var(--dark-bg);border-radius:12px;border:1px solid var(--dark-border)">
    <div>
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px">
            % Atraso General
        </div>
        <div style="font-size:2.8rem;font-weight:900;line-height:1;color:<?= $color_hero ?>"><?= $pct_atraso_total ?>%</div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px">
            <?= formato_pesos($total_atraso) ?> en mora sobre <?= formato_pesos($total_otorgado) ?> otorgado
        </div>
    </div>
    <div style="flex:1;min-width:220px;display:flex;flex-direction:column;gap:7px;
                padding-left:26px;border-left:1px solid var(--dark-border)">
        <div style="display:flex;justify-content:space-between;font-size:.82rem">
            <span style="color:var(--text-muted)">Clientes</span>
            <span style="font-weight:700"><?= number_format($total_clientes, 0, ',', '.') ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem">
            <span style="color:var(--text-muted)">Importe Total</span>
            <span style="font-weight:700"><?= formato_pesos($total_importe) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem">
            <span style="color:var(--text-muted)">Cobrado</span>
            <span style="font-weight:700"><?= formato_pesos($total_cobrado) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem">
            <span style="color:var(--text-muted)">Faltante</span>
            <span style="font-weight:700"><?= formato_pesos($total_faltante) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem">
            <span style="color:var(--text-muted)">Devolución Artículos</span>
            <span style="font-weight:700;color:<?= $total_devolucion > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>"><?= formato_pesos($total_devolucion) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.82rem">
            <span style="color:var(--text-muted)">% Cobro</span>
            <span style="font-weight:700;color:<?= color_cobro($pct_cobro_total) ?>"><?= $pct_cobro_total ?>%</span>
        </div>
    </div>
</div>

<!-- Tarjetas por zona, ordenadas por % Atraso -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-triangle-exclamation"></i> Cartera por Zona — ordenado por riesgo</span>
        <div style="display:flex;align-items:center;gap:8px">
            <?php $qs_export = $modo_historico
                ? http_build_query(['cobrador_id' => $cobrador_id, 'historico' => 1])
                : http_build_query(['cobrador_id' => $cobrador_id, 'desde' => $desde, 'hasta' => $hasta]); ?>
            <a href="?<?= $qs_export ?>&export=csv" class="btn-ic btn-success btn-sm" title="Exportar a CSV">
                <i class="fa fa-file-csv"></i> CSV
            </a>
            <a href="cartera_zona_pdf?<?= $qs_export ?>" target="_blank" class="btn-ic btn-ghost btn-sm" title="Exportar a PDF">
                <i class="fa fa-file-pdf"></i> PDF
            </a>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;padding:18px">
        <?php foreach ($zonas_cob as $zona => $dz):
            $ca = color_atraso($dz['pct_atraso']);
            $cc = color_cobro($dz['pct_cobro']);
            $bg_tint = $dz['pct_atraso'] > 35 ? 'rgba(239,68,68,.05)' : ($dz['pct_atraso'] > 15 ? 'rgba(245,158,11,.05)' : 'transparent');
        ?>
        <div style="background:<?= $bg_tint ?>;border:1px solid var(--dark-border);border-radius:12px;padding:18px 20px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:14px">
                <div style="font-weight:700;font-size:1rem"><?= e($zona) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= $dz['clientes'] ?> cliente<?= $dz['clientes'] != 1 ? 's' : '' ?></div>
            </div>

            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px">
                    <span style="font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.4px">% Atraso</span>
                    <span style="font-size:1.5rem;font-weight:900;color:<?= $ca ?>"><?= $dz['pct_atraso'] ?>%</span>
                </div>
                <div style="height:10px;background:var(--dark-border);border-radius:5px;overflow:hidden">
                    <div style="width:<?= min(100, $dz['pct_atraso']) ?>%;height:100%;background:<?= $ca ?>;border-radius:5px"></div>
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px">
                <span style="color:var(--text-muted)">Importe Total</span>
                <span style="font-weight:700"><?= formato_pesos($dz['importe_total']) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px">
                <span style="color:var(--text-muted)">Cobrado</span>
                <span style="font-weight:700"><?= formato_pesos($dz['cobrado']) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px">
                <span style="color:var(--text-muted)">Faltante</span>
                <span style="font-weight:700"><?= formato_pesos($dz['faltante']) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:12px">
                <span style="color:var(--text-muted)">Atraso</span>
                <span style="font-weight:700;color:<?= $dz['atraso'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>"><?= formato_pesos($dz['atraso']) ?></span>
            </div>

            <?php if ($dz['devolucion'] > 0): ?>
            <div style="display:inline-block;background:rgba(245,158,11,.15);color:#f59e0b;font-size:.72rem;
                        font-weight:700;padding:3px 10px;border-radius:999px;margin-bottom:12px">
                Devolución: <?= formato_pesos($dz['devolucion']) ?>
            </div>
            <?php endif; ?>

            <div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                    <span style="font-size:.66rem;color:var(--text-muted)">% Cobro</span>
                    <span style="font-size:.76rem;font-weight:700;color:<?= $cc ?>"><?= $dz['pct_cobro'] ?>%</span>
                </div>
                <div style="height:4px;background:var(--dark-border);border-radius:2px;overflow:hidden">
                    <div style="width:<?= min(100, $dz['pct_cobro']) ?>%;height:100%;background:<?= $cc ?>;border-radius:2px"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Gráfico: Importe Total vs % Atraso -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-braille"></i> Importe Total vs % Atraso</span>
        <span class="text-muted" style="font-size:.78rem">zonas grandes-y-sanas vs. chicas-pero-riesgosas</span>
    </div>
    <div style="padding:16px;position:relative;height:340px">
        <canvas id="chartZonas"></canvas>
    </div>
</div>

<?php
$scatter_data = [];
foreach ($zonas_cob as $zona => $dz) {
    $scatter_data[] = [
        'x' => round($dz['importe_total'], 2),
        'y' => $dz['pct_atraso'],
        'zona' => $zona,
        'color' => color_atraso($dz['pct_atraso']),
    ];
}
$json_scatter = json_encode($scatter_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
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
    const raw = {$json_scatter};
    new Chart(ctx, {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'Zonas',
                data: raw.map(r => ({x: r.x, y: r.y})),
                backgroundColor: raw.map(r => r.color),
                borderColor: raw.map(r => r.color),
                pointRadius: 7,
                pointHoverRadius: 9
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const r = raw[ctx.dataIndex];
                            return r.zona + ': \$ ' + Number(r.x).toLocaleString('es-AR', { maximumFractionDigits: 0 }) + ' — ' + r.y + '% atraso';
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Importe Total ($)' },
                    grid: { color: 'rgba(255,255,255,.05)' },
                    ticks: { font: { size: 11 }, callback: v => '\$' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 }) }
                },
                y: {
                    title: { display: true, text: '% Atraso' },
                    grid: { color: 'rgba(255,255,255,.05)' },
                    ticks: { font: { size: 11 } }
                }
            }
        }
    });
})();
</script>
SCRIPTS;
}
require_once __DIR__ . '/../views/layout_footer.php';
?>
