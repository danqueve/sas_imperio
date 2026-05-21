<?php
// ============================================================
// ventas/ver_credito.php — Estado de cuenta (solo lectura, vendedor)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes_vendedor');

$pdo = obtener_conexion();

// Resolver vendedor_id
$mi_vendedor_id = null;
if (es_vendedor()) {
    $stmt = $pdo->prepare("SELECT id FROM ic_vendedores WHERE usuario_id=? AND activo=1 LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $mi_vendedor_id = $stmt->fetchColumn() ?: null;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: mis_clientes');
    exit;
}

// Cargar crédito
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono, cl.dni, cl.id AS cid,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a,
           v.nombre AS vendedor_n, v.apellido AS vendedor_a
    FROM ic_creditos cr
    JOIN ic_clientes cl  ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    JOIN ic_usuarios u   ON cr.cobrador_id = u.id
    LEFT JOIN ic_vendedores v ON cr.vendedor_id = v.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();

if (!$cr) {
    header('Location: mis_clientes');
    exit;
}

// Seguridad: el vendedor solo puede ver sus propios créditos
if (es_vendedor() && (int)$cr['vendedor_id'] !== (int)$mi_vendedor_id) {
    http_response_code(403);
    require __DIR__ . '/../views/403.php';
    exit;
}

// Cronograma
$cuotas_stmt = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas_stmt->execute([$id]);
$lista_cuotas = $cuotas_stmt->fetchAll();

// Cuotas pendientes (para banner "por cerrar")
$cuotas_pendientes = 0;
foreach ($lista_cuotas as $cu) {
    if (!in_array($cu['estado'], ['PAGADA', 'CANCELADA'])) {
        $cuotas_pendientes++;
    }
}

// Historial de pagos confirmados
$hist_stmt = $pdo->prepare("
    SELECT
        cu.numero_cuota,
        pt.monto_efectivo,
        pt.monto_transferencia,
        pt.monto_mora_cobrada,
        pt.monto_total,
        pt.fecha_jornada,
        CONCAT(u.nombre, ' ', u.apellido) AS cobrador_nombre
    FROM ic_pagos_confirmados pc
    JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    JOIN ic_cuotas cu           ON cu.id = pc.cuota_id
    JOIN ic_usuarios u          ON u.id  = pt.cobrador_id
    WHERE cu.credito_id = ?
    ORDER BY pt.fecha_registro ASC
");
$hist_stmt->execute([$id]);
$historial_pagos = $hist_stmt->fetchAll();

// Totales historial
$hist_ef   = array_sum(array_column($historial_pagos, 'monto_efectivo'));
$hist_tr   = array_sum(array_column($historial_pagos, 'monto_transferencia'));
$hist_mora = array_sum(array_column($historial_pagos, 'monto_mora_cobrada'));
$hist_tot  = array_sum(array_column($historial_pagos, 'monto_total'));

// Mapa de colores por estado de cuota (sistema .badge-ic del proyecto)
$cuota_badge = [
    'PAGADA'     => 'success',
    'PENDIENTE'  => 'muted',
    'VENCIDA'    => 'danger',
    'PARCIAL'    => 'warning',
    'CAP_PAGADA' => 'info',
    'CANCELADA'  => 'muted',
];

// Progreso general del crédito
$total_cuotas  = count($lista_cuotas);
$pagadas_count = count(array_filter($lista_cuotas, fn($c) => in_array($c['estado'], ['PAGADA', 'CAP_PAGADA'])));
$pct_global    = $total_cuotas > 0 ? round($pagadas_count / $total_cuotas * 100) : 0;
if ($cr['estado'] === 'MOROSO') {
    $bar_color_global = 'var(--danger)';
} elseif ($pct_global >= 75) {
    $bar_color_global = 'var(--success)';
} elseif ($pct_global >= 40) {
    $bar_color_global = 'var(--primary-light)';
} else {
    $bar_color_global = 'var(--text-muted)';
}

$page_title   = 'Crédito #' . $id . ' — ' . $cr['apellidos'] . ', ' . $cr['nombres'];
$page_current = 'mis_clientes';
require __DIR__ . '/../views/layout.php';
?>

<style>
@media (max-width: 600px) {
    .hide-mobile { display: none; }
    .info-grid   { grid-template-columns: 1fr !important; }
    .info-col-left { border-right: none !important; border-bottom: 1px solid var(--dark-border); }
    .table-ic td, .table-ic th { padding: 10px 8px; }
}
</style>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:.82rem;color:var(--text-muted)">
    <a href="<?= BASE_URL ?>ventas/mis_clientes" style="color:var(--primary-light);text-decoration:none">
        <i class="fa fa-users"></i> Mis Clientes
    </a>
    <i class="fa fa-chevron-right" style="font-size:.6rem"></i>
    <span>Crédito #<?= $id ?></span>
</div>

<div class="page-header" style="margin-bottom:20px">
    <div>
        <h1 class="page-title" style="font-size:1.3rem">
            <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
        </h1>
        <div class="page-subtitle">
            <?= e($cr['articulo'] ?? '—') ?>
            &nbsp;·&nbsp; Crédito #<?= $id ?>
            &nbsp;·&nbsp; <?= badge_estado_credito($cr['estado']) ?>
        </div>
    </div>
</div>

<!-- Barra de progreso global -->
<div class="card-ic" style="margin-bottom:20px;padding:16px 20px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <span style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
            Progreso del crédito
        </span>
        <span style="font-size:.8rem;color:<?= $bar_color_global ?>;font-weight:700">
            <?= $pct_global ?>%
        </span>
    </div>
    <div style="background:rgba(255,255,255,.08);border-radius:99px;height:9px;overflow:hidden;margin-bottom:6px">
        <div style="width:<?= $pct_global ?>%;height:100%;border-radius:99px;
                    background:<?= $bar_color_global ?>;transition:width .4s ease"></div>
    </div>
    <div style="font-size:.75rem;color:var(--text-muted)">
        <?= $pagadas_count ?> de <?= $total_cuotas ?> cuotas pagadas
        <?php if ($cuotas_pendientes > 0): ?>
            &nbsp;·&nbsp;
            <span style="color:<?= $cuotas_pendientes <= 3 ? 'var(--warning)' : 'var(--text-muted)' ?>">
                <?= $cuotas_pendientes ?> pendiente<?= $cuotas_pendientes !== 1 ? 's' : '' ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Banner "por cerrar" -->
<?php if ($cuotas_pendientes > 0 && $cuotas_pendientes <= 3): ?>
<div style="background:rgba(255,167,11,.15);border:1px solid rgba(255,167,11,.35);
            border-radius:var(--radius);padding:14px 20px;
            display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <i class="fa fa-hourglass-end" style="color:var(--warning);font-size:1.3rem;flex-shrink:0"></i>
    <div>
        <strong style="color:var(--warning)">Crédito próximo a finalizar</strong>
        <div style="font-size:.83rem;color:var(--text-body);margin-top:2px">
            Quedan <?= $cuotas_pendientes ?> cuota<?= $cuotas_pendientes !== 1 ? 's' : '' ?> pendiente<?= $cuotas_pendientes !== 1 ? 's' : '' ?>.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Info crédito + cliente -->
<div class="card-ic" style="margin-bottom:20px">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-circle-info"></i> Información del Crédito</span>
    </div>
    <div class="info-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:0;padding:4px 0">

        <!-- Col izquierda: datos del cliente -->
        <div class="info-col-left" style="padding:16px 20px;border-right:1px solid var(--dark-border)">
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin-bottom:12px">Cliente</div>
            <div style="display:flex;justify-content:space-between;padding:6px 0;
                        border-bottom:1px solid var(--dark-border-2);font-size:.85rem">
                <span style="color:var(--text-muted)">Nombre</span>
                <span style="font-weight:500"><?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:6px 0;
                        border-bottom:1px solid var(--dark-border-2);font-size:.85rem">
                <span style="color:var(--text-muted)">DNI</span>
                <span style="font-weight:500"><?= e($cr['dni'] ?? '—') ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:6px 0;font-size:.85rem">
                <span style="color:var(--text-muted)">Teléfono</span>
                <?php if (!empty($cr['telefono'])): ?>
                    <a href="tel:<?= e($cr['telefono']) ?>"
                       style="font-weight:500;color:var(--primary-light);text-decoration:none">
                        <?= e($cr['telefono']) ?>
                    </a>
                <?php else: ?>
                    <span style="font-weight:500">—</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Col derecha: datos del crédito -->
        <div style="padding:16px 20px">
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin-bottom:12px">Crédito</div>
            <?php
            $info_credito = [
                'Artículo'    => e($cr['articulo'] ?? '—'),
                'Fecha alta'  => date('d/m/Y', strtotime($cr['fecha_alta'])),
                'Monto total' => formato_pesos((float)$cr['monto_total']),
                'Cuota'       => formato_pesos((float)$cr['monto_cuota']),
                'Frecuencia'  => ucfirst($cr['frecuencia']),
                'Estado'      => badge_estado_credito($cr['estado']),
                'Cobrador'    => e($cr['cobrador_a'] . ', ' . $cr['cobrador_n']),
            ];
            foreach ($info_credito as $lbl => $val): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;
                        padding:6px 0;border-bottom:1px solid var(--dark-border-2);font-size:.85rem">
                <span style="color:var(--text-muted)"><?= $lbl ?></span>
                <span style="font-weight:500"><?= $val ?></span>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<!-- Cronograma -->
<div class="card-ic" style="margin-bottom:20px">
    <div class="card-ic-header">
        <span class="card-title">
            <i class="fa fa-calendar-days"></i> Cronograma de Cuotas
        </span>
        <span style="font-size:.78rem;color:var(--text-muted)">
            <?= $pagadas_count ?> / <?= $total_cuotas ?> pagadas
        </span>
    </div>
    <div class="table-responsive">
        <table class="table-ic">
            <thead>
                <tr>
                    <th style="width:44px">N°</th>
                    <th>Vencimiento</th>
                    <th style="text-align:right">Monto</th>
                    <th class="hide-mobile" style="text-align:right">Mora</th>
                    <th>Estado</th>
                    <th class="hide-mobile" style="text-align:right">Pagado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lista_cuotas as $cu): ?>
                <?php
                    $badge_class = $cuota_badge[$cu['estado']] ?? 'muted';
                    $hoy = date('Y-m-d');
                    $mora = 0;
                    if (!in_array($cu['estado'], ['PAGADA', 'CAP_PAGADA', 'CANCELADA']) && $cu['fecha_vencimiento'] < $hoy) {
                        $dias = dias_atraso_habiles($cu['fecha_vencimiento'], $hoy);
                        $mora = calcular_mora((float)$cu['monto_cuota'] - (float)($cu['saldo_pagado'] ?? 0), $dias, 15);
                    }
                    $es_pagada = in_array($cu['estado'], ['PAGADA', 'CAP_PAGADA', 'CANCELADA']);
                ?>
                <tr style="<?= $es_pagada ? 'opacity:.6' : '' ?>">
                    <td style="text-align:center;font-weight:600"><?= (int)$cu['numero_cuota'] ?></td>
                    <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($cu['fecha_vencimiento'])) ?></td>
                    <td style="text-align:right"><?= formato_pesos((float)$cu['monto_cuota']) ?></td>
                    <td class="hide-mobile" style="text-align:right;color:<?= $mora > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>">
                        <?= $mora > 0 ? formato_pesos($mora) : '—' ?>
                    </td>
                    <td><span class="badge-ic badge-<?= $badge_class ?>"><?= e($cu['estado']) ?></span></td>
                    <td class="hide-mobile" style="text-align:right">
                        <?= (float)($cu['saldo_pagado'] ?? 0) > 0
                            ? formato_pesos((float)$cu['saldo_pagado'])
                            : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Historial de pagos -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title">
            <i class="fa fa-clock-rotate-left"></i> Historial de Pagos
        </span>
        <?php if ($hist_tot > 0): ?>
        <span style="font-size:.78rem;color:var(--text-muted)">
            Total: <strong style="color:var(--success)"><?= formato_pesos($hist_tot) ?></strong>
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($historial_pagos)): ?>
        <div style="text-align:center;padding:40px 20px;color:var(--text-muted)">
            <i class="fa fa-receipt" style="font-size:2.5rem;margin-bottom:12px;display:block;color:var(--dark-border)"></i>
            <div style="font-size:.85rem">Sin pagos registrados aún.</div>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Cuota</th>
                    <th>Fecha</th>
                    <th class="hide-mobile" style="text-align:right">Efectivo</th>
                    <th class="hide-mobile" style="text-align:right">Transfer.</th>
                    <th class="hide-mobile" style="text-align:right">Mora</th>
                    <th style="text-align:right">Total</th>
                    <th class="hide-mobile">Cobrador</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial_pagos as $hp): ?>
                <tr>
                    <td style="text-align:center;font-weight:600"><?= (int)$hp['numero_cuota'] ?></td>
                    <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($hp['fecha_jornada'])) ?></td>
                    <td class="hide-mobile" style="text-align:right">
                        <?= (float)$hp['monto_efectivo'] > 0 ? formato_pesos((float)$hp['monto_efectivo']) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td class="hide-mobile" style="text-align:right">
                        <?= (float)$hp['monto_transferencia'] > 0 ? formato_pesos((float)$hp['monto_transferencia']) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td class="hide-mobile" style="text-align:right;color:var(--danger)">
                        <?= (float)$hp['monto_mora_cobrada'] > 0 ? formato_pesos((float)$hp['monto_mora_cobrada']) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td style="text-align:right;font-weight:600;color:var(--success)"><?= formato_pesos((float)$hp['monto_total']) ?></td>
                    <td class="hide-mobile" style="font-size:.82rem;color:var(--text-muted)"><?= e($hp['cobrador_nombre']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;border-top:2px solid var(--dark-border)">
                    <td colspan="2" style="color:var(--text-muted)">Totales</td>
                    <td class="hide-mobile" style="text-align:right"><?= formato_pesos($hist_ef) ?></td>
                    <td class="hide-mobile" style="text-align:right"><?= formato_pesos($hist_tr) ?></td>
                    <td class="hide-mobile" style="text-align:right;color:var(--danger)"><?= $hist_mora > 0 ? formato_pesos($hist_mora) : '—' ?></td>
                    <td style="text-align:right;color:var(--success)"><?= formato_pesos($hist_tot) ?></td>
                    <td class="hide-mobile"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../views/layout_footer.php'; ?>
