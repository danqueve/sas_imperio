<?php
// ============================================================
// admin/cobrador_detalle.php — Ficha individual de cobrador
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Cobrador ─────────────────────────────────────────────────
$cobrador_id = (int)($_GET['id'] ?? 0);
if ($cobrador_id <= 0) {
    header('Location: ranking_cobradores');
    exit;
}

$cobrador = $pdo->prepare(
    "SELECT id, nombre, apellido FROM ic_usuarios
     WHERE id = ? AND rol = 'cobrador' AND activo = 1"
);
$cobrador->execute([$cobrador_id]);
$cobrador = $cobrador->fetch(PDO::FETCH_ASSOC);
if (!$cobrador) {
    header('Location: ranking_cobradores');
    exit;
}

// ── Período ───────────────────────────────────────────────────
$hoy            = date('Y-m-d');
$primer_dia_mes = date('Y-m-01');

$desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
    ? $_GET['desde'] : $primer_dia_mes;
$hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
    ? $_GET['hasta'] : $hoy;
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

// Si el rango termina en sábado, extender 1 día para incluir entradas tardías (domingo = sábado)
$hasta_ext = (date('N', strtotime($hasta)) == 6)
    ? date('Y-m-d', strtotime($hasta . ' +1 day'))
    : $hasta;

$mes_ant_ini = date('Y-m-01', strtotime('first day of last month'));
$mes_ant_fin = date('Y-m-t',  strtotime('first day of last month'));
$trim_ini    = date('Y-m-01', strtotime('-2 months'));

$periodo_activo = 'custom';
if ($desde === $primer_dia_mes && $hasta === $hoy) $periodo_activo = 'mes';
elseif ($desde === $mes_ant_ini && $hasta === $mes_ant_fin) $periodo_activo = 'mes_ant';
elseif ($desde === $trim_ini    && $hasta === $hoy) $periodo_activo = 'trimestre';

$qs = http_build_query(['desde' => $desde, 'hasta' => $hasta]);

// ── KPIs de producción ────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*)                   AS cant_pagos,
           COALESCE(SUM(monto_total),0) AS total_cobrado,
           COALESCE(SUM(monto_mora_cobrada),0) AS mora_cobrada,
           COALESCE(SUM(monto_efectivo),0) AS efectivo,
           COALESCE(SUM(monto_transferencia),0) AS transferencia
    FROM ic_pagos_confirmados
    WHERE cobrador_id = ? AND fecha_pago BETWEEN ? AND ?
");
$stmt->execute([$cobrador_id, $desde, $hasta_ext]);
$prod = $stmt->fetch(PDO::FETCH_ASSOC);

$ticket_promedio = $prod['cant_pagos'] > 0
    ? $prod['total_cobrado'] / $prod['cant_pagos'] : 0;

// ── Calidad de cartera ────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT cr.id) AS total_activos,
           COUNT(DISTINCT CASE WHEN cr.estado='MOROSO' THEN cr.id END) AS morosos,
           COUNT(CASE WHEN cu.estado IN('VENCIDA','PARCIAL')
                       AND cu.fecha_vencimiento < CURDATE() THEN 1 END) AS cuotas_vencidas,
           ROUND(AVG(CASE WHEN cu.estado IN('VENCIDA','PARCIAL')
                           AND cu.fecha_vencimiento < CURDATE()
                           THEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) END)) AS dias_prom,
           COALESCE(SUM(CASE WHEN cu.estado IN('VENCIDA','PARCIAL')
                              AND cu.fecha_vencimiento < CURDATE()
                              THEN cu.monto_cuota - cu.saldo_pagado END), 0) AS monto_vencido
    FROM ic_creditos cr
    LEFT JOIN ic_cuotas cu ON cu.credito_id = cr.id
    WHERE cr.cobrador_id = ? AND cr.estado IN ('EN_CURSO','MOROSO')
");
$stmt->execute([$cobrador_id]);
$cartera = $stmt->fetch(PDO::FETCH_ASSOC);

$pct_morosidad = $cartera['total_activos'] > 0
    ? round($cartera['morosos'] / $cartera['total_activos'] * 100, 1) : 0;

// ── Tasa de recuperación ──────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS agendadas,
           SUM(CASE WHEN cu.estado='PAGADA' THEN 1 ELSE 0 END) AS cobradas
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cr.cobrador_id = ? AND cu.fecha_vencimiento BETWEEN ? AND ?
");
$stmt->execute([$cobrador_id, $desde, $hasta]);
$recup = $stmt->fetch(PDO::FETCH_ASSOC);
$tasa_recup = $recup['agendadas'] > 0
    ? round($recup['cobradas'] / $recup['agendadas'] * 100) : 0;

// ── Finalizados en el período ─────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS finalizados FROM ic_creditos
    WHERE cobrador_id = ? AND estado='FINALIZADO'
      AND fecha_finalizacion BETWEEN ? AND ?
");
$stmt->execute([$cobrador_id, $desde, $hasta]);
$finalizados = (int)$stmt->fetchColumn();

// ── Sin visitar (morosos sin cobro 30 días) ───────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT cr.id) AS sin_visitar
    FROM ic_creditos cr
    WHERE cr.cobrador_id = ? AND cr.estado = 'MOROSO'
      AND cr.id NOT IN (
          SELECT DISTINCT cu2.credito_id
          FROM ic_pagos_temporales pt
          JOIN ic_cuotas cu2 ON pt.cuota_id = cu2.id
          WHERE pt.fecha_jornada >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND pt.estado IN ('PENDIENTE','APROBADO')
      )
");
$stmt->execute([$cobrador_id]);
$sin_visitar = (int)$stmt->fetchColumn();

// ── Cobros diarios del período (para gráfico de barras) ───────
$stmt = $pdo->prepare("
    SELECT DATE(fecha_pago) AS dia,
           SUM(monto_total) AS total,
           COUNT(*) AS pagos
    FROM ic_pagos_confirmados
    WHERE cobrador_id = ? AND fecha_pago BETWEEN ? AND ?
    GROUP BY DATE(fecha_pago)
    ORDER BY dia
");
$stmt->execute([$cobrador_id, $desde, $hasta_ext]);
$cobros_diarios_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Promedio diario del equipo (excluyendo al cobrador)
$stmt = $pdo->prepare("
    SELECT DATE(pc.fecha_pago) AS dia,
           AVG(sub.total) AS promedio
    FROM (
        SELECT fecha_pago, cobrador_id, SUM(monto_total) AS total
        FROM ic_pagos_confirmados
        WHERE cobrador_id != ? AND fecha_pago BETWEEN ? AND ?
        GROUP BY fecha_pago, cobrador_id
    ) sub
    GROUP BY DATE(sub.fecha_pago)
    ORDER BY dia
");
$stmt->execute([$cobrador_id, $desde, $hasta_ext]);
$prom_equipo_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construir series indexadas por fecha
$dias_intervalo = [];
$d_iter = new DateTime($desde);
$d_fin  = new DateTime($hasta);
while ($d_iter <= $d_fin) {
    $dias_intervalo[] = $d_iter->format('Y-m-d');
    $d_iter->modify('+1 day');
}

// Si hay muchos días, agrupar por semanas
$agrupar_semanas = count($dias_intervalo) > 45;

if ($agrupar_semanas) {
    // Agrupar cobros por semana ISO
    $cobros_por_sem = [];
    foreach ($cobros_diarios_raw as $r) {
        $sem = date('Y-W', strtotime($r['dia']));
        $cobros_por_sem[$sem] = ($cobros_por_sem[$sem] ?? 0) + (float)$r['total'];
    }
    $prom_por_sem = [];
    foreach ($prom_equipo_raw as $r) {
        $sem = date('Y-W', strtotime($r['dia']));
        $prom_por_sem[$sem] = ($prom_por_sem[$sem] ?? 0) + (float)$r['promedio'];
    }
    ksort($cobros_por_sem);
    $chart_labels   = array_map(fn($s) => 'Sem '.$s, array_keys($cobros_por_sem));
    $chart_cobrador = array_values($cobros_por_sem);
    $chart_promedio = array_map(fn($s) => round($prom_por_sem[$s] ?? 0, 2), array_keys($cobros_por_sem));
} else {
    $cobros_map = array_column($cobros_diarios_raw, 'total', 'dia');
    $prom_map   = array_column($prom_equipo_raw, 'promedio', 'dia');
    $chart_labels   = array_map(fn($d) => date('d/m', strtotime($d)), $dias_intervalo);
    $chart_cobrador = array_map(fn($d) => round((float)($cobros_map[$d] ?? 0), 2), $dias_intervalo);
    $chart_promedio = array_map(fn($d) => round((float)($prom_map[$d]   ?? 0), 2), $dias_intervalo);
}

// ── Motivos de finalización (torta) ──────────────────────────
$stmt = $pdo->prepare("
    SELECT motivo_finalizacion, COUNT(*) AS cant
    FROM ic_creditos
    WHERE cobrador_id = ? AND estado = 'FINALIZADO' AND motivo_finalizacion IS NOT NULL
    GROUP BY motivo_finalizacion
");
$stmt->execute([$cobrador_id]);
$motivos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$motivos_labels = [
    'PAGO_COMPLETO'           => 'Pago completo',
    'PAGO_COMPLETO_CON_MORA'  => 'Pago con mora',
    'RETIRO_PRODUCTO'         => 'Retiro de producto',
    'INCOBRABILIDAD'          => 'Incobrabilidad',
    'ACUERDO_EXTRAJUDICIAL'   => 'Acuerdo extrajud.',
];
$motivos_colores = [
    'PAGO_COMPLETO'           => 'rgba(33,150,83,.8)',
    'PAGO_COMPLETO_CON_MORA'  => 'rgba(52,211,153,.8)',
    'RETIRO_PRODUCTO'         => 'rgba(245,158,11,.8)',
    'INCOBRABILIDAD'          => 'rgba(211,64,83,.8)',
    'ACUERDO_EXTRAJUDICIAL'   => 'rgba(139,153,245,.8)',
];

$torta_labels = [];
$torta_data   = [];
$torta_colors = [];
foreach ($motivos_raw as $m) {
    $key = $m['motivo_finalizacion'];
    $torta_labels[] = $motivos_labels[$key] ?? $key;
    $torta_data[]   = (int)$m['cant'];
    $torta_colors[] = $motivos_colores[$key] ?? 'rgba(148,163,184,.8)';
}

// ── Créditos morosos del cobrador (tabla) ────────────────────
$stmt = $pdo->prepare("
    SELECT cr.id AS credito_id,
           cl.nombres, cl.apellidos, cl.telefono,
           cr.monto_cuota,
           cr.frecuencia,
           COUNT(CASE WHEN cu.estado IN('VENCIDA','PARCIAL')
                       AND cu.fecha_vencimiento < CURDATE() THEN 1 END) AS cuotas_vencidas,
           MAX(DATEDIFF(CURDATE(), cu.fecha_vencimiento)) AS max_dias,
           (SELECT MAX(pt2.fecha_jornada) FROM ic_pagos_temporales pt2
            JOIN ic_cuotas cu2 ON pt2.cuota_id = cu2.id
            WHERE cu2.credito_id = cr.id
              AND pt2.estado IN ('PENDIENTE','APROBADO')) AS ultimo_cobro
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_cuotas cu ON cu.credito_id = cr.id
    WHERE cr.cobrador_id = ? AND cr.estado = 'MOROSO'
    GROUP BY cr.id, cl.nombres, cl.apellidos, cl.telefono, cr.monto_cuota, cr.frecuencia
    ORDER BY max_dias DESC
    LIMIT 50
");
$stmt->execute([$cobrador_id]);
$morosos_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── JSON para Chart.js ────────────────────────────────────────
$json_labels      = json_encode($chart_labels);
$json_cobrador    = json_encode($chart_cobrador);
$json_promedio    = json_encode($chart_promedio);
$json_torta_lbl   = json_encode($torta_labels);
$json_torta_data  = json_encode($torta_data);
$json_torta_col   = json_encode($torta_colors);

$nombre_cob = e($cobrador['nombre'] . ' ' . $cobrador['apellido']);

$page_title   = 'Detalle: ' . $cobrador['apellido'] . ', ' . $cobrador['nombre'];
$page_current = 'ranking_cobradores';
$topbar_actions = '<a href="ranking_cobradores?' . $qs . '" class="btn-ic btn-ghost btn-sm">
    <i class="fa fa-arrow-left"></i> Volver al Ranking
</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── HEADER DEL COBRADOR ───────────────────────────────────── -->
<div class="card-ic mb-4">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--primary);
                    display:flex;align-items:center;justify-content:center;
                    font-weight:900;font-size:1.3rem;flex-shrink:0">
            <?= strtoupper(mb_substr($cobrador['nombre'],0,1).mb_substr($cobrador['apellido'],0,1)) ?>
        </div>
        <div style="flex:1">
            <div style="font-size:1.25rem;font-weight:800">
                <?= $nombre_cob ?>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                Cobrador activo · <?= $cartera['total_activos'] ?> créditos en cartera
            </div>
        </div>
        <!-- Selector de período inline -->
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <a href="?id=<?= $cobrador_id ?>&desde=<?= $primer_dia_mes ?>&hasta=<?= $hoy ?>"
               class="btn-ic btn-sm <?= $periodo_activo === 'mes' ? 'btn-primary' : 'btn-ghost' ?>">Mes actual</a>
            <a href="?id=<?= $cobrador_id ?>&desde=<?= $mes_ant_ini ?>&hasta=<?= $mes_ant_fin ?>"
               class="btn-ic btn-sm <?= $periodo_activo === 'mes_ant' ? 'btn-primary' : 'btn-ghost' ?>">Mes anterior</a>
            <a href="?id=<?= $cobrador_id ?>&desde=<?= $trim_ini ?>&hasta=<?= $hoy ?>"
               class="btn-ic btn-sm <?= $periodo_activo === 'trimestre' ? 'btn-primary' : 'btn-ghost' ?>">Trimestre</a>
            <form method="GET" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="id" value="<?= $cobrador_id ?>">
                <input type="date" name="desde" value="<?= e($desde) ?>" max="<?= $hoy ?>" style="min-width:130px">
                <input type="date" name="hasta" value="<?= e($hasta) ?>" max="<?= $hoy ?>" style="min-width:130px">
                <button type="submit" class="btn-ic btn-ghost btn-sm"><i class="fa fa-search"></i></button>
            </form>
        </div>
    </div>
    <div style="margin-top:10px;font-size:.78rem;color:var(--text-muted)">
        Período: <strong><?= date('d/m/Y', strtotime($desde)) ?></strong>
        al <strong><?= date('d/m/Y', strtotime($hasta)) ?></strong>
    </div>
</div>

<!-- ── KPIs DE PRODUCCIÓN ────────────────────────────────────── -->
<div class="db-kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981">
            <i class="fa fa-sack-dollar"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Total Cobrado</div>
            <div class="kpi-value" style="font-size:1.1rem;color:var(--success)">
                <?= formato_pesos((float)$prod['total_cobrado']) ?>
            </div>
            <div class="kpi-sub">Mora: <?= formato_pesos((float)$prod['mora_cobrada']) ?></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(60,80,224,.15);--icon-color:#3C50E0">
            <i class="fa fa-receipt"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cantidad de Pagos</div>
            <div class="kpi-value"><?= number_format((int)$prod['cant_pagos']) ?></div>
            <div class="kpi-sub">Ticket prom.: <?= formato_pesos($ticket_promedio) ?></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(0,181,226,.15);--icon-color:#00B5E2">
            <i class="fa fa-chart-line"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Tasa Recuperación</div>
            <div class="kpi-value"
                 style="color:<?= $tasa_recup >= 80 ? 'var(--success)' : ($tasa_recup >= 50 ? 'var(--warning)' : 'var(--danger)') ?>">
                <?= $tasa_recup ?>%
            </div>
            <div class="kpi-sub"><?= $recup['cobradas'] ?> de <?= $recup['agendadas'] ?> cuotas</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(255,167,11,.15);--icon-color:#FFA70B">
            <i class="fa fa-money-bills"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Efectivo / Transf.</div>
            <div class="kpi-value" style="font-size:.95rem">
                <?= formato_pesos((float)$prod['efectivo']) ?>
            </div>
            <div class="kpi-sub">Transf.: <?= formato_pesos((float)$prod['transferencia']) ?></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(211,64,83,.15);--icon-color:#D34053">
            <i class="fa fa-triangle-exclamation"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">% Morosidad</div>
            <div class="kpi-value"
                 style="color:<?= $pct_morosidad > 20 ? 'var(--danger)' : ($pct_morosidad > 10 ? 'var(--warning)' : 'var(--success)') ?>">
                <?= $pct_morosidad ?>%
            </div>
            <div class="kpi-sub"><?= $cartera['morosos'] ?> de <?= $cartera['total_activos'] ?> créditos</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(33,150,83,.15);--icon-color:#219653">
            <i class="fa fa-circle-check"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Créditos Finalizados</div>
            <div class="kpi-value"><?= $finalizados ?></div>
            <div class="kpi-sub">en el período</div>
        </div>
    </div>
</div>

<!-- ── CALIDAD DE CARTERA (badges) ───────────────────────────── -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-shield-halved"></i> Calidad de Cartera Actual</span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:16px;padding:16px">
        <div style="text-align:center;min-width:100px">
            <div style="font-size:1.4rem;font-weight:800;color:var(--text)"><?= $cartera['cuotas_vencidas'] ?: '—' ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">Cuotas vencidas</div>
        </div>
        <div style="text-align:center;min-width:100px">
            <div style="font-size:1.4rem;font-weight:800;color:<?= ($cartera['dias_prom'] ?? 0) > 30 ? 'var(--danger)' : 'var(--warning)' ?>">
                <?= $cartera['dias_prom'] ?? '—' ?>
            </div>
            <div style="font-size:.75rem;color:var(--text-muted)">Días prom. atraso</div>
        </div>
        <div style="text-align:center;min-width:130px">
            <div style="font-size:1.2rem;font-weight:800;color:var(--danger)">
                <?= $cartera['monto_vencido'] > 0 ? formato_pesos((float)$cartera['monto_vencido']) : '—' ?>
            </div>
            <div style="font-size:.75rem;color:var(--text-muted)">Monto vencido total</div>
        </div>
        <div style="text-align:center;min-width:100px">
            <div style="font-size:1.4rem;font-weight:800;color:<?= $sin_visitar > 0 ? 'var(--danger)' : 'var(--success)' ?>">
                <?= $sin_visitar ?: '0' ?>
            </div>
            <div style="font-size:.75rem;color:var(--text-muted)">Sin visitar (30d)</div>
        </div>
    </div>
</div>

<!-- ── GRÁFICOS ───────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 320px;gap:16px;margin-bottom:16px" class="charts-row">

    <!-- Cobros diarios vs promedio equipo -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title">
                <i class="fa fa-chart-column"></i>
                Cobros <?= $agrupar_semanas ? 'por semana' : 'por día' ?> vs Promedio del Equipo
            </span>
        </div>
        <div style="padding:16px;position:relative;height:260px">
            <canvas id="chartCobros"></canvas>
        </div>
    </div>

    <!-- Torta de motivos -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-pie"></i> Motivos de Finalización</span>
        </div>
        <?php if (!empty($torta_data)): ?>
        <div style="padding:16px;position:relative;height:260px;display:flex;align-items:center;justify-content:center">
            <canvas id="chartMotivos"></canvas>
        </div>
        <?php else: ?>
        <div style="padding:30px;text-align:center;color:var(--text-muted);font-size:.85rem">
            <i class="fa fa-chart-pie fa-2x" style="opacity:.3;display:block;margin-bottom:8px"></i>
            Sin créditos finalizados
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── TABLA DE MOROSOS ───────────────────────────────────────── -->
<?php if (!empty($morosos_lista)): ?>
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title">
            <i class="fa fa-triangle-exclamation" style="color:var(--danger)"></i>
            Créditos Morosos
        </span>
        <span style="font-size:.78rem;color:var(--text-muted)">
            <?= count($morosos_lista) ?> crédito<?= count($morosos_lista) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic" style="min-width:600px">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th style="text-align:center">Cuotas vencidas</th>
                    <th style="text-align:center">Días máx.</th>
                    <th style="text-align:right">Cuota</th>
                    <th style="text-align:center">Frecuencia</th>
                    <th style="text-align:center">Último cobro</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($morosos_lista as $m):
                $col_dias = $m['max_dias'] > 60 ? 'var(--danger)' : ($m['max_dias'] > 30 ? 'var(--warning)' : 'var(--text-muted)');
                $sin_cobro = !$m['ultimo_cobro'] || $m['ultimo_cobro'] < date('Y-m-d', strtotime('-30 days'));
            ?>
            <tr>
                <td style="font-weight:600"><?= e($m['apellidos'] . ', ' . $m['nombres']) ?></td>
                <td style="text-align:center">
                    <span class="badge-ic <?= $m['cuotas_vencidas'] > 2 ? 'badge-danger' : 'badge-warning' ?>">
                        <?= $m['cuotas_vencidas'] ?>
                    </span>
                </td>
                <td style="text-align:center;font-weight:700;color:<?= $col_dias ?>">
                    <?= $m['max_dias'] ?> d.
                </td>
                <td style="text-align:right"><?= formato_pesos((float)$m['monto_cuota']) ?></td>
                <td style="text-align:center;font-size:.8rem;color:var(--text-muted)">
                    <?= ucfirst($m['frecuencia']) ?>
                </td>
                <td style="text-align:center;font-size:.82rem">
                    <?php if ($m['ultimo_cobro']): ?>
                        <span style="color:<?= $sin_cobro ? 'var(--danger)' : 'var(--text-muted)' ?>">
                            <?= date('d/m/Y', strtotime($m['ultimo_cobro'])) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge-ic badge-danger">Sin cobros</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>creditos/ver?id=<?= (int)$m['credito_id'] ?>"
                       class="btn-ic btn-ghost btn-sm" target="_blank">
                        <i class="fa fa-eye"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$page_scripts = <<<SCRIPTS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = 'rgba(255,255,255,.6)';
Chart.defaults.borderColor = 'rgba(255,255,255,.07)';
Chart.defaults.font.family = "'Sarabun', sans-serif";

// ── Gráfico de barras: cobros vs promedio ─────────────────────
(function() {
    const ctx = document.getElementById('chartCobros');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$json_labels},
            datasets: [
                {
                    label: '$nombre_cob',
                    data: {$json_cobrador},
                    backgroundColor: 'rgba(60,80,224,.7)',
                    borderColor: '#3C50E0',
                    borderWidth: 1,
                    borderRadius: 4,
                    order: 2
                },
                {
                    label: 'Promedio del equipo',
                    data: {$json_promedio},
                    type: 'line',
                    borderColor: 'rgba(245,158,11,.85)',
                    backgroundColor: 'rgba(245,158,11,.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.3,
                    fill: false,
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { padding: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': $' +
                            Number(ctx.raw).toLocaleString('es-AR', { maximumFractionDigits: 0 })
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { font: { size: 10 }, maxRotation: 45 } },
                y: {
                    grid: { color: 'rgba(255,255,255,.05)' },
                    ticks: {
                        font: { size: 10 },
                        callback: v => '$' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 })
                    }
                }
            }
        }
    });
})();

// ── Torta de motivos ─────────────────────────────────────────
(function() {
    const ctx = document.getElementById('chartMotivos');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: {$json_torta_lbl},
            datasets: [{
                data: {$json_torta_data},
                backgroundColor: {$json_torta_col},
                borderColor: 'rgba(0,0,0,.3)',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 10, font: { size: 11 }, boxWidth: 12 } }
            }
        }
    });
})();
</script>
<style>
@media(max-width:900px){
    .charts-row { grid-template-columns:1fr !important; }
}
</style>
SCRIPTS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
