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

// ── Vista detalle de un vendedor específico ────────────────────
if ($vendedor_id > 0) {

    $vend = $pdo->prepare("SELECT * FROM ic_vendedores WHERE id = ?");
    $vend->execute([$vendedor_id]);
    $vendedor = $vend->fetch();
    if (!$vendedor) {
        header('Location: estadisticas');
        exit;
    }

    // KPIs del vendedor
    $kpi = $pdo->prepare("
        SELECT
            COUNT(*)                                                   AS total_creditos,
            COUNT(DISTINCT cr.cliente_id)                              AS total_clientes,
            COALESCE(SUM(cr.monto_total), 0)                          AS monto_vendido,
            COUNT(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                        AND (SELECT COUNT(*) FROM ic_cuotas
                             WHERE credito_id=cr.id
                               AND estado IN ('VENCIDA','PENDIENTE')
                               AND fecha_vencimiento < CURDATE()) = 0  THEN 1 END) AS al_dia,
            COUNT(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                        AND (SELECT COUNT(*) FROM ic_cuotas
                             WHERE credito_id=cr.id
                               AND estado IN ('VENCIDA','PENDIENTE')
                               AND fecha_vencimiento < CURDATE()) > 0  THEN 1 END) AS atrasados,
            COUNT(CASE WHEN cr.motivo_finalizacion = 'PAGO_COMPLETO'  THEN 1 END) AS pagados,
            COUNT(CASE WHEN cr.motivo_finalizacion = 'RETIRO_PRODUCTO' THEN 1 END) AS retirados
        FROM ic_creditos cr
        WHERE cr.vendedor_id = ?
    ");
    $kpi->execute([$vendedor_id]);
    $k = $kpi->fetch();

    // Listado de créditos del vendedor
    $stmt = $pdo->prepare("
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
                WHERE cu2.credito_id=cr.id) AS ultimo_pago
        FROM ic_creditos cr
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.vendedor_id = ?
        ORDER BY cr.estado ASC, cr.fecha_alta DESC
    ");
    $stmt->execute([$vendedor_id]);
    $creditos = $stmt->fetchAll();

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

    <!-- KPI Cards -->
    <div class="db-kpi-grid mb-4">

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
                <div class="kpi-sub"><?= number_format($k['pagados']) ?> crédito<?= $k['pagados'] != 1 ? 's' : '' ?> finalizado<?= $k['pagados'] != 1 ? 's' : '' ?> pagos</div>
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
                <div class="kpi-sub">
                    <?= number_format($k['retirados']) ?> retiro<?= $k['retirados'] != 1 ? 's' : '' ?> de artículo
                </div>
            </div>
        </div>

    </div>

    <!-- Tabla detalle créditos -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title">
                <i class="fa fa-list"></i>
                Créditos de <?= e($vendedor['nombre'] . ' ' . $vendedor['apellido']) ?>
            </span>
            <span class="text-muted" style="font-size:.8rem"><?= count($creditos) ?> registro<?= count($creditos) != 1 ? 's' : '' ?></span>
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
                    <tr><td colspan="8" class="text-center text-muted" style="padding:32px">Sin créditos asignados.</td></tr>
                <?php else: ?>
                <?php foreach ($creditos as $cr):
                    // Determinar condición de pago
                    if ($cr['motivo_finalizacion'] === 'RETIRO_PRODUCTO') {
                        $cond_label = 'Retiró Artículo';
                        $cond_badge = 'badge-warning';
                        $cond_icon  = 'fa-box-open';
                        $row_style  = '';
                    } elseif ($cr['motivo_finalizacion'] === 'PAGO_COMPLETO') {
                        $cond_label = 'Pagado';
                        $cond_badge = 'badge-success';
                        $cond_icon  = 'fa-circle-check';
                        $row_style  = '';
                    } elseif ($cr['cuotas_vencidas'] > 0) {
                        $cond_label = 'Atrasado (' . $cr['cuotas_vencidas'] . ')';
                        $cond_badge = 'badge-danger';
                        $cond_icon  = 'fa-triangle-exclamation';
                        $row_style  = 'background:rgba(211,64,83,.06)';
                    } else {
                        $cond_label = 'Al Día';
                        $cond_badge = 'badge-success';
                        $cond_icon  = 'fa-check';
                        $row_style  = '';
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

// ── Vista general: resumen de todos los vendedores ────────────
} else {

    // Opciones de ordenamiento
    $orden_opts = [
        'mayor_venta'   => ['label' => 'Mayor venta',         'sql' => 'monto_vendido DESC'],
        'mas_creditos'  => ['label' => 'Más créditos',        'sql' => 'total_creditos DESC'],
        'mas_atrasados' => ['label' => 'Más atrasados',       'sql' => 'atrasados DESC'],
        'mas_pagados'   => ['label' => 'Más pagados',         'sql' => 'pagados DESC'],
        'mas_retiros'   => ['label' => 'Más retiros',         'sql' => 'retirados DESC'],
        'mejor_cumpl'   => ['label' => 'Mejor cumplimiento',  'sql' => 'al_dia DESC, atrasados ASC'],
        'peor_cumpl'    => ['label' => 'Peor cumplimiento',   'sql' => 'atrasados DESC, al_dia ASC'],
        'nombre'        => ['label' => 'Nombre A-Z',          'sql' => 'v.apellido ASC, v.nombre ASC'],
    ];
    $orden = isset($_GET['orden'], $orden_opts[$_GET['orden']]) ? $_GET['orden'] : 'mayor_venta';
    $order_sql = $orden_opts[$orden]['sql'];

    $stmt = $pdo->query("
        SELECT
            v.id, v.nombre, v.apellido, v.telefono, v.activo,
            COUNT(cr.id)                                                AS total_creditos,
            COUNT(DISTINCT cr.cliente_id)                               AS total_clientes,
            COALESCE(SUM(cr.monto_total), 0)                           AS monto_vendido,
            COUNT(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                        AND (SELECT COUNT(*) FROM ic_cuotas
                             WHERE credito_id=cr.id
                               AND estado IN ('VENCIDA','PENDIENTE')
                               AND fecha_vencimiento < CURDATE()) = 0  THEN 1 END) AS al_dia,
            COUNT(CASE WHEN cr.estado IN ('EN_CURSO','MOROSO')
                        AND (SELECT COUNT(*) FROM ic_cuotas
                             WHERE credito_id=cr.id
                               AND estado IN ('VENCIDA','PENDIENTE')
                               AND fecha_vencimiento < CURDATE()) > 0  THEN 1 END) AS atrasados,
            COUNT(CASE WHEN cr.motivo_finalizacion = 'PAGO_COMPLETO'   THEN 1 END) AS pagados,
            COUNT(CASE WHEN cr.motivo_finalizacion = 'RETIRO_PRODUCTO'  THEN 1 END) AS retirados
        FROM ic_vendedores v
        LEFT JOIN ic_creditos cr ON cr.vendedor_id = v.id
        GROUP BY v.id
        ORDER BY $order_sql
    ");
    $vendedores = $stmt->fetchAll();

    $page_title   = 'Estadísticas de Vendedores';
    $page_current = 'vendedores_stats';
    require_once __DIR__ . '/../views/layout.php';
    ?>

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-chart-bar"></i> Rendimiento por Vendedor</span>
            <div style="display:flex;align-items:center;gap:10px">
                <form method="GET" style="margin:0">
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
                        <th style="text-align:right">Total vendido</th>
                        <th style="text-align:center">Al día</th>
                        <th style="text-align:center">Atrasados</th>
                        <th style="text-align:center">Pagados</th>
                        <th style="text-align:center">Retiros</th>
                        <th style="text-align:center">% cumplimiento</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($vendedores)): ?>
                    <tr><td colspan="10" class="text-center text-muted" style="padding:32px">Sin vendedores registrados.</td></tr>
                <?php else: ?>
                <?php foreach ($vendedores as $v):
                    $activos = (int)$v['al_dia'] + (int)$v['atrasados'];
                    $pct_ok  = $activos > 0 ? round($v['al_dia'] / $activos * 100) : ($v['total_creditos'] > 0 ? 100 : 0);
                    $bar_color = $pct_ok >= 80 ? 'var(--success)' : ($pct_ok >= 50 ? 'var(--warning)' : 'var(--danger)');
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
                    <td style="text-align:center"><?= $v['total_creditos'] ?></td>
                    <td style="text-align:center"><?= $v['total_clientes'] ?></td>
                    <td style="text-align:right;font-weight:700;color:var(--primary-light)"><?= formato_pesos($v['monto_vendido']) ?></td>
                    <td style="text-align:center">
                        <?php if ($v['al_dia'] > 0): ?>
                            <span class="badge-ic badge-success"><?= $v['al_dia'] ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($v['atrasados'] > 0): ?>
                            <span class="badge-ic badge-danger"><?= $v['atrasados'] ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($v['pagados'] > 0): ?>
                            <span class="badge-ic badge-primary"><?= $v['pagados'] ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($v['retirados'] > 0): ?>
                            <span class="badge-ic badge-warning"><?= $v['retirados'] ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
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
                        <a href="estadisticas?id=<?= $v['id'] ?>" class="btn-ic btn-ghost btn-sm" title="Ver detalle">
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
