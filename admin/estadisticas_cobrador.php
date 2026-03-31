<?php
// ============================================================
// admin/estadisticas_cobrador.php — Cobrador vs promedio equipo
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_estadisticas');

$pdo = obtener_conexion();

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

// ── Cobrador seleccionado ─────────────────────────────────────
$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);

// ── Lista de cobradores ───────────────────────────────────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios
     WHERE rol = 'cobrador' AND activo = 1
     ORDER BY apellido, nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Helper: obtener métricas para un conjunto de cobradores ──
function obtener_metricas(PDO $pdo, array $ids, string $desde, string $hasta, bool $es_promedio = false): array
{
    if (empty($ids)) {
        return metricas_vacias();
    }

    // A) Producción
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params_prod  = array_merge($ids, [$desde, $hasta]);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cant_pagos,
               COALESCE(SUM(monto_total),0) AS total_cobrado,
               COALESCE(SUM(monto_mora_cobrada),0) AS mora_cobrada
        FROM ic_pagos_confirmados
        WHERE cobrador_id IN ($placeholders) AND fecha_pago BETWEEN ? AND ?
    ");
    $stmt->execute($params_prod);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);

    // B) Cartera
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
        WHERE cr.cobrador_id IN ($placeholders) AND cr.estado IN ('EN_CURSO','MOROSO')
    ");
    $stmt->execute($ids);
    $cartera = $stmt->fetch(PDO::FETCH_ASSOC);

    // C) Tasa recuperación
    $params_recup = array_merge($ids, [$desde, $hasta]);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS agendadas,
               SUM(CASE WHEN cu.estado='PAGADA' THEN 1 ELSE 0 END) AS cobradas
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cr.cobrador_id IN ($placeholders) AND cu.fecha_vencimiento BETWEEN ? AND ?
    ");
    $stmt->execute($params_recup);
    $recup = $stmt->fetch(PDO::FETCH_ASSOC);

    // D) Finalizados
    $params_fin = array_merge($ids, [$desde, $hasta]);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS finalizados
        FROM ic_creditos
        WHERE cobrador_id IN ($placeholders)
          AND estado='FINALIZADO' AND fecha_finalizacion BETWEEN ? AND ?
    ");
    $stmt->execute($params_fin);
    $fin = (int)$stmt->fetchColumn();

    // E) Sin visitar
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT cr.id) AS sin_visitar
        FROM ic_creditos cr
        WHERE cr.cobrador_id IN ($placeholders) AND cr.estado = 'MOROSO'
          AND cr.id NOT IN (
              SELECT DISTINCT cu2.credito_id
              FROM ic_pagos_temporales pt
              JOIN ic_cuotas cu2 ON pt.cuota_id = cu2.id
              WHERE pt.fecha_jornada >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                AND pt.estado IN ('PENDIENTE','APROBADO')
          )
    ");
    $stmt->execute($ids);
    $sin_vis = (int)$stmt->fetchColumn();

    $cant_cobr = $es_promedio ? count($ids) : 1;

    $total_cobrado  = (float)$prod['total_cobrado'];
    $cant_pagos     = (int)$prod['cant_pagos'];
    $mora_cobrada   = (float)$prod['mora_cobrada'];
    $total_activos  = (int)$cartera['total_activos'];
    $morosos        = (int)$cartera['morosos'];
    $cuotas_venc    = (int)$cartera['cuotas_vencidas'];
    $dias_prom      = (int)($cartera['dias_prom'] ?? 0);
    $monto_vencido  = (float)$cartera['monto_vencido'];
    $agendadas      = (int)$recup['agendadas'];
    $cobradas_r     = (int)$recup['cobradas'];

    return [
        'total_cobrado'     => $es_promedio && $cant_cobr > 1 ? round($total_cobrado / $cant_cobr, 2) : $total_cobrado,
        'cant_pagos'        => $es_promedio && $cant_cobr > 1 ? round($cant_pagos / $cant_cobr, 1) : $cant_pagos,
        'mora_cobrada'      => $es_promedio && $cant_cobr > 1 ? round($mora_cobrada / $cant_cobr, 2) : $mora_cobrada,
        'ticket_promedio'   => $cant_pagos > 0 ? round($total_cobrado / $cant_pagos, 2) : 0,
        'total_activos'     => $es_promedio && $cant_cobr > 1 ? round($total_activos / $cant_cobr, 1) : $total_activos,
        'morosos'           => $es_promedio && $cant_cobr > 1 ? round($morosos / $cant_cobr, 1) : $morosos,
        'pct_morosidad'     => $total_activos > 0 ? round($morosos / $total_activos * 100, 1) : 0,
        'cuotas_vencidas'   => $es_promedio && $cant_cobr > 1 ? round($cuotas_venc / $cant_cobr, 1) : $cuotas_venc,
        'dias_prom'         => $dias_prom,
        'monto_vencido'     => $es_promedio && $cant_cobr > 1 ? round($monto_vencido / $cant_cobr, 2) : $monto_vencido,
        'tasa_recuperacion' => $agendadas > 0 ? round($cobradas_r / $agendadas * 100) : 0,
        'finalizados'       => $es_promedio && $cant_cobr > 1 ? round($fin / $cant_cobr, 1) : $fin,
        'sin_visitar'       => $es_promedio && $cant_cobr > 1 ? round($sin_vis / $cant_cobr, 1) : $sin_vis,
    ];
}

function metricas_vacias(): array
{
    return [
        'total_cobrado' => 0, 'cant_pagos' => 0, 'mora_cobrada' => 0,
        'ticket_promedio' => 0, 'total_activos' => 0, 'morosos' => 0,
        'pct_morosidad' => 0, 'cuotas_vencidas' => 0, 'dias_prom' => 0,
        'monto_vencido' => 0, 'tasa_recuperacion' => 0, 'finalizados' => 0,
        'sin_visitar' => 0,
    ];
}

// ── Calcular métricas ─────────────────────────────────────────
$met_cob  = null;
$met_prom = null;
$nombre_cob = '';

if ($cobrador_id > 0) {
    $cobrador_info = null;
    foreach ($cobradores as $c) {
        if ((int)$c['id'] === $cobrador_id) { $cobrador_info = $c; break; }
    }

    if ($cobrador_info) {
        $nombre_cob  = e($cobrador_info['apellido'] . ', ' . $cobrador_info['nombre']);
        $met_cob     = obtener_metricas($pdo, [$cobrador_id], $desde, $hasta_ext, false);

        // Promedio del resto del equipo
        $ids_resto = array_map('intval', array_column(
            array_filter($cobradores, fn($c) => (int)$c['id'] !== $cobrador_id),
            'id'
        ));
        $met_prom = obtener_metricas($pdo, $ids_resto, $desde, $hasta_ext, true);
    }
}

// ── Definición de métricas para la tabla comparativa ─────────
$metricas_def = [
    ['key' => 'total_cobrado',     'label' => 'Total Cobrado',        'format' => 'pesos',   'higher_is_better' => true],
    ['key' => 'cant_pagos',        'label' => 'Cantidad de Pagos',    'format' => 'numero',  'higher_is_better' => true],
    ['key' => 'ticket_promedio',   'label' => 'Ticket Promedio',      'format' => 'pesos',   'higher_is_better' => true],
    ['key' => 'mora_cobrada',      'label' => 'Mora Cobrada',         'format' => 'pesos',   'higher_is_better' => true],
    ['key' => 'tasa_recuperacion', 'label' => 'Tasa Recuperación',    'format' => 'pct',     'higher_is_better' => true],
    ['key' => 'total_activos',     'label' => 'Créditos en Cartera',  'format' => 'numero',  'higher_is_better' => null],
    ['key' => 'pct_morosidad',     'label' => '% Morosidad',          'format' => 'pct',     'higher_is_better' => false],
    ['key' => 'cuotas_vencidas',   'label' => 'Cuotas Vencidas',      'format' => 'numero',  'higher_is_better' => false],
    ['key' => 'dias_prom',         'label' => 'Días Prom. Atraso',    'format' => 'numero',  'higher_is_better' => false],
    ['key' => 'monto_vencido',     'label' => 'Monto Vencido',        'format' => 'pesos',   'higher_is_better' => false],
    ['key' => 'finalizados',       'label' => 'Créditos Finalizados', 'format' => 'numero',  'higher_is_better' => true],
    ['key' => 'sin_visitar',       'label' => 'Sin Visitar (30d)',     'format' => 'numero',  'higher_is_better' => false],
];

function fmt_metrica(float $val, string $format): string
{
    return match($format) {
        'pesos'  => formato_pesos($val),
        'pct'    => number_format($val, 1) . '%',
        default  => number_format($val, 0, ',', '.'),
    };
}

$qs = http_build_query(['cobrador_id' => $cobrador_id, 'desde' => $desde, 'hasta' => $hasta]);

$page_title   = 'Estadísticas por Cobrador';
$page_current = 'estadisticas_cob';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── FILTROS ───────────────────────────────────────────────── -->
<div class="card-ic mb-4">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div style="flex:1;min-width:200px">
            <label style="display:block;font-size:.75rem;color:var(--text-muted);margin-bottom:5px;font-weight:600">
                <i class="fa fa-user-tie"></i> Cobrador
            </label>
            <select name="cobrador_id" style="width:100%">
                <option value="">— Seleccionar cobrador —</option>
                <?php foreach ($cobradores as $c): ?>
                    <option value="<?= $c['id'] ?>"
                            <?= $cobrador_id === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['apellido'] . ', ' . $c['nombre']) ?>
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
                <i class="fa fa-search"></i> Ver estadísticas
            </button>
        </div>
    </form>
    <!-- Accesos rápidos de período -->
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

<?php if ($cobrador_id <= 0 || !$met_cob): ?>
<!-- ── PANTALLA INICIAL: LISTA DE COBRADORES ─────────────────── -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-users"></i> Seleccioná un cobrador para comparar</span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;padding:16px">
        <?php foreach ($cobradores as $c): ?>
        <a href="?cobrador_id=<?= $c['id'] ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
           style="flex:1;min-width:180px;max-width:240px;text-decoration:none;
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
                    Ver estadísticas <i class="fa fa-arrow-right"></i>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <div style="padding:0 16px 16px;text-align:center">
        <a href="ranking_cobradores?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
           class="btn-ic btn-ghost btn-sm">
            <i class="fa fa-trophy"></i> Ver ranking completo
        </a>
    </div>
</div>

<?php else: ?>
<!-- ── VISTA COMPARATIVA ──────────────────────────────────────── -->

<!-- Header del cobrador -->
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
            vs. promedio del resto del equipo ·
            <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
        </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <a href="cobrador_detalle?id=<?= $cobrador_id ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
           class="btn-ic btn-ghost btn-sm">
            <i class="fa fa-chart-bar"></i> Ver ficha completa
        </a>
        <a href="ranking_cobradores?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
           class="btn-ic btn-ghost btn-sm">
            <i class="fa fa-trophy"></i> Ranking
        </a>
    </div>
</div>

<!-- KPIs side by side (top 4) -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px" class="kpi-side-grid">
    <?php
    $top_metricas = ['total_cobrado','tasa_recuperacion','pct_morosidad','finalizados'];
    $top_icons    = ['fa-sack-dollar','fa-chart-line','fa-triangle-exclamation','fa-circle-check'];
    $top_colors   = ['success','primary','danger','success'];
    foreach ($top_metricas as $i => $key):
        $def = null;
        foreach ($metricas_def as $md) { if ($md['key'] === $key) { $def = $md; break; } }
        if (!$def) continue;
        $val_c = $met_cob[$key];
        $val_p = $met_prom[$key];
        $diff  = $val_c - $val_p;
        $mejor = $def['higher_is_better'];
        $color_diff = 'var(--text-muted)';
        if ($mejor === true)  $color_diff = $diff >= 0 ? 'var(--success)' : 'var(--danger)';
        if ($mejor === false) $color_diff = $diff <= 0 ? 'var(--success)' : 'var(--danger)';
        $icon  = $top_icons[$i];
        $col   = $top_colors[$i];
        $color_kpi = "var(--$col)";
    ?>
    <div class="card-ic" style="padding:14px 18px">
        <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="flex:1">
                <div style="font-size:.75rem;color:var(--text-muted);font-weight:600;margin-bottom:4px">
                    <?= $def['label'] ?>
                </div>
                <!-- Cobrador -->
                <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:6px">
                    <span style="font-size:1.2rem;font-weight:800;color:<?= $color_kpi ?>">
                        <?= fmt_metrica($val_c, $def['format']) ?>
                    </span>
                    <span style="font-size:.75rem;color:var(--text-muted)"><?= $nombre_cob ?></span>
                </div>
                <!-- Promedio -->
                <div style="display:flex;align-items:baseline;gap:8px;padding:6px 8px;
                            background:rgba(0,0,0,.15);border-radius:6px">
                    <span style="font-size:.95rem;font-weight:600;color:var(--text-muted)">
                        <?= fmt_metrica($val_p, $def['format']) ?>
                    </span>
                    <span style="font-size:.72rem;color:var(--text-muted)">prom. equipo</span>
                    <?php if ($diff != 0): ?>
                    <span style="font-size:.75rem;font-weight:700;color:<?= $color_diff ?>;margin-left:auto">
                        <?= $diff > 0 ? '+' : '' ?><?= fmt_metrica(abs($diff), $def['format']) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="width:36px;height:36px;border-radius:8px;
                        background:rgba(255,255,255,.06);display:flex;align-items:center;
                        justify-content:center;flex-shrink:0">
                <i class="fa <?= $icon ?>" style="color:<?= $color_kpi ?>"></i>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tabla comparativa completa -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-table-columns"></i> Comparación detallada</span>
        <span style="font-size:.75rem;color:var(--text-muted)">
            Verde = supera al promedio · Rojo = por debajo del promedio
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic" style="min-width:520px">
            <thead>
                <tr>
                    <th>Métrica</th>
                    <th style="text-align:right"><?= $nombre_cob ?></th>
                    <th style="text-align:right">Prom. equipo</th>
                    <th style="text-align:center">Diferencia</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($metricas_def as $def):
                $val_c = $met_cob[$def['key']];
                $val_p = $met_prom[$def['key']];
                $diff  = $val_c - $val_p;
                $mejor = $def['higher_is_better'];
                $color_diff = 'var(--text-muted)';
                if ($mejor === true  && $diff != 0) $color_diff = $diff > 0 ? 'var(--success)' : 'var(--danger)';
                if ($mejor === false && $diff != 0) $color_diff = $diff < 0 ? 'var(--success)' : 'var(--danger)';
                $icono_diff = '';
                if ($diff > 0) $icono_diff = '<i class="fa fa-arrow-up" style="font-size:.7rem"></i>';
                if ($diff < 0) $icono_diff = '<i class="fa fa-arrow-down" style="font-size:.7rem"></i>';
            ?>
            <tr>
                <td style="font-weight:600;font-size:.85rem"><?= $def['label'] ?></td>
                <td style="text-align:right;font-weight:700">
                    <?= fmt_metrica($val_c, $def['format']) ?>
                </td>
                <td style="text-align:right;color:var(--text-muted)">
                    <?= fmt_metrica($val_p, $def['format']) ?>
                </td>
                <td style="text-align:center">
                    <?php if ($diff != 0): ?>
                        <span style="font-weight:700;color:<?= $color_diff ?>;
                                     font-size:.82rem;display:inline-flex;align-items:center;gap:4px">
                            <?= $icono_diff ?>
                            <?= ($diff > 0 ? '+' : '') . fmt_metrica(abs($diff), $def['format']) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.8rem">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Gráfico radar / barras horizontales de comparación -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-chart-bar"></i> Resumen visual de brechas</span>
    </div>
    <div style="padding:16px;position:relative;height:320px">
        <canvas id="chartComparativo"></canvas>
    </div>
</div>

<?php
// Preparar datos para el gráfico comparativo (barras de métricas positivas normalizadas)
$metricas_grafico = ['total_cobrado','tasa_recuperacion','pct_morosidad','finalizados','cuotas_vencidas','sin_visitar'];
$labels_g = [];
$vals_cob_g = [];
$vals_prom_g = [];
foreach ($metricas_grafico as $key) {
    foreach ($metricas_def as $def) {
        if ($def['key'] === $key) {
            $labels_g[]    = $def['label'];
            $vals_cob_g[]  = round($met_cob[$key], 2);
            $vals_prom_g[] = round($met_prom[$key], 2);
            break;
        }
    }
}
$json_gl  = json_encode($labels_g);
$json_gc  = json_encode($vals_cob_g);
$json_gp  = json_encode($vals_prom_g);
$nombre_cob_js = addslashes($nombre_cob);
?>
<?php endif; ?>

<?php
$page_scripts = '';
if ($cobrador_id > 0 && $met_cob) {
    $page_scripts = <<<SCRIPTS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = 'rgba(255,255,255,.6)';
Chart.defaults.borderColor = 'rgba(255,255,255,.07)';
Chart.defaults.font.family = "'Sarabun', sans-serif";

(function() {
    const ctx = document.getElementById('chartComparativo');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$json_gl},
            datasets: [
                {
                    label: '{$nombre_cob_js}',
                    data: {$json_gc},
                    backgroundColor: 'rgba(60,80,224,.75)',
                    borderColor: '#3C50E0',
                    borderWidth: 1,
                    borderRadius: 4
                },
                {
                    label: 'Promedio equipo',
                    data: {$json_gp},
                    backgroundColor: 'rgba(245,158,11,.6)',
                    borderColor: '#F59E0B',
                    borderWidth: 1,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { padding: 14, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': ' +
                            Number(ctx.raw).toLocaleString('es-AR', { maximumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { font: { size: 11 } } },
                y: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { font: { size: 10 } } }
            }
        }
    });
})();
</script>
<style>
@media(max-width:600px){ .kpi-side-grid { grid-template-columns:1fr !important; } }
</style>
SCRIPTS;
}
require_once __DIR__ . '/../views/layout_footer.php';
?>
