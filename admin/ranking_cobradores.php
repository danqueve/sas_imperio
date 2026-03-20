<?php
// ============================================================
// admin/ranking_cobradores.php — Ranking mensual de cobradores
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Período seleccionado ──────────────────────────────────────
$hoy       = date('Y-m-d');
$primer_dia_mes = date('Y-m-01');

$desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
    ? $_GET['desde'] : $primer_dia_mes;
$hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
    ? $_GET['hasta'] : $hoy;

if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

// Detectar período activo para resaltar botón
$mes_ant_ini = date('Y-m-01', strtotime('first day of last month'));
$mes_ant_fin = date('Y-m-t', strtotime('first day of last month'));
$trim_ini    = date('Y-m-01', strtotime('-2 months'));

$periodo_activo = 'custom';
if ($desde === $primer_dia_mes && $hasta === $hoy) $periodo_activo = 'mes';
elseif ($desde === $mes_ant_ini && $hasta === $mes_ant_fin) $periodo_activo = 'mes_ant';
elseif ($desde === $trim_ini    && $hasta === $hoy) $periodo_activo = 'trimestre';

// ── Query 1: Cobradores activos ───────────────────────────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios
     WHERE rol = 'cobrador' AND activo = 1
     ORDER BY apellido, nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$ids  = array_column($cobradores, 'id');
$data = [];
foreach ($cobradores as $c) {
    $data[$c['id']] = [
        'nombre'         => $c['nombre'],
        'apellido'       => $c['apellido'],
        'cant_pagos'     => 0,
        'total_cobrado'  => 0.0,
        'mora_cobrada'   => 0.0,
        'total_activos'  => 0,
        'morosos'        => 0,
        'cuotas_vencidas'=> 0,
        'dias_prom'      => 0,
        'monto_vencido'  => 0.0,
        'agendadas'      => 0,
        'cobradas'       => 0,
        'finalizados'    => 0,
        'sin_visitar'    => 0,
    ];
}

if (!empty($ids)) {
    // ── Query A: Producción ───────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT pc.cobrador_id,
               COUNT(*)                    AS cant_pagos,
               SUM(pc.monto_total)         AS total_cobrado,
               SUM(pc.monto_mora_cobrada)  AS mora_cobrada
        FROM ic_pagos_confirmados pc
        WHERE pc.fecha_pago BETWEEN ? AND ?
        GROUP BY pc.cobrador_id
    ");
    $stmt->execute([$desde, $hasta]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['cobrador_id'];
        if (!isset($data[$cid])) continue;
        $data[$cid]['cant_pagos']    = (int)$r['cant_pagos'];
        $data[$cid]['total_cobrado'] = (float)$r['total_cobrado'];
        $data[$cid]['mora_cobrada']  = (float)$r['mora_cobrada'];
    }

    // ── Query B: Calidad de cartera actual ────────────────────
    $stmt = $pdo->query("
        SELECT cr.cobrador_id,
               COUNT(DISTINCT cr.id) AS total_activos,
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
        WHERE cr.estado IN ('EN_CURSO','MOROSO')
        GROUP BY cr.cobrador_id
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['cobrador_id'];
        if (!isset($data[$cid])) continue;
        $data[$cid]['total_activos']   = (int)$r['total_activos'];
        $data[$cid]['morosos']         = (int)$r['morosos'];
        $data[$cid]['cuotas_vencidas'] = (int)$r['cuotas_vencidas'];
        $data[$cid]['dias_prom']       = (int)($r['dias_prom'] ?? 0);
        $data[$cid]['monto_vencido']   = (float)$r['monto_vencido'];
    }

    // ── Query C: Tasa de recuperación en el período ───────────
    $stmt = $pdo->prepare("
        SELECT cr.cobrador_id,
               COUNT(*) AS agendadas,
               SUM(CASE WHEN cu.estado = 'PAGADA' THEN 1 ELSE 0 END) AS cobradas
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cu.fecha_vencimiento BETWEEN ? AND ?
        GROUP BY cr.cobrador_id
    ");
    $stmt->execute([$desde, $hasta]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['cobrador_id'];
        if (!isset($data[$cid])) continue;
        $data[$cid]['agendadas'] = (int)$r['agendadas'];
        $data[$cid]['cobradas']  = (int)$r['cobradas'];
    }

    // ── Query D: Créditos finalizados en el período ───────────
    $stmt = $pdo->prepare("
        SELECT cobrador_id, COUNT(*) AS finalizados
        FROM ic_creditos
        WHERE estado = 'FINALIZADO' AND fecha_finalizacion BETWEEN ? AND ?
        GROUP BY cobrador_id
    ");
    $stmt->execute([$desde, $hasta]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['cobrador_id'];
        if (!isset($data[$cid])) continue;
        $data[$cid]['finalizados'] = (int)$r['finalizados'];
    }

    // ── Query E: Morosos sin visita en 30 días ────────────────
    $stmt = $pdo->query("
        SELECT cr.cobrador_id, COUNT(DISTINCT cr.id) AS sin_visitar
        FROM ic_creditos cr
        WHERE cr.estado = 'MOROSO'
          AND cr.id NOT IN (
              SELECT DISTINCT cu2.credito_id
              FROM ic_pagos_temporales pt
              JOIN ic_cuotas cu2 ON pt.cuota_id = cu2.id
              WHERE pt.fecha_jornada >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                AND pt.estado IN ('PENDIENTE','APROBADO')
          )
        GROUP BY cr.cobrador_id
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['cobrador_id'];
        if (!isset($data[$cid])) continue;
        $data[$cid]['sin_visitar'] = (int)$r['sin_visitar'];
    }
}

// ── Calcular métricas derivadas ───────────────────────────────
foreach ($data as $cid => &$d) {
    $d['tasa_recuperacion'] = $d['agendadas'] > 0
        ? round($d['cobradas'] / $d['agendadas'] * 100) : 0;
    $d['pct_morosidad'] = $d['total_activos'] > 0
        ? round($d['morosos'] / $d['total_activos'] * 100, 1) : 0;
    $d['ticket_promedio'] = $d['cant_pagos'] > 0
        ? $d['total_cobrado'] / $d['cant_pagos'] : 0;
}
unset($d);

// ── Ordenar por total cobrado DESC ────────────────────────────
uasort($data, fn($a, $b) => $b['total_cobrado'] <=> $a['total_cobrado']);

// ── KPIs globales ─────────────────────────────────────────────
$g_cobrado    = array_sum(array_column($data, 'total_cobrado'));
$g_agendadas  = array_sum(array_column($data, 'agendadas'));
$g_cobradas   = array_sum(array_column($data, 'cobradas'));
$g_activos    = array_sum(array_column($data, 'total_activos'));
$g_morosos    = array_sum(array_column($data, 'morosos'));
$g_tasa       = $g_agendadas > 0 ? round($g_cobradas / $g_agendadas * 100) : 0;
$g_pct_mora   = $g_activos   > 0 ? round($g_morosos  / $g_activos  * 100, 1) : 0;
$max_cobrado  = count($data) ? max(array_column($data, 'total_cobrado')) : 1;
if ($max_cobrado == 0) $max_cobrado = 1;

$page_title     = 'Ranking de Cobradores';
$page_current   = 'ranking_cobradores';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── FILTRO DE PERÍODO ─────────────────────────────────────── -->
<div class="card-ic mb-4">
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px">
        <span style="font-size:.8rem;color:var(--text-muted);font-weight:600;white-space:nowrap">
            <i class="fa fa-calendar-range"></i> Período:
        </span>
        <a href="?desde=<?= $primer_dia_mes ?>&hasta=<?= $hoy ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes' ? 'btn-primary' : 'btn-ghost' ?>">
            Mes actual
        </a>
        <a href="?desde=<?= $mes_ant_ini ?>&hasta=<?= $mes_ant_fin ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes_ant' ? 'btn-primary' : 'btn-ghost' ?>">
            Mes anterior
        </a>
        <a href="?desde=<?= $trim_ini ?>&hasta=<?= $hoy ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'trimestre' ? 'btn-primary' : 'btn-ghost' ?>">
            Último trimestre
        </a>
        <form method="GET" style="display:flex;gap:8px;align-items:center;margin-left:auto;flex-wrap:wrap">
            <input type="date" name="desde" value="<?= e($desde) ?>"
                   max="<?= $hoy ?>" style="min-width:140px">
            <span style="color:var(--text-muted);font-size:.8rem">hasta</span>
            <input type="date" name="hasta" value="<?= e($hasta) ?>"
                   max="<?= $hoy ?>" style="min-width:140px">
            <button type="submit" class="btn-ic btn-ghost btn-sm">
                <i class="fa fa-search"></i> Filtrar
            </button>
        </form>
    </div>
    <div style="margin-top:8px;font-size:.78rem;color:var(--text-muted)">
        Mostrando datos del <strong><?= date('d/m/Y', strtotime($desde)) ?></strong>
        al <strong><?= date('d/m/Y', strtotime($hasta)) ?></strong>
    </div>
</div>

<!-- ── KPIs GLOBALES ─────────────────────────────────────────── -->
<div class="db-kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981">
            <i class="fa fa-sack-dollar"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Total Cobrado (equipo)</div>
            <div class="kpi-value" style="font-size:1.1rem"><?= formato_pesos($g_cobrado) ?></div>
            <div class="kpi-sub"><?= count($cobradores) ?> cobradores activos</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(60,80,224,.15);--icon-color:#3C50E0">
            <i class="fa fa-chart-line"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Tasa de Recuperación</div>
            <div class="kpi-value" style="color:<?= $g_tasa >= 80 ? 'var(--success)' : ($g_tasa >= 50 ? 'var(--warning)' : 'var(--danger)') ?>">
                <?= $g_tasa ?>%
            </div>
            <div class="kpi-sub"><?= number_format($g_cobradas) ?> de <?= number_format($g_agendadas) ?> cuotas</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(211,64,83,.15);--icon-color:#D34053">
            <i class="fa fa-triangle-exclamation"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Morosidad Global</div>
            <div class="kpi-value" style="color:<?= $g_pct_mora > 20 ? 'var(--danger)' : ($g_pct_mora > 10 ? 'var(--warning)' : 'var(--success)') ?>">
                <?= $g_pct_mora ?>%
            </div>
            <div class="kpi-sub"><?= $g_morosos ?> morosos de <?= $g_activos ?> activos</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(0,181,226,.15);--icon-color:#00B5E2">
            <i class="fa fa-circle-check"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Créditos Finalizados</div>
            <div class="kpi-value"><?= array_sum(array_column($data, 'finalizados')) ?></div>
            <div class="kpi-sub">en el período seleccionado</div>
        </div>
    </div>
</div>

<?php
// Top 3 para el podio
$top = array_slice($data, 0, 3, true);
$top_ids = array_keys($top);
$medallas = [
    0 => ['color' => '#F59E0B', 'label' => '1°', 'sombra' => 'rgba(245,158,11,.35)', 'size' => '110px'],
    1 => ['color' => '#94A3B8', 'label' => '2°', 'sombra' => 'rgba(148,163,184,.25)', 'size' => '90px'],
    2 => ['color' => '#CD7F32', 'label' => '3°', 'sombra' => 'rgba(205,127,50,.25)', 'size' => '85px'],
];
?>

<!-- ── PODIO TOP 3 ─────────────────────────────────────────── -->
<?php if (count($top) >= 2): ?>
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-trophy" style="color:#F59E0B"></i> Podio del Período</span>
    </div>
    <div style="display:flex;justify-content:center;align-items:flex-end;gap:20px;padding:24px 16px 20px;flex-wrap:wrap">
        <?php
        // Reordenar visualmente: 2° - 1° - 3°
        $orden_visual = array_filter([
            isset($top_ids[1]) ? $top_ids[1] : null,
            isset($top_ids[0]) ? $top_ids[0] : null,
            isset($top_ids[2]) ? $top_ids[2] : null,
        ], fn($v) => $v !== null);
        $pos_visual = [
            $top_ids[0] => 0,
            isset($top_ids[1]) ? $top_ids[1] : -1 => 1,
            isset($top_ids[2]) ? $top_ids[2] : -1 => 2,
        ];
        foreach ($orden_visual as $cid):
            $d  = $data[$cid];
            $mi = $pos_visual[$cid] ?? 0;
            $m  = $medallas[$mi];
        ?>
        <div style="text-align:center;flex:1;min-width:120px;max-width:200px">
            <div style="font-size:1.4rem;font-weight:900;color:<?= $m['color'] ?>;margin-bottom:6px;
                        text-shadow:0 0 12px <?= $m['sombra'] ?>"><?= $m['label'] ?></div>
            <div style="width:<?= $m['size'] ?>;height:<?= $m['size'] ?>;border-radius:50%;
                        background:linear-gradient(135deg,<?= $m['color'] ?>22,<?= $m['color'] ?>55);
                        border:3px solid <?= $m['color'] ?>;display:flex;align-items:center;
                        justify-content:center;font-size:1.6rem;font-weight:900;
                        color:<?= $m['color'] ?>;margin:0 auto 10px;
                        box-shadow:0 0 18px <?= $m['sombra'] ?>">
                <?= strtoupper(mb_substr($d['nombre'],0,1).mb_substr($d['apellido'],0,1)) ?>
            </div>
            <div style="font-weight:700;font-size:.9rem;margin-bottom:3px">
                <?= e($d['apellido'].', '.$d['nombre']) ?>
            </div>
            <div style="font-size:1.05rem;font-weight:800;color:var(--success)">
                <?= formato_pesos($d['total_cobrado']) ?>
            </div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:3px">
                <?= $d['cant_pagos'] ?> pagos · <?= $d['tasa_recuperacion'] ?>% recuperación
            </div>
            <a href="cobrador_detalle?id=<?= $cid ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
               class="btn-ic btn-ghost btn-sm" style="margin-top:8px;font-size:.72rem">
                Ver detalle
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── TABLA DE RANKING COMPLETO ─────────────────────────────── -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-list-ol"></i> Ranking Completo</span>
        <span style="font-size:.78rem;color:var(--text-muted)">Ordenado por monto cobrado</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic" style="min-width:720px">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Cobrador</th>
                    <th style="min-width:180px">Cobrado en el período</th>
                    <th style="text-align:center">Pagos</th>
                    <th style="text-align:center">Recuperación</th>
                    <th style="text-align:center">% Mora</th>
                    <th style="text-align:center">Sin visitar</th>
                    <th style="text-align:center">Finalizados</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php $pos = 0; foreach ($data as $cid => $d): $pos++; ?>
            <?php
                $pct_barra = $max_cobrado > 0 ? min(100, round($d['total_cobrado'] / $max_cobrado * 100)) : 0;
                $color_tasa = $d['tasa_recuperacion'] >= 80 ? 'var(--success)' : ($d['tasa_recuperacion'] >= 50 ? 'var(--warning)' : 'var(--danger)');
                $color_mora = $d['pct_morosidad'] > 20 ? 'var(--danger)' : ($d['pct_morosidad'] > 10 ? 'var(--warning)' : 'var(--success)');
                $sin_actividad = ($d['total_cobrado'] == 0 && $d['cant_pagos'] == 0);
            ?>
            <tr style="<?= $sin_actividad ? 'opacity:.5' : '' ?>">
                <td style="font-weight:800;color:<?= $pos <= 3 ? ['','#F59E0B','#94A3B8','#CD7F32'][$pos] : 'var(--text-muted)' ?>;text-align:center">
                    <?= $pos ?>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);
                                    display:flex;align-items:center;justify-content:center;
                                    font-weight:800;font-size:.8rem;flex-shrink:0">
                            <?= strtoupper(mb_substr($d['nombre'],0,1).mb_substr($d['apellido'],0,1)) ?>
                        </div>
                        <span style="font-weight:600"><?= e($d['apellido'].', '.$d['nombre']) ?></span>
                    </div>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div style="flex:1;min-width:80px;background:rgba(255,255,255,.08);
                                    border-radius:99px;height:8px;overflow:hidden">
                            <div style="width:<?= $pct_barra ?>%;height:100%;
                                        background:linear-gradient(90deg,var(--primary),var(--primary-light));
                                        border-radius:99px;transition:width .4s"></div>
                        </div>
                        <span style="font-weight:700;color:var(--success);font-size:.88rem;min-width:80px;text-align:right">
                            <?= $d['total_cobrado'] > 0 ? formato_pesos($d['total_cobrado']) : '—' ?>
                        </span>
                    </div>
                </td>
                <td style="text-align:center;font-weight:700">
                    <?= $d['cant_pagos'] ?: '—' ?>
                </td>
                <td style="text-align:center">
                    <?php if ($d['agendadas'] > 0): ?>
                        <span style="font-weight:700;color:<?= $color_tasa ?>">
                            <?= $d['tasa_recuperacion'] ?>%
                        </span>
                        <div style="font-size:.72rem;color:var(--text-muted)">
                            <?= $d['cobradas'] ?>/<?= $d['agendadas'] ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center">
                    <?php if ($d['total_activos'] > 0): ?>
                        <span style="font-weight:700;color:<?= $color_mora ?>">
                            <?= $d['pct_morosidad'] ?>%
                        </span>
                        <div style="font-size:.72rem;color:var(--text-muted)">
                            <?= $d['morosos'] ?>/<?= $d['total_activos'] ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center">
                    <?php if ($d['sin_visitar'] > 0): ?>
                        <span class="badge-ic badge-danger"><?= $d['sin_visitar'] ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center">
                    <?php if ($d['finalizados'] > 0): ?>
                        <span class="badge-ic badge-success"><?= $d['finalizados'] ?></span>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <a href="cobrador_detalle?id=<?= $cid ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
                       class="btn-ic btn-ghost btn-sm" title="Ver ficha completa">
                        <i class="fa fa-chart-bar"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── CALIDAD DE CARTERA ──────────────────────────────────────  -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-shield-halved"></i> Calidad de Cartera Actual</span>
        <span style="font-size:.75rem;color:var(--text-muted)">
            <i class="fa fa-info-circle"></i> Estado en tiempo real (independiente del período)
        </span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:12px;padding:16px">
        <?php foreach ($data as $cid => $d):
            if ($d['total_activos'] == 0) continue;
            $pct_mora_c = $d['pct_morosidad'];
            $col = $pct_mora_c > 20 ? '#D34053' : ($pct_mora_c > 10 ? '#FFA70B' : '#219653');
        ?>
        <div style="flex:1;min-width:200px;max-width:260px;background:rgba(0,0,0,.2);
                    border-radius:10px;padding:14px 16px;
                    border-left:4px solid <?= $col ?>;
                    border:1px solid rgba(255,255,255,.07);border-left-width:4px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <span style="font-weight:700;font-size:.9rem">
                    <?= e($d['apellido'].', '.$d['nombre']) ?>
                </span>
                <span style="font-size:1rem;font-weight:800;color:<?= $col ?>">
                    <?= $pct_mora_c ?>%
                </span>
            </div>
            <div style="background:rgba(255,255,255,.08);border-radius:99px;height:5px;overflow:hidden;margin-bottom:10px">
                <div style="width:<?= min(100,$pct_mora_c*2) ?>%;height:100%;background:<?= $col ?>;border-radius:99px"></div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:.75rem;color:var(--text-muted)">
                <span><i class="fa fa-file-invoice"></i> <?= $d['total_activos'] ?> créditos</span>
                <?php if ($d['morosos'] > 0): ?>
                    <span style="color:var(--danger)"><i class="fa fa-triangle-exclamation"></i> <?= $d['morosos'] ?> morosos</span>
                <?php endif; ?>
                <?php if ($d['cuotas_vencidas'] > 0): ?>
                    <span style="color:var(--warning)"><i class="fa fa-clock"></i> <?= $d['cuotas_vencidas'] ?> cuotas vencidas</span>
                <?php endif; ?>
                <?php if ($d['dias_prom'] > 0): ?>
                    <span><i class="fa fa-calendar-xmark"></i> <?= $d['dias_prom'] ?> días prom.</span>
                <?php endif; ?>
                <?php if ($d['monto_vencido'] > 0): ?>
                    <span style="color:var(--danger);font-weight:600">
                        <i class="fa fa-dollar-sign"></i> <?= formato_pesos($d['monto_vencido']) ?> vencido
                    </span>
                <?php endif; ?>
            </div>
            <a href="cobrador_detalle?id=<?= $cid ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
               style="display:block;text-align:right;font-size:.72rem;color:var(--primary-light);
                      margin-top:8px;text-decoration:none">
                Ver detalle <i class="fa fa-arrow-right"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
$page_scripts = '';
require_once __DIR__ . '/../views/layout_footer.php';
?>
