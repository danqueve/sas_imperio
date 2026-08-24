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
$total_cobrado_cob = 0.0;
$total_vencido_cob = 0.0;

if ($cobrador_id > 0) {
    foreach ($cobradores as $c) {
        if ((int)$c['id'] === $cobrador_id) {
            $nombre_cob = e($c['apellido'] . ', ' . $c['nombre']);
            break;
        }
    }

    if ($nombre_cob !== '') {
        $zInit = ['clientes' => 0, 'cuotas_cobradas' => 0, 'cobrado' => 0.0, 'vencido' => 0.0];

        // Monto cobrado + cuotas + clientes, por zona, en el período
        $stmtCobrado = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(cl.zona), ''), 'Sin zona') AS zona,
                   COUNT(DISTINCT cl.id) AS clientes,
                   COUNT(pc.id) AS cuotas_cobradas,
                   COALESCE(SUM(pc.monto_total), 0) AS cobrado
            FROM ic_pagos_confirmados pc
            JOIN ic_cuotas cu   ON cu.id = pc.cuota_id
            JOIN ic_creditos cr ON cr.id = cu.credito_id
            JOIN ic_clientes cl ON cl.id = cr.cliente_id
            WHERE pc.cobrador_id = ? AND pc.fecha_jornada BETWEEN ? AND ?
            GROUP BY zona
        ");
        $stmtCobrado->execute([$cobrador_id, $desde, $hasta]);

        // Cartera vencida por zona (situación actual, no depende del período elegido)
        $stmtVencido = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(cl.zona), ''), 'Sin zona') AS zona,
                   SUM(cu.monto_cuota - cu.saldo_pagado) AS vencido
            FROM ic_cuotas cu
            JOIN ic_creditos cr ON cu.credito_id = cr.id
            JOIN ic_clientes cl ON cr.cliente_id = cl.id
            WHERE cr.cobrador_id = ?
              AND cu.estado IN ('VENCIDA','PARCIAL')
              AND cu.fecha_vencimiento < CURDATE()
            GROUP BY zona
        ");
        $stmtVencido->execute([$cobrador_id]);

        foreach ($stmtCobrado->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $z = $r['zona'];
            $zonas_cob[$z] = $zonas_cob[$z] ?? $zInit;
            $zonas_cob[$z]['clientes']        = (int)$r['clientes'];
            $zonas_cob[$z]['cuotas_cobradas'] = (int)$r['cuotas_cobradas'];
            $zonas_cob[$z]['cobrado']         = (float)$r['cobrado'];
        }
        foreach ($stmtVencido->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $z = $r['zona'];
            $zonas_cob[$z] = $zonas_cob[$z] ?? $zInit;
            $zonas_cob[$z]['vencido'] = (float)$r['vencido'];
        }

        uasort($zonas_cob, fn($a, $b) => $b['cobrado'] <=> $a['cobrado']);

        foreach ($zonas_cob as $dz) {
            $total_cobrado_cob += $dz['cobrado'];
            $total_vencido_cob += $dz['vencido'];
        }
    }
}

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
        Sin cobros ni cartera vencida registrados para este cobrador en el período elegido.
    </div>
<?php else: ?>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:16px">
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Total Cobrado</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--success)"><?= formato_pesos($total_cobrado_cob) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Zonas</div>
        <div style="font-size:1.3rem;font-weight:800"><?= count($zonas_cob) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Zona con más cobro</div>
        <div style="font-size:1.05rem;font-weight:800"><?= e((string) array_key_first($zonas_cob)) ?></div>
    </div>
    <div class="card-ic" style="padding:14px 18px">
        <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">Cartera Vencida</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--danger)"><?= formato_pesos($total_vencido_cob) ?></div>
    </div>
</div>

<!-- Tabla Cobrado por Zona -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-table-columns"></i> Cobrado por Zona</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic" style="min-width:520px">
            <thead>
                <tr>
                    <th>Zona</th>
                    <th style="text-align:right">Clientes</th>
                    <th style="text-align:right">Cuotas cobradas</th>
                    <th style="text-align:right">Monto cobrado</th>
                    <th style="text-align:right">Cartera vencida</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($zonas_cob as $zona => $dz): ?>
                <tr>
                    <td style="font-weight:600"><?= e($zona) ?></td>
                    <td style="text-align:right"><?= number_format($dz['clientes'], 0, ',', '.') ?></td>
                    <td style="text-align:right"><?= number_format($dz['cuotas_cobradas'], 0, ',', '.') ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--success)"><?= formato_pesos($dz['cobrado']) ?></td>
                    <td style="text-align:right;color:<?= $dz['vencido'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>">
                        <?= formato_pesos($dz['vencido']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:800;border-top:2px solid rgba(255,255,255,.12)">
                    <td>TOTAL</td>
                    <td></td>
                    <td></td>
                    <td style="text-align:right;color:var(--success)"><?= formato_pesos($total_cobrado_cob) ?></td>
                    <td style="text-align:right;color:var(--danger)"><?= formato_pesos($total_vencido_cob) ?></td>
                </tr>
            </tfoot>
        </table>
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
$json_lz = json_encode($labels_zona, JSON_UNESCAPED_UNICODE);
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
