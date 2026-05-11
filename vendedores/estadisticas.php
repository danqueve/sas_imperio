<?php
// ============================================================
// vendedores/estadisticas.php — Estadísticas por Vendedor
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();
$vendedor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Filtro temporal ──────────────────────────────────────────
$preset  = $_GET['periodo'] ?? 'historico';
$validos = ['mes_actual','mes_ant','trim','sem','anio','historico','custom'];
if (!in_array($preset, $validos)) $preset = 'historico';

$hoy = date('Y-m-d');
switch ($preset) {
    case 'mes_actual':
        $f_desde = date('Y-m-01'); $f_hasta = $hoy; break;
    case 'mes_ant':
        $f_desde = date('Y-m-01', strtotime('first day of last month'));
        $f_hasta = date('Y-m-t',  strtotime('last month')); break;
    case 'trim':
        $f_desde = date('Y-m-d', strtotime('-3 months')); $f_hasta = $hoy; break;
    case 'sem':
        $f_desde = date('Y-m-d', strtotime('-6 months')); $f_hasta = $hoy; break;
    case 'anio':
        $f_desde = date('Y-01-01'); $f_hasta = $hoy; break;
    case 'custom':
        $f_desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-01-01');
        $f_hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy; break;
    default:
        $f_desde = null; $f_hasta = null; break;
}

$tiene_filtro  = ($f_desde && $f_hasta);
$params_fecha  = $tiene_filtro ? [$f_desde, $f_hasta] : [];
$where_fecha   = $tiene_filtro ? "AND cr.fecha_alta BETWEEN ? AND ?" : "";
$on_fecha      = $tiene_filtro ? "AND cr.fecha_alta BETWEEN ? AND ?" : "";  // para LEFT JOIN ON

$presets_labels = [
    'mes_actual' => 'Mes actual',
    'mes_ant'    => 'Mes anterior',
    'trim'       => 'Últimos 3 meses',
    'sem'        => 'Últimos 6 meses',
    'anio'       => date('Y') . ' (año actual)',
    'historico'  => 'Histórico completo',
    'custom'     => 'Personalizado',
];
$label_periodo = $presets_labels[$preset] ?? 'Histórico completo';
if ($preset === 'custom' && $tiene_filtro) {
    $label_periodo .= ' (' . date('d/m/Y', strtotime($f_desde)) . ' — ' . date('d/m/Y', strtotime($f_hasta)) . ')';
}

// ── Subquery reutilizable: aging por credito ─────────────────
// MAX de días de atraso por crédito (solo cuotas vencidas pasadas)
$aging_subq = "
    (SELECT credito_id,
            MAX(GREATEST(0, DATEDIFF(CURDATE(), fecha_vencimiento))) AS max_dias
     FROM ic_cuotas
     WHERE estado IN ('VENCIDA','PENDIENTE') AND fecha_vencimiento < CURDATE()
     GROUP BY credito_id)";

// ── HTML helper para el selector de período ──────────────────
function filtro_periodo_html(string $preset_act, ?string $f_desde, ?string $f_hasta,
                             int $vendedor_id, array $presets_labels): string {
    $select = '<select name="periodo" onchange="toggleCustomFecha(this.value)"
                       style="background:var(--dark-card);border:1px solid var(--dark-border);
                              color:var(--text-main);border-radius:6px;padding:5px 10px;
                              font-size:.8rem;cursor:pointer">';
    foreach ($presets_labels as $k => $lbl) {
        $sel = $preset_act === $k ? 'selected' : '';
        $select .= "<option value=\"$k\" $sel>$lbl</option>";
    }
    $select .= '</select>';

    $hidden = $vendedor_id ? '<input type="hidden" name="id" value="' . $vendedor_id . '">' : '';
    $disp   = $preset_act === 'custom' ? 'flex' : 'none';
    $vd = htmlspecialchars($f_desde ?? '', ENT_QUOTES);
    $vh = htmlspecialchars($f_hasta ?? '', ENT_QUOTES);

    return <<<HTML
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        $hidden
        $select
        <div id="custom-range-fecha" style="display:$disp;gap:6px;align-items:center">
            <input type="date" name="desde" value="$vd"
                   style="background:var(--dark-card);border:1px solid var(--dark-border);
                          color:var(--text-main);border-radius:6px;padding:4px 8px;font-size:.8rem">
            <span style="color:var(--text-muted)">—</span>
            <input type="date" name="hasta" value="$vh"
                   style="background:var(--dark-card);border:1px solid var(--dark-border);
                          color:var(--text-main);border-radius:6px;padding:4px 8px;font-size:.8rem">
        </div>
        <button type="submit" class="btn-ic btn-secondary btn-sm">Aplicar</button>
    </form>
    <script>
    function toggleCustomFecha(v) {
        document.getElementById('custom-range-fecha').style.display = v === 'custom' ? 'flex' : 'none';
    }
    </script>
HTML;
}


// ═══════════════════════════════════════════════════════════
// VISTA DETALLE — un vendedor específico
// ═══════════════════════════════════════════════════════════
if ($vendedor_id > 0) {

    $vend = $pdo->prepare("SELECT * FROM ic_vendedores WHERE id = ?");
    $vend->execute([$vendedor_id]);
    $vendedor = $vend->fetch();
    if (!$vendedor) { header('Location: estadisticas'); exit; }

    // ── KPIs principales ────────────────────────────────────
    $kpi_sql = "
        SELECT
            COUNT(cr.id)                                                 AS total_creditos,
            COUNT(DISTINCT cr.cliente_id)                                AS total_clientes,
            COALESCE(SUM(cr.monto_total), 0)                             AS monto_vendido,
            COALESCE(SUM(COALESCE(pag.cobrado, 0)), 0)                   AS total_cobrado,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) = 0 THEN 1 ELSE 0 END) AS al_dia,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) > 0 THEN 1 ELSE 0 END) AS atrasados,
            SUM(CASE WHEN cr.motivo_finalizacion = 'PAGO_COMPLETO'   THEN 1 ELSE 0 END) AS pagados,
            SUM(CASE WHEN cr.motivo_finalizacion = 'RETIRO_PRODUCTO'  THEN 1 ELSE 0 END) AS retirados
        FROM ic_creditos cr
        LEFT JOIN (
            SELECT cu.credito_id, SUM(pc.monto_total) AS cobrado
            FROM ic_pagos_confirmados pc
            JOIN ic_cuotas cu ON pc.cuota_id = cu.id
            WHERE pc.revertido = 0
            GROUP BY cu.credito_id
        ) pag ON pag.credito_id = cr.id
        LEFT JOIN $aging_subq aging ON aging.credito_id = cr.id
        WHERE cr.vendedor_id = ? $where_fecha
    ";
    $kpi_stmt = $pdo->prepare($kpi_sql);
    $kpi_stmt->execute(array_merge([$vendedor_id], $params_fecha));
    $k = $kpi_stmt->fetch();

    // Clientes recurrentes (≥2 créditos con este vendedor en el período)
    $rec_sql = "SELECT COUNT(*) FROM (
        SELECT cliente_id FROM ic_creditos WHERE vendedor_id = ? $where_fecha
        GROUP BY cliente_id HAVING COUNT(*) >= 2
    ) sub";
    $rec_stmt = $pdo->prepare($rec_sql);
    $rec_stmt->execute(array_merge([$vendedor_id], $params_fecha));
    $k['recurrentes'] = (int) $rec_stmt->fetchColumn();

    // Métricas derivadas
    $ticket_prom  = $k['total_creditos'] > 0 ? $k['monto_vendido'] / $k['total_creditos'] : 0;
    $pct_cobrado  = $k['monto_vendido']  > 0 ? round($k['total_cobrado'] / $k['monto_vendido'] * 100, 1) : 0;
    $tasa_retiro  = $k['total_creditos'] > 0 ? round($k['retirados'] / $k['total_creditos'] * 100, 1) : 0;

    // ── Aging de cartera ────────────────────────────────────
    $aging_sql = "
        SELECT
            SUM(CASE WHEN cr.motivo_finalizacion = 'RETIRO_PRODUCTO'              THEN 1 ELSE 0 END) AS bucket_retiro,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) = 0                         THEN 1 ELSE 0 END) AS bucket_aldia,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND aging.max_dias BETWEEN 1 AND 30                         THEN 1 ELSE 0 END) AS bucket_30,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND aging.max_dias BETWEEN 31 AND 60                        THEN 1 ELSE 0 END) AS bucket_60,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND aging.max_dias > 60                                     THEN 1 ELSE 0 END) AS bucket_mas,
            SUM(CASE WHEN cr.estado = 'FINALIZADO'
                      AND cr.motivo_finalizacion = 'PAGO_COMPLETO'                THEN 1 ELSE 0 END) AS bucket_pagado
        FROM ic_creditos cr
        LEFT JOIN $aging_subq aging ON aging.credito_id = cr.id
        WHERE cr.vendedor_id = ? $where_fecha
    ";
    $ag_stmt = $pdo->prepare($aging_sql);
    $ag_stmt->execute(array_merge([$vendedor_id], $params_fecha));
    $aging = $ag_stmt->fetch();

    // ── Top 10 clientes ─────────────────────────────────────
    $top_sql = "
        SELECT
            cl.id,
            CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
            cl.telefono,
            COUNT(cr.id)                AS total_creditos,
            COALESCE(SUM(cr.monto_total), 0) AS monto_total,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) = 0 THEN 1 ELSE 0 END) AS al_dia,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) > 0 THEN 1 ELSE 0 END) AS atrasado,
            SUM(CASE WHEN cr.motivo_finalizacion = 'RETIRO_PRODUCTO' THEN 1 ELSE 0 END) AS retiro
        FROM ic_creditos cr
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN $aging_subq aging ON aging.credito_id = cr.id
        WHERE cr.vendedor_id = ? $where_fecha
        GROUP BY cl.id
        ORDER BY monto_total DESC
        LIMIT 10
    ";
    $top_stmt = $pdo->prepare($top_sql);
    $top_stmt->execute(array_merge([$vendedor_id], $params_fecha));
    $top_clientes = $top_stmt->fetchAll();

    // ── Listado de créditos ──────────────────────────────────
    $cred_sql = "
        SELECT
            cr.id, cr.estado, cr.motivo_finalizacion,
            cr.monto_total, cr.monto_cuota, cr.cant_cuotas, cr.frecuencia,
            cr.fecha_alta,
            COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
            cl.id AS cliente_id,
            CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
            cl.telefono,
            (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas,
            (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id
                AND estado IN ('VENCIDA','PENDIENTE') AND fecha_vencimiento < CURDATE()) AS cuotas_vencidas,
            (SELECT MAX(pc2.fecha_pago) FROM ic_pagos_confirmados pc2
                JOIN ic_cuotas cu2 ON pc2.cuota_id=cu2.id
                WHERE cu2.credito_id=cr.id AND pc2.revertido=0) AS ultimo_pago
        FROM ic_creditos cr
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.vendedor_id = ? $where_fecha
        ORDER BY cr.estado ASC, cr.fecha_alta DESC
    ";
    $cred_stmt = $pdo->prepare($cred_sql);
    $cred_stmt->execute(array_merge([$vendedor_id], $params_fecha));
    $creditos = $cred_stmt->fetchAll();

    $page_title   = 'Estadísticas — ' . e($vendedor['nombre'] . ' ' . $vendedor['apellido']);
    $page_current = 'vendedores_stats';
    require_once __DIR__ . '/../views/layout.php';
    ?>

    <!-- Breadcrumb -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;font-size:.85rem;color:var(--text-muted)">
        <a href="index" style="color:var(--text-muted);text-decoration:none">Vendedores</a>
        <span>/</span>
        <a href="estadisticas" style="color:var(--text-muted);text-decoration:none">Estadísticas</a>
        <span>/</span>
        <span style="color:var(--text-main)"><?= e($vendedor['apellido'] . ', ' . $vendedor['nombre']) ?></span>
    </div>

    <!-- Filtro temporal -->
    <div class="card-ic mb-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-calendar-days"></i> <?= e($label_periodo) ?></span>
            <?= filtro_periodo_html($preset, $f_desde, $f_hasta, $vendedor_id, $presets_labels) ?>
        </div>
    </div>

    <!-- KPI Cards — fila 1 -->
    <div class="db-kpi-grid mb-3">

        <div class="kpi-card" style="--kpi-color:var(--primary-light)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(144,153,232,.15);--icon-color:#9099e8">
                <i class="fa fa-file-invoice-dollar"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Créditos</div>
                <div class="kpi-value"><?= number_format($k['total_creditos']) ?></div>
                <div class="kpi-sub"><?= number_format($k['total_clientes']) ?> cliente<?= $k['total_clientes'] != 1 ? 's' : '' ?> únicos</div>
            </div>
        </div>

        <div class="kpi-card" style="--kpi-color:var(--accent)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(6,182,212,.15);--icon-color:#06b6d4">
                <i class="fa fa-dollar-sign"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Total Vendido</div>
                <div class="kpi-value" style="font-size:1.35rem"><?= formato_pesos($k['monto_vendido']) ?></div>
                <div class="kpi-sub"><?= number_format($k['pagados']) ?> finalizado<?= $k['pagados'] != 1 ? 's' : '' ?> pagos</div>
            </div>
        </div>

        <div class="kpi-card" style="--kpi-color:var(--success)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981">
                <i class="fa fa-circle-check"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Al Día</div>
                <div class="kpi-value"><?= number_format($k['al_dia']) ?></div>
                <div class="kpi-sub">sin cuotas vencidas</div>
            </div>
        </div>

        <div class="kpi-card" style="--kpi-color:var(--danger)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(211,64,83,.15);--icon-color:#d34053">
                <i class="fa fa-clock-rotate-left"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Atrasados</div>
                <div class="kpi-value"><?= number_format($k['atrasados']) ?></div>
                <div class="kpi-sub"><?= number_format($k['retirados']) ?> retiro<?= $k['retirados'] != 1 ? 's' : '' ?> de artículo</div>
            </div>
        </div>

    </div>

    <!-- KPI Cards — fila 2 -->
    <div class="db-kpi-grid mb-4">

        <div class="kpi-card" style="--kpi-color:#f59e0b">
            <div class="kpi-icon-box" style="--icon-bg:rgba(245,158,11,.15);--icon-color:#f59e0b">
                <i class="fa fa-tag"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Ticket Promedio</div>
                <div class="kpi-value" style="font-size:1.25rem"><?= formato_pesos($ticket_prom) ?></div>
                <div class="kpi-sub">por crédito vendido</div>
            </div>
        </div>

        <div class="kpi-card" style="--kpi-color:<?= $pct_cobrado >= 70 ? 'var(--success)' : ($pct_cobrado >= 40 ? 'var(--warning)' : 'var(--danger)') ?>">
            <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.12);--icon-color:#10b981">
                <i class="fa fa-hand-holding-dollar"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">% Cobrado</div>
                <div class="kpi-value"><?= $pct_cobrado ?>%</div>
                <div class="kpi-sub"><?= formato_pesos($k['total_cobrado']) ?> cobrado</div>
            </div>
        </div>

        <div class="kpi-card" style="--kpi-color:<?= $tasa_retiro <= 5 ? 'var(--success)' : ($tasa_retiro <= 15 ? 'var(--warning)' : 'var(--danger)') ?>">
            <div class="kpi-icon-box" style="--icon-bg:rgba(139,92,246,.15);--icon-color:#8b5cf6">
                <i class="fa fa-box-open"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Tasa de Retiro</div>
                <div class="kpi-value"><?= $tasa_retiro ?>%</div>
                <div class="kpi-sub">de artículos retirados</div>
            </div>
        </div>

        <div class="kpi-card" style="--kpi-color:var(--primary-light)">
            <div class="kpi-icon-box" style="--icon-bg:rgba(144,153,232,.15);--icon-color:#9099e8">
                <i class="fa fa-repeat"></i>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Clientes Recurrentes</div>
                <div class="kpi-value"><?= number_format($k['recurrentes']) ?></div>
                <div class="kpi-sub">con 2 o más créditos</div>
            </div>
        </div>

    </div>

    <!-- Aging de cartera -->
    <?php
    $total_aging = max(1, $k['total_creditos']);
    $buckets = [
        ['label' => 'Al día',           'val' => (int)$aging['bucket_aldia'],  'color' => '#10b981'],
        ['label' => 'Atraso 1–30 días', 'val' => (int)$aging['bucket_30'],     'color' => '#f59e0b'],
        ['label' => 'Atraso 31–60 días','val' => (int)$aging['bucket_60'],     'color' => '#f97316'],
        ['label' => 'Atraso 60+ días',  'val' => (int)$aging['bucket_mas'],    'color' => '#d34053'],
        ['label' => 'Retiro artículo',  'val' => (int)$aging['bucket_retiro'], 'color' => '#8b5cf6'],
        ['label' => 'Pagados',          'val' => (int)$aging['bucket_pagado'], 'color' => '#9099e8'],
    ];
    $tiene_aging = array_sum(array_column($buckets, 'val')) > 0;
    ?>
    <div class="card-ic mb-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-bar"></i> Aging de Cartera</span>
            <span class="text-muted" style="font-size:.8rem">distribución de <?= number_format($k['total_creditos']) ?> crédito<?= $k['total_creditos'] != 1 ? 's' : '' ?> — <?= e($label_periodo) ?></span>
        </div>
        <div style="padding:20px">
        <?php if (!$tiene_aging): ?>
            <p class="text-muted text-center" style="padding:16px 0">Sin datos para el período seleccionado.</p>
        <?php else: ?>
        <?php foreach ($buckets as $b):
            $pct = $b['val'] > 0 ? round($b['val'] / $total_aging * 100) : 0;
        ?>
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px">
                <div style="min-width:140px;font-size:.82rem;color:var(--text-muted);text-align:right"><?= $b['label'] ?></div>
                <div style="flex:1;height:14px;background:var(--dark-border);border-radius:7px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $b['color'] ?>;border-radius:7px;transition:width .6s"></div>
                </div>
                <div style="min-width:80px;font-size:.82rem">
                    <span style="font-weight:700;color:<?= $b['color'] ?>"><?= $b['val'] ?></span>
                    <span style="color:var(--text-muted)"> (<?= $pct ?>%)</span>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

    <!-- Top 10 Clientes -->
    <div class="card-ic mb-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-users"></i> Top Clientes</span>
            <span class="text-muted" style="font-size:.8rem">por monto total — <?= e($label_periodo) ?></span>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th style="text-align:center">Créditos</th>
                        <th style="text-align:right">Total</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($top_clientes)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:24px">Sin clientes en el período.</td></tr>
                <?php else: ?>
                <?php foreach ($top_clientes as $i => $tc):
                    if ($tc['retiro'] > 0) {
                        $est_badge = 'badge-warning'; $est_label = 'Retiro';     $est_icon = 'fa-box-open';
                    } elseif ($tc['atrasado'] > 0) {
                        $est_badge = 'badge-danger';  $est_label = 'Atrasado';   $est_icon = 'fa-triangle-exclamation';
                    } elseif ($tc['al_dia'] > 0) {
                        $est_badge = 'badge-success'; $est_label = 'Al día';     $est_icon = 'fa-check';
                    } else {
                        $est_badge = 'badge-primary'; $est_label = 'Finalizado'; $est_icon = 'fa-circle-check';
                    }
                ?>
                <tr>
                    <td class="text-muted" style="font-size:.75rem"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-bold">
                            <a href="../clientes/ver?id=<?= $tc['id'] ?>"><?= e($tc['cliente']) ?></a>
                        </div>
                        <?php if ($tc['telefono']): ?>
                            <div class="text-muted" style="font-size:.72rem"><?= e($tc['telefono']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <span class="badge-ic badge-primary"><?= $tc['total_creditos'] ?></span>
                    </td>
                    <td style="text-align:right;font-weight:700;color:var(--primary-light);white-space:nowrap">
                        <?= formato_pesos($tc['monto_total']) ?>
                    </td>
                    <td>
                        <span class="badge-ic <?= $est_badge ?>" style="white-space:nowrap">
                            <i class="fa <?= $est_icon ?>"></i> <?= $est_label ?>
                        </span>
                    </td>
                    <td>
                        <a href="../clientes/ver?id=<?= $tc['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver cliente">
                            <i class="fa fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabla detalle créditos -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title">
                <i class="fa fa-list"></i>
                Créditos de <?= e($vendedor['nombre'] . ' ' . $vendedor['apellido']) ?>
            </span>
            <span class="text-muted" style="font-size:.8rem"><?= count($creditos) ?> registro<?= count($creditos) != 1 ? 's' : '' ?> — <?= e($label_periodo) ?></span>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th>Artículo</th>
                        <th>Total</th>
                        <th>Avance</th>
                        <th>Último pago</th>
                        <th>Cond. pago</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($creditos)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:32px">Sin créditos en el período seleccionado.</td></tr>
                <?php else: ?>
                <?php foreach ($creditos as $cr):
                    if ($cr['motivo_finalizacion'] === 'RETIRO_PRODUCTO') {
                        $cond_label = 'Retiró Artículo'; $cond_badge = 'badge-warning'; $cond_icon = 'fa-box-open'; $row_style = '';
                    } elseif ($cr['motivo_finalizacion'] === 'PAGO_COMPLETO') {
                        $cond_label = 'Pagado'; $cond_badge = 'badge-success'; $cond_icon = 'fa-circle-check'; $row_style = '';
                    } elseif ($cr['cuotas_vencidas'] > 0) {
                        $cond_label = 'Atrasado (' . $cr['cuotas_vencidas'] . ')'; $cond_badge = 'badge-danger'; $cond_icon = 'fa-triangle-exclamation'; $row_style = 'background:rgba(211,64,83,.06)';
                    } else {
                        $cond_label = 'Al Día'; $cond_badge = 'badge-success'; $cond_icon = 'fa-check'; $row_style = '';
                    }
                    $pct = $cr['cant_cuotas'] > 0 ? round($cr['cuotas_pagadas'] / $cr['cant_cuotas'] * 100) : 0;
                ?>
                <tr style="<?= $row_style ?>">
                    <td class="text-muted">#<?= $cr['id'] ?></td>
                    <td>
                        <div class="fw-bold">
                            <a href="../clientes/ver?id=<?= $cr['cliente_id'] ?>"><?= e($cr['cliente']) ?></a>
                        </div>
                        <div class="text-muted" style="font-size:.72rem"><?= e($cr['telefono']) ?></div>
                    </td>
                    <td style="font-size:.83rem"><?= e($cr['articulo']) ?></td>
                    <td class="fw-bold nowrap"><?= formato_pesos($cr['monto_total']) ?></td>
                    <td class="nowrap">
                        <div style="font-size:.78rem;margin-bottom:4px">
                            <?= $cr['cuotas_pagadas'] ?>/<?= $cr['cant_cuotas'] ?>
                            <span class="text-muted" style="font-size:.7rem">(<?= $pct ?>%)</span>
                        </div>
                        <div style="width:80px;height:4px;background:var(--dark-border);border-radius:4px;overflow:hidden">
                            <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? 'var(--success)' : 'var(--primary-light)' ?>;border-radius:4px"></div>
                        </div>
                    </td>
                    <td class="text-muted nowrap" style="font-size:.8rem">
                        <?= $cr['ultimo_pago'] ? date('d/m/Y', strtotime($cr['ultimo_pago'])) : '<span style="opacity:.4">—</span>' ?>
                    </td>
                    <td>
                        <span class="badge-ic <?= $cond_badge ?>" style="white-space:nowrap">
                            <i class="fa <?= $cond_icon ?>"></i> <?= $cond_label ?>
                        </span>
                    </td>
                    <td>
                        <a href="../creditos/ver?id=<?= $cr['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver crédito">
                            <i class="fa fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php

// ═══════════════════════════════════════════════════════════
// VISTA RANKING — todos los vendedores
// ═══════════════════════════════════════════════════════════
} else {

    $orden_opts = [
        'mayor_venta'    => ['label' => 'Mayor venta',         'sql' => 'monto_vendido DESC'],
        'mayor_cobrado'  => ['label' => 'Mayor cobrado',       'sql' => 'total_cobrado DESC'],
        'mas_creditos'   => ['label' => 'Más créditos',        'sql' => 'total_creditos DESC'],
        'mas_atrasados'  => ['label' => 'Más atrasados',       'sql' => 'atrasados DESC'],
        'mas_pagados'    => ['label' => 'Más pagados',         'sql' => 'pagados DESC'],
        'mas_retiros'    => ['label' => 'Más retiros',         'sql' => 'retirados DESC'],
        'mejor_cumpl'    => ['label' => 'Mejor cumplimiento',  'sql' => 'al_dia DESC, atrasados ASC'],
        'peor_cumpl'     => ['label' => 'Peor cumplimiento',   'sql' => 'atrasados DESC, al_dia ASC'],
        'nombre'         => ['label' => 'Nombre A-Z',          'sql' => 'v.apellido ASC, v.nombre ASC'],
    ];
    $orden     = isset($_GET['orden'], $orden_opts[$_GET['orden']]) ? $_GET['orden'] : 'mayor_venta';
    $order_sql = $orden_opts[$orden]['sql'];

    // Parámetros para la query del ranking (fecha va en el ON del LEFT JOIN)
    $params_ranking = $tiene_filtro ? [$f_desde, $f_hasta] : [];

    $rank_sql = "
        SELECT
            v.id, v.nombre, v.apellido, v.telefono, v.activo,
            COUNT(cr.id)                                                      AS total_creditos,
            COUNT(DISTINCT cr.cliente_id)                                     AS total_clientes,
            COALESCE(SUM(cr.monto_total), 0)                                  AS monto_vendido,
            COALESCE(SUM(COALESCE(pag.cobrado, 0)), 0)                        AS total_cobrado,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) = 0 THEN 1 ELSE 0 END) AS al_dia,
            SUM(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                      AND COALESCE(aging.max_dias, 0) > 0 THEN 1 ELSE 0 END) AS atrasados,
            SUM(CASE WHEN cr.motivo_finalizacion = 'PAGO_COMPLETO'   THEN 1 ELSE 0 END) AS pagados,
            SUM(CASE WHEN cr.motivo_finalizacion = 'RETIRO_PRODUCTO'  THEN 1 ELSE 0 END) AS retirados
        FROM ic_vendedores v
        LEFT JOIN ic_creditos cr
            ON cr.vendedor_id = v.id " . ($tiene_filtro ? "AND cr.fecha_alta BETWEEN ? AND ?" : "") . "
        LEFT JOIN (
            SELECT cu.credito_id, SUM(pc.monto_total) AS cobrado
            FROM ic_pagos_confirmados pc
            JOIN ic_cuotas cu ON pc.cuota_id = cu.id
            WHERE pc.revertido = 0
            GROUP BY cu.credito_id
        ) pag ON pag.credito_id = cr.id
        LEFT JOIN $aging_subq aging ON aging.credito_id = cr.id
        GROUP BY v.id
        ORDER BY $order_sql
    ";
    $rank_stmt = $pdo->prepare($rank_sql);
    $rank_stmt->execute($params_ranking);
    $vendedores = $rank_stmt->fetchAll();

    $page_title   = 'Estadísticas de Vendedores';
    $page_current = 'vendedores_stats';
    require_once __DIR__ . '/../views/layout.php';
    ?>

    <!-- Filtro temporal -->
    <div class="card-ic mb-4">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-calendar-days"></i> <?= e($label_periodo) ?></span>
            <?= filtro_periodo_html($preset, $f_desde, $f_hasta, 0, $presets_labels) ?>
        </div>
    </div>

    <!-- Tabla ranking -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-bar"></i> Rendimiento por Vendedor</span>
            <div style="display:flex;align-items:center;gap:10px">
                <form method="GET" style="margin:0">
                    <?php if ($tiene_filtro): ?>
                        <input type="hidden" name="periodo" value="<?= e($preset) ?>">
                        <?php if ($preset === 'custom'): ?>
                            <input type="hidden" name="desde" value="<?= e($f_desde) ?>">
                            <input type="hidden" name="hasta" value="<?= e($f_hasta) ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                    <select name="orden" onchange="this.form.submit()"
                            style="background:var(--dark-card);border:1px solid var(--dark-border);
                                   color:var(--text-main);border-radius:6px;padding:5px 10px;
                                   font-size:.8rem;cursor:pointer">
                        <?php foreach ($orden_opts as $key => $opt): ?>
                            <option value="<?= $key ?>" <?= $orden === $key ? 'selected' : '' ?>>
                                <?= $opt['label'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="index" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver</a>
            </div>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>Vendedor</th>
                        <th style="text-align:center">Créditos</th>
                        <th style="text-align:center">Clientes</th>
                        <th style="text-align:right">Vendido</th>
                        <th style="text-align:right">Cobrado</th>
                        <th style="text-align:center">Al día</th>
                        <th style="text-align:center">Atrasados</th>
                        <th style="text-align:center">Pagados</th>
                        <th style="text-align:center">Retiros</th>
                        <th style="text-align:center">% cumplim.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($vendedores)): ?>
                    <tr><td colspan="11" class="text-center text-muted" style="padding:32px">Sin vendedores registrados.</td></tr>
                <?php else: ?>
                <?php foreach ($vendedores as $v):
                    $activos   = (int)$v['al_dia'] + (int)$v['atrasados'];
                    $pct_ok    = $activos > 0 ? round($v['al_dia'] / $activos * 100) : ($v['total_creditos'] > 0 ? 100 : 0);
                    $bar_color = $pct_ok >= 80 ? 'var(--success)' : ($pct_ok >= 50 ? 'var(--warning)' : 'var(--danger)');
                    $pct_cobr  = $v['monto_vendido'] > 0 ? round($v['total_cobrado'] / $v['monto_vendido'] * 100) : 0;
                    $cobr_color = $pct_cobr >= 70 ? 'var(--success)' : ($pct_cobr >= 40 ? 'var(--warning)' : 'var(--danger)');
                ?>
                <tr>
                    <td>
                        <div class="fw-bold"><?= e($v['apellido'] . ', ' . $v['nombre']) ?></div>
                        <?php if (!$v['activo']): ?>
                            <span class="badge-ic badge-muted" style="font-size:.6rem">Inactivo</span>
                        <?php endif; ?>
                        <?php if ($v['telefono']): ?>
                            <div class="text-muted" style="font-size:.72rem"><?= e($v['telefono']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center"><?= $v['total_creditos'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td style="text-align:center"><?= $v['total_clientes'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--primary-light)">
                        <?= $v['monto_vendido'] > 0 ? formato_pesos($v['monto_vendido']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="text-align:right">
                        <?php if ($v['total_cobrado'] > 0): ?>
                            <span style="font-weight:700;color:<?= $cobr_color ?>"><?= formato_pesos($v['total_cobrado']) ?></span>
                            <div style="font-size:.7rem;color:<?= $cobr_color ?>"><?= $pct_cobr ?>%</div>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?= $v['al_dia'] > 0 ? '<span class="badge-ic badge-success">' . $v['al_dia'] . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="text-align:center">
                        <?= $v['atrasados'] > 0 ? '<span class="badge-ic badge-danger">' . $v['atrasados'] . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="text-align:center">
                        <?= $v['pagados'] > 0 ? '<span class="badge-ic badge-primary">' . $v['pagados'] . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="text-align:center">
                        <?= $v['retirados'] > 0 ? '<span class="badge-ic badge-warning">' . $v['retirados'] . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="min-width:110px">
                        <?php if ($v['total_creditos'] > 0): ?>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;height:6px;background:var(--dark-border);border-radius:3px;overflow:hidden">
                                <div style="width:<?= $pct_ok ?>%;height:100%;background:<?= $bar_color ?>;border-radius:3px;transition:width .6s"></div>
                            </div>
                            <span style="font-size:.75rem;font-weight:700;color:<?= $bar_color ?>;min-width:32px"><?= $pct_ok ?>%</span>
                        </div>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.75rem">Sin créditos</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($v['total_creditos'] > 0): ?>
                        <a href="estadisticas?id=<?= $v['id'] ?><?= $tiene_filtro ? '&periodo=' . urlencode($preset) . ($preset === 'custom' ? '&desde=' . urlencode($f_desde) . '&hasta=' . urlencode($f_hasta) : '') : '' ?>"
                           class="btn-ic btn-ghost btn-sm" title="Ver detalle">
                            <i class="fa fa-chart-line"></i> Detalle
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
}

require_once __DIR__ . '/../views/layout_footer.php';
?>
