<?php
// ============================================================
// creditos/ver.php — Cronograma de cuotas de un crédito
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index');
    exit;
}

$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono, cl.dni, cl.id AS cid,
           cl.puntaje_pago,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a,
           v.nombre AS vendedor_n, v.apellido AS vendedor_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    LEFT JOIN ic_vendedores v ON cr.vendedor_id=v.id
    WHERE cr.id=?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) {
    header('Location: index');
    exit;
}

$cuotas = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas->execute([$id]);
$lista_cuotas = $cuotas->fetchAll();

// Mapa cuota_id → pago confirmado (id, solicitud_baja, motivo_baja) para acciones admin/supervisor
$conf_map = [];
if (es_admin() || es_supervisor()) {
    $cuota_ids = array_column($lista_cuotas, 'id');
    if ($cuota_ids) {
        $placeholders = implode(',', array_fill(0, count($cuota_ids), '?'));
        $conf_rows = $pdo->prepare("
            SELECT pc.id AS pc_id, pc.cuota_id, pc.solicitud_baja, pc.motivo_baja,
                   pt.fecha_registro AS fecha_carga,
                   IFNULL(pt.origen, 'cobrador') AS origen,
                   CONCAT(u.nombre, ' ', u.apellido) AS cobrador_nombre,
                   CONCAT(ua.nombre, ' ', ua.apellido) AS aprobador_nombre,
                   ua.rol AS aprobador_rol
            FROM ic_pagos_confirmados pc
            JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
            JOIN ic_usuarios u ON u.id = pt.cobrador_id
            JOIN ic_usuarios ua ON ua.id = pc.aprobador_id
            WHERE pc.cuota_id IN ($placeholders) ORDER BY pc.id DESC
        ");
        $conf_rows->execute($cuota_ids);
        foreach ($conf_rows->fetchAll() as $pc) {
            $cid = (int) $pc['cuota_id'];
            if (!isset($conf_map[$cid])) $conf_map[$cid] = $pc; // solo el más reciente
        }
    }
}

// ── Historial completo de pagos confirmados del crédito ──────
$hist_stmt = $pdo->prepare("
    SELECT
        pc.id AS pc_id,
        cu.numero_cuota,
        cu.monto_cuota,
        cu.estado AS estado_cuota,
        pt.monto_efectivo,
        pt.monto_transferencia,
        pt.monto_mora_cobrada,
        pt.monto_total,
        pt.fecha_jornada,
        pt.fecha_registro,
        IFNULL(pt.origen, 'cobrador') AS origen,
        CONCAT(u.nombre, ' ', u.apellido)  AS cobrador_nombre,
        CONCAT(ua.nombre, ' ', ua.apellido) AS aprobador_nombre
    FROM ic_pagos_confirmados pc
    JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    JOIN ic_cuotas cu           ON cu.id = pc.cuota_id
    JOIN ic_usuarios u          ON u.id  = pt.cobrador_id
    JOIN ic_usuarios ua         ON ua.id = pc.aprobador_id
    WHERE cu.credito_id = ?
    ORDER BY pt.fecha_registro ASC
");
$hist_stmt->execute([$id]);
$historial_pagos = $hist_stmt->fetchAll();
$hist_total_ef   = array_sum(array_column($historial_pagos, 'monto_efectivo'));
$hist_total_tr   = array_sum(array_column($historial_pagos, 'monto_transferencia'));
$hist_total_mora = array_sum(array_column($historial_pagos, 'monto_mora_cobrada'));
$hist_total      = array_sum(array_column($historial_pagos, 'monto_total'));

// ── Historial de Refinanciaciones ────────────────────────────
$ref_historial = [];
try {
    $ref_stmt = $pdo->prepare("
        SELECT hr.fecha, hr.cuotas_anteriores, hr.monto_cuota_anterior,
               hr.cuotas_nuevas, hr.monto_cuota_nueva, hr.deuda_capital,
               hr.frecuencia_nueva, hr.observaciones,
               u.nombre, u.apellido
        FROM ic_historial_refinanciaciones hr
        JOIN ic_usuarios u ON hr.usuario_id = u.id
        WHERE hr.credito_id = ?
        ORDER BY hr.fecha DESC
    ");
    $ref_stmt->execute([$id]);
    $ref_historial = $ref_stmt->fetchAll();
} catch (\PDOException $e) { /* tabla puede no existir aún */ }

// ── Notas para Timeline ───────────────────────────────────────
$notas_tl = [];
if (es_admin() || es_supervisor()) {
    try {
        $notas_stmt = $pdo->prepare("
            SELECT n.nota, n.created_at, CONCAT(u.nombre,' ',u.apellido) AS autor
            FROM ic_notas_credito n
            JOIN ic_usuarios u ON n.usuario_id = u.id
            WHERE n.credito_id = ?
            ORDER BY n.created_at ASC
        ");
        $notas_stmt->execute([$id]);
        $notas_tl = $notas_stmt->fetchAll();
    } catch (\PDOException $e) {}
}

// Calcular mora actualizada para cada cuota
$hoy = new DateTime('today');
foreach ($lista_cuotas as &$cuota) {
    if (in_array($cuota['estado'], ['PENDIENTE', 'VENCIDA', 'PARCIAL'])) {
        // Para PARCIAL usar mora congelada si existe, sino calcular
        $dias = dias_atraso_habiles($cuota['fecha_vencimiento']);
        $cuota['dias_atraso_calc'] = $dias;
        $cuota['mora_calc'] = (float)$cuota['monto_mora'] > 0
            ? (float)$cuota['monto_mora']
            : calcular_mora($cuota['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
    } elseif ($cuota['estado'] === 'CAP_PAGADA') {
        // Capital pagado, mora congelada en monto_mora
        $cuota['dias_atraso_calc'] = 0;
        $cuota['mora_calc'] = (float)$cuota['monto_mora'];
    } else {
        $cuota['dias_atraso_calc'] = 0;
        $cuota['mora_calc'] = 0;
    }
}
unset($cuota);

$pagadas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PAGADA'));
$pendientes = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PENDIENTE'));
$vencidas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'VENCIDA' || ($c['estado'] === 'PENDIENTE' && $c['dias_atraso_calc'] > 0)));
$total_cuotas = count($lista_cuotas);
$porc = $total_cuotas > 0 ? round($pagadas / $total_cuotas * 100) : 0;

$total_mora_pendiente = array_sum(array_map(fn($c) => $c['mora_calc'], $lista_cuotas));
$deuda_pendiente = array_sum(array_map(fn($c) =>
    $c['estado'] === 'CAP_PAGADA' ? $c['mora_calc']
    : ($c['estado'] !== 'PAGADA'  ? $c['monto_cuota'] : 0),
    $lista_cuotas));

// #5 Progreso temporal
$pct_pagado = $porc;
$ultimo_venc = null;
foreach ($lista_cuotas as $c) {
    if ($ultimo_venc === null || $c['fecha_vencimiento'] > $ultimo_venc) $ultimo_venc = $c['fecha_vencimiento'];
}
$pct_tiempo = 0;
if (!empty($cr['primer_vencimiento']) && $ultimo_venc) {
    $dt_ini  = new DateTime($cr['primer_vencimiento']);
    $dt_fin  = new DateTime($ultimo_venc);
    $dt_hoy2 = new DateTime('today');
    $dias_total = max(1, (int)$dt_ini->diff($dt_fin)->days);
    $dias_trans = $dt_hoy2 < $dt_ini ? 0 : min($dias_total, (int)$dt_ini->diff($dt_hoy2)->days);
    $pct_tiempo = min(100, (int)round($dias_trans / $dias_total * 100));
}

// #8 Simulador
$sim_capital = 0; $sim_mora = 0;
foreach ($lista_cuotas as $c) {
    if ($c['estado'] !== 'PAGADA') {
        $sim_capital += max(0, (float)$c['monto_cuota'] - (float)($c['saldo_pagado'] ?? 0));
        $sim_mora    += (float)$c['mora_calc'];
    }
}
$sim_total = $sim_capital + $sim_mora;

// #14 Panel mora hoy
$mora_vencida_hoy  = array_sum(array_map(
    fn($c) => (float)$c['mora_calc'],
    array_filter($lista_cuotas, fn($c) => $c['mora_calc'] > 0)
));
$capital_pendiente = array_sum(array_map(
    fn($c) => max(0, (float)$c['monto_cuota'] - (float)($c['saldo_pagado'] ?? 0)),
    array_filter($lista_cuotas, fn($c) => !in_array($c['estado'], ['PAGADA']))
));

// #11 Próximas a vencer (3 días hábiles, excl. domingo)
$proximas_3d = [];
$hoy_ts = strtotime('today');
$dias_hab = 0; $limite_ts = $hoy_ts; $chk_ts = $hoy_ts;
while ($dias_hab < 3) {
    $chk_ts += 86400;
    if ((int)date('w', $chk_ts) !== 0) { $dias_hab++; $limite_ts = $chk_ts; }
}
foreach ($lista_cuotas as $c) {
    if ($c['estado'] === 'PENDIENTE') {
        $vts = strtotime($c['fecha_vencimiento']);
        if ($vts >= $hoy_ts && $vts <= $limite_ts) $proximas_3d[] = $c;
    }
}

// #4 Timeline
$timeline = [['fecha' => $cr['fecha_alta'] . ' 00:00:00', 'tipo' => 'alta', 'icon' => 'fa-star',
    'color' => 'var(--primary)', 'texto' => 'Alta del crédito',
    'sub'   => formato_pesos($cr['monto_total']) . ' — ' . $cr['cant_cuotas'] . ' cuotas ' . $cr['frecuencia']]];
foreach ($historial_pagos as $hp) {
    $timeline[] = ['fecha' => $hp['fecha_registro'], 'tipo' => 'pago', 'icon' => 'fa-dollar-sign',
        'color' => 'var(--success)', 'texto' => 'Pago cuota #' . $hp['numero_cuota'],
        'sub'   => formato_pesos($hp['monto_total']) . ' — ' . e($hp['cobrador_nombre'])];
}
foreach ($ref_historial as $rh) {
    $obs = !empty($rh['observaciones']) ? ' — ' . mb_substr($rh['observaciones'], 0, 60) : '';
    $timeline[] = ['fecha' => $rh['fecha'], 'tipo' => 'refin', 'icon' => 'fa-sync-alt',
        'color' => 'var(--warning)', 'texto' => 'Refinanciación',
        'sub'   => $rh['cuotas_nuevas'] . ' cuotas × ' . formato_pesos($rh['monto_cuota_nueva']) . $obs];
}
foreach ($notas_tl as $nt) {
    $preview = e(mb_substr($nt['nota'], 0, 80)) . (mb_strlen($nt['nota']) > 80 ? '…' : '');
    $timeline[] = ['fecha' => $nt['created_at'], 'tipo' => 'nota', 'icon' => 'fa-sticky-note',
        'color' => 'var(--text-muted)', 'texto' => 'Nota interna',
        'sub'   => $preview . ' — ' . e($nt['autor'])];
}
usort($timeline, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));

$page_title   = 'Crédito #' . $id;
$page_current = 'creditos';

// ¿Existe ya un reconocimiento de deuda para este crédito?
$recon_check = $pdo->prepare("SELECT id FROM ic_reconocimientos WHERE credito_id=?");
$recon_check->execute([$id]);
$tiene_recon = (bool) $recon_check->fetch();

$topbar_actions = '<a href="resumen_pdf.php?id=' . $id . '" target="_blank" class="btn-ic btn-ghost btn-sm"><i class="fa fa-file-pdf"></i> PDF Resumen</a> ';
if ((es_admin() || es_supervisor()) && $cr['estado'] === 'FINALIZADO') {
    $topbar_actions .= '<a href="libre_deuda_pdf.php?id=' . $id . '" target="_blank" class="btn-ic btn-success btn-sm"><i class="fa fa-stamp"></i> Libre Deuda</a> ';
}
if (es_admin() || es_supervisor()) {
    if ($tiene_recon) {
        $topbar_actions .= '<a href="reconocimiento_nuevo.php?credito_id=' . $id . '" class="btn-ic btn-ghost btn-sm"><i class="fa fa-edit"></i> Editar Recon.</a> ';
        $topbar_actions .= '<a href="reconocimiento_pdf.php?credito_id=' . $id . '" target="_blank" class="btn-ic btn-success btn-sm"><i class="fa fa-file-contract"></i> PDF Recon.</a> ';
    } else {
        $topbar_actions .= '<a href="reconocimiento_nuevo.php?credito_id=' . $id . '" class="btn-ic btn-ghost btn-sm"><i class="fa fa-file-contract"></i> Reconocimiento</a> ';
    }
}
if (es_admin()) {
    $topbar_actions .= '<a href="editar?id=' . $id . '" class="btn-ic btn-ghost btn-sm"><i class="fa fa-edit"></i> Editar</a> ';
}
if ((es_admin() || es_supervisor()) && in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $topbar_actions .= '<a href="refinanciar?id=' . $id . '" class="btn-ic btn-primary btn-sm"><i class="fa fa-sync-alt"></i> Refinanciar</a> ';
}
if (es_admin()) {
    $topbar_actions .= '<button onclick="openModal(\'modal-eliminar-credito\')" class="btn-ic btn-danger btn-sm"><i class="fa fa-trash"></i> Eliminar</button>';
}

require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <i class="fa fa-box-open kpi-icon"></i>
        <div class="kpi-label">Artículo</div>
        <div class="kpi-value" style="font-size:1rem;margin-top:8px">
            <?= e($cr['articulo']) ?>
        </div>
        <div class="kpi-sub">Crédito #
            <?= $id ?> —
            <?= badge_estado_credito($cr['estado']) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <i class="fa fa-check-circle kpi-icon"></i>
        <div class="kpi-label">Pagadas</div>
        <div class="kpi-value">
            <?= $pagadas ?>/
            <?= $total_cuotas ?>
        </div>
        <div class="kpi-sub">
            <?= $porc ?>% completado
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <i class="fa fa-clock kpi-icon"></i>
        <div class="kpi-label">Deuda Capital</div>
        <div class="kpi-value" style="font-size:1.3rem">
            <?= formato_pesos($deuda_pendiente) ?>
        </div>
        <div class="kpi-sub">
            <?= $pendientes ?> cuota
            <?= $pendientes !== 1 ? 's' : '' ?> pendiente
            <?= $pendientes !== 1 ? 's' : '' ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <i class="fa fa-fire kpi-icon"></i>
        <div class="kpi-label">Mora Acumulada</div>
        <div class="kpi-value" style="font-size:1.3rem;color:var(--danger)">
            <?= formato_pesos($total_mora_pendiente) ?>
        </div>
        <div class="kpi-sub">
            <?= $vencidas ?> cuota
            <?= $vencidas !== 1 ? 's' : '' ?> vencida
            <?= $vencidas !== 1 ? 's' : '' ?>
        </div>
    </div>
</div>

<?php if (!empty($proximas_3d)): ?>
<div class="alert-ic alert-warning mb-3" style="display:flex;align-items:center;gap:10px">
    <i class="fa fa-bell" style="font-size:1.1rem;flex-shrink:0"></i>
    <span>
        <strong><?= count($proximas_3d) ?> cuota<?= count($proximas_3d) > 1 ? 's' : '' ?></strong>
        vence<?= count($proximas_3d) > 1 ? 'n' : '' ?> en los próximos 3 días hábiles:
        <?= implode(', ', array_map(fn($c) => '#' . $c['numero_cuota'] . ' (' . date('d/m', strtotime($c['fecha_vencimiento'])) . ')', $proximas_3d)) ?>
    </span>
</div>
<?php endif; ?>

<?php if ($mora_vencida_hoy > 0 || $capital_pendiente > 0): ?>
<div class="card-ic mb-4" style="border-left:4px solid var(--danger)">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-fire"></i> Estado de Deuda Hoy</span>
        <span style="font-size:.78rem;color:var(--text-muted)"><?= date('d/m/Y') ?></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0;padding:16px 20px">
        <div style="text-align:center">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">Capital Pendiente</div>
            <div style="font-size:1.4rem;font-weight:800;color:var(--warning)"><?= formato_pesos($capital_pendiente) ?></div>
        </div>
        <div style="text-align:center;border-left:1px solid var(--dark-border);border-right:1px solid var(--dark-border)">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">Mora a Hoy</div>
            <div style="font-size:1.4rem;font-weight:800;color:var(--danger)"><?= formato_pesos($mora_vencida_hoy) ?></div>
        </div>
        <div style="text-align:center">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">Total si Paga Hoy</div>
            <div style="font-size:1.4rem;font-weight:800;color:var(--primary)"><?= formato_pesos($capital_pendiente + $mora_vencida_hoy) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">

    <!-- SIDEBAR DEL CRÉDITO -->
    <div>
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title">Datos del Crédito</span></div>
            <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                <tr>
                    <td class="text-muted" style="padding:5px 0;width=45%">Cliente</td>
                    <td>
                        <a href="../clientes/ver?id=<?= $cr['cid'] ?>" class="fw-bold">
                            <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                        </a>
                        <?php if (!empty($cr['puntaje_pago'])): ?>
                            <?= badge_puntaje_pago((int)$cr['puntaje_pago']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($cr['dni'])): ?>
                <tr>
                    <td class="text-muted" style="padding:5px 0">DNI</td>
                    <td><?= e($cr['dni']) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Artículo</td>
                    <td>
                        <?= e($cr['articulo']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Precio art.</td>
                    <td>
                        <?= formato_pesos($cr['precio_articulo']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Interés</td>
                    <td>
                        <?= $cr['interes_pct'] ?>%
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Monto total</td>
                    <td class="fw-bold">
                        <?= formato_pesos($cr['monto_total']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Monto cuota</td>
                    <td>
                        <?= formato_pesos($cr['monto_cuota']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cuotas</td>
                    <td>
                        <?= $cr['cant_cuotas'] ?> (
                        <?= $cr['frecuencia'] ?>)
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Mora/sem.</td>
                    <td>
                        <?= $cr['interes_moratorio_pct'] ?>%
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Alta</td>
                    <td>
                        <?= date('d/m/Y', strtotime($cr['fecha_alta'])) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">1er venc.</td>
                    <td>
                        <?= date('d/m/Y', strtotime($cr['primer_vencimiento'])) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cobrador</td>
                    <td>
                        <?= e($cr['cobrador_n'] . ' ' . $cr['cobrador_a']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Vendedor</td>
                    <td>
                        <?= !empty($cr['vendedor_n']) ? e($cr['vendedor_n'] . ' ' . $cr['vendedor_a']) : '<span class="text-muted">No asignado</span>' ?>
                        <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                            <a href="cambiar_vendedor?id=<?= $id ?>" class="btn-ic btn-ghost btn-sm" title="Cambiar vendedor" style="padding:2px 5px; font-size:.7rem; margin-left:10px;"><i class="fa fa-edit"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($cr['veces_refinanciado']) && (int)$cr['veces_refinanciado'] > 0): ?>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Refinanciaciones</td>
                    <td>
                        <span class="badge-ic badge-warning"><?= (int)$cr['veces_refinanciado'] ?> vez<?= (int)$cr['veces_refinanciado'] > 1 ? 'ces' : '' ?></span>
                        <?php if (!empty($cr['fecha_ultima_refinanciacion'])): ?>
                            <span class="text-muted" style="font-size:.78rem;margin-left:6px">
                                Última: <?= date('d/m/Y', strtotime($cr['fecha_ultima_refinanciacion'])) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <hr class="divider">
            <!-- Barra de progreso doble (#5) -->
            <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-muted);margin-bottom:4px">
                <span><span style="color:var(--success)">●</span> <?= $pct_pagado ?>% pagado</span>
                <span><span style="color:rgba(245,158,11,.8)">●</span> <?= $pct_tiempo ?>% del tiempo</span>
            </div>
            <div style="background:var(--dark-border);border-radius:6px;height:10px;position:relative;overflow:hidden">
                <div style="position:absolute;top:0;left:0;width:<?= $pct_tiempo ?>%;height:100%;background:rgba(245,158,11,.3);border-radius:6px"></div>
                <div style="position:absolute;top:0;left:0;width:<?= $pct_pagado ?>%;height:100%;background:var(--success);border-radius:6px;transition:width .4s"></div>
            </div>
            <?php if ($pct_tiempo > $pct_pagado + 10): ?>
                <div style="font-size:.7rem;color:var(--warning);margin-top:3px"><i class="fa fa-exclamation-triangle"></i> El tiempo avanza más rápido que los pagos</div>
            <?php elseif ($pct_pagado >= $pct_tiempo && $pct_pagado > 0): ?>
                <div style="font-size:.7rem;color:var(--success);margin-top:3px"><i class="fa fa-check-circle"></i> Al día con los pagos</div>
            <?php endif; ?>
            <hr class="divider">
            <div class="d-flex gap-2" style="flex-wrap:wrap">
                <a href="cronograma_pdf.php?id=<?= $id ?>" target="_blank" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-print"></i> PDF / Imprimir
                </a>
                <a href="../clientes/ver?id=<?= $cr['cid'] ?>" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-user"></i> Cliente
                </a>
                <?php if ($sim_total > 0): ?>
                <button onclick="openModal('modal-simulador')" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-calculator"></i> ¿Cuánto debe?
                </button>
                <?php endif; ?>
                <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                    <a href="finalizar?id=<?= $id ?>" class="btn-ic btn-danger btn-sm" title="Finalizar Crédito">
                        <i class="fa fa-power-off"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- CRONOGRAMA -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-calendar-alt"></i> Cronograma de Cuotas</span>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Vencimiento</th>
                        <th>Monto Cuota</th>
                        <th>Días Atraso</th>
                        <th>Mora</th>
                        <th>Total a Pagar</th>
                        <th>Estado</th>
                        <th>Pagado</th>
                        <?php if (es_admin() || es_supervisor()): ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_cuotas as $q): ?>
                        <?php
                        $esAtrasada = $q['dias_atraso_calc'] > 0;
                        $pc_info    = $conf_map[$q['id']] ?? null;
                        $pc_id      = $pc_info ? (int) $pc_info['pc_id'] : 0;
                        $sol_baja   = $pc_info ? (int) $pc_info['solicitud_baja'] : 0;
                        $mot_baja   = $pc_info ? $pc_info['motivo_baja'] : '';
                        $rowStyle   = '';
                        if ($sol_baja && $q['estado'] === 'PAGADA')
                            $rowStyle = 'background:rgba(245,158,11,.07);border-left:3px solid var(--warning)';
                        elseif ($esAtrasada && $q['estado'] !== 'PAGADA')
                            $rowStyle = 'background:rgba(239,68,68,.05)';
                        ?>
                        <tr style="<?= $rowStyle ?>">
                            <td class="text-muted">
                                <?= $q['numero_cuota'] ?>
                            </td>
                            <td class="nowrap <?= $esAtrasada && $q['estado'] !== 'PAGADA' ? 'text-danger' : '' ?>">
                                <?= date('d/m/Y', strtotime($q['fecha_vencimiento'])) ?>
                            </td>
                            <td class="nowrap">
                                <?= formato_pesos($q['monto_cuota']) ?>
                            </td>
                            <td class="text-center <?= $q['dias_atraso_calc'] > 0 ? 'text-warning' : '' ?>">
                                <?= $q['dias_atraso_calc'] > 0 ? $q['dias_atraso_calc'] . ' hábiles' : '—' ?>
                            </td>
                            <td class="nowrap <?= $q['mora_calc'] > 0 ? 'text-danger' : '' ?>">
                                <?= $q['mora_calc'] > 0 ? formato_pesos($q['mora_calc']) : '—' ?>
                            </td>
                            <td class="nowrap fw-bold">
                                <?php if ($q['estado'] === 'PAGADA'): ?>
                                    <?= formato_pesos($q['monto_cuota'] + $q['mora_calc']) ?>
                                <?php elseif ($q['estado'] === 'CAP_PAGADA'): ?>
                                    <span style="color:var(--warning)"><?= formato_pesos($q['mora_calc']) ?></span>
                                    <br><span class="text-muted" style="font-size:.70rem;font-weight:normal">mora pendiente</span>
                                <?php elseif ($q['estado'] === 'PARCIAL' && !empty($q['saldo_pagado'])): ?>
                                    <?= formato_pesos($q['monto_cuota'] + $q['mora_calc']) ?>
                                    <br><span class="text-success" style="font-size:.70rem;font-weight:normal">A favor: <?= formato_pesos($q['saldo_pagado']) ?></span>
                                    <br><span class="text-warning" style="font-size:.70rem;">Resta: <?= formato_pesos(max(0, ($q['monto_cuota'] + $q['mora_calc']) - $q['saldo_pagado'])) ?></span>
                                <?php else: ?>
                                    <?= formato_pesos($q['monto_cuota'] + $q['mora_calc']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $badgeMap = ['PENDIENTE' => 'badge-warning', 'PAGADA' => 'badge-success', 'VENCIDA' => 'badge-danger', 'PARCIAL' => 'badge-primary']; ?>
                                <?php if ($q['estado'] === 'CAP_PAGADA'): ?>
                                    <span class="badge-ic badge-success">Capital ✓</span>
                                    <br><span class="badge-ic badge-warning" style="font-size:.65rem;margin-top:3px">
                                        Mora <?= formato_pesos($q['mora_calc']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-ic <?= $badgeMap[$q['estado']] ?? 'badge-muted' ?>">
                                        <?= $q['estado'] ?>
                                    </span>
                                    <?php if ($sol_baja && $q['estado'] === 'PAGADA'): ?>
                                        <br><span class="badge-ic badge-warning" style="font-size:.65rem;margin-top:2px"
                                            title="<?= e($mot_baja) ?>"><i class="fa fa-flag"></i> Solicitud baja</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?php if ($q['estado'] === 'PAGADA' && !empty($q['saldo_pagado'])): ?>
                                    <span class="text-success fw-bold"><?= formato_pesos($q['saldo_pagado']) ?></span>
                                    <?php if ($q['fecha_pago']): ?>
                                        <br><span class="text-muted" style="font-size:.75rem"><?= date('d/m/Y', strtotime($q['fecha_pago'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($pc_info['fecha_carga'])): ?>
                                        <br><span class="text-muted" style="font-size:.7rem" title="Fecha y hora en que se cargó el pago al sistema">
                                            <i class="fa fa-clock"></i> <?= date('d/m/Y H:i', strtotime($pc_info['fecha_carga'])) ?>
                                        </span>
                                        <?php $es_manual = ($pc_info['origen'] ?? 'cobrador') === 'manual'; ?>
                                        <br><span class="text-muted" style="font-size:.7rem">
                                            <i class="fa fa-user"></i> <?= e($es_manual ? $pc_info['aprobador_nombre'] : $pc_info['cobrador_nombre']) ?>
                                            <?php if ($es_manual): ?>
                                                <span style="background:rgba(99,102,241,.2);color:#a5b4fc;font-size:.65rem;padding:1px 5px;border-radius:4px;margin-left:3px">Manual</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($q['estado'] === 'CAP_PAGADA' && !empty($q['saldo_pagado'])): ?>
                                    <span class="text-success fw-bold"><?= formato_pesos($q['saldo_pagado']) ?></span>
                                    <br><span class="text-warning" style="font-size:.75rem">capital pagado</span>
                                    <?php if (!empty($pc_info['fecha_carga'])): ?>
                                        <br><span class="text-muted" style="font-size:.7rem" title="Fecha y hora en que se cargó el pago al sistema">
                                            <i class="fa fa-clock"></i> <?= date('d/m/Y H:i', strtotime($pc_info['fecha_carga'])) ?>
                                        </span>
                                        <?php $es_manual = ($pc_info['origen'] ?? 'cobrador') === 'manual'; ?>
                                        <br><span class="text-muted" style="font-size:.7rem">
                                            <i class="fa fa-user"></i> <?= e($es_manual ? $pc_info['aprobador_nombre'] : $pc_info['cobrador_nombre']) ?>
                                            <?php if ($es_manual): ?>
                                                <span style="background:rgba(99,102,241,.2);color:#a5b4fc;font-size:.65rem;padding:1px 5px;border-radius:4px;margin-left:3px">Manual</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($q['estado'] === 'PARCIAL' && !empty($q['saldo_pagado'])): ?>
                                    <span class="text-warning fw-bold"><?= formato_pesos($q['saldo_pagado']) ?></span>
                                    <br><span class="text-muted" style="font-size:.75rem">a cuenta</span>
                                    <?php if (!empty($pc_info['fecha_carga'])): ?>
                                        <br><span class="text-muted" style="font-size:.7rem" title="Fecha y hora en que se cargó el pago al sistema">
                                            <i class="fa fa-clock"></i> <?= date('d/m/Y H:i', strtotime($pc_info['fecha_carga'])) ?>
                                        </span>
                                        <?php $es_manual = ($pc_info['origen'] ?? 'cobrador') === 'manual'; ?>
                                        <br><span class="text-muted" style="font-size:.7rem">
                                            <i class="fa fa-user"></i> <?= e($es_manual ? $pc_info['aprobador_nombre'] : $pc_info['cobrador_nombre']) ?>
                                            <?php if ($es_manual): ?>
                                                <span style="background:rgba(99,102,241,.2);color:#a5b4fc;font-size:.65rem;padding:1px 5px;border-radius:4px;margin-left:3px">Manual</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if (es_admin() || es_supervisor()): ?>
                            <td class="nowrap" style="display:flex;gap:4px;align-items:center">
                                <?php if (in_array($q['estado'], ['PENDIENTE', 'VENCIDA', 'PARCIAL', 'CAP_PAGADA'])): ?>
                                    <button
                                        onclick="abrirPagoDirecto(<?= $q['id'] ?>, <?= $q['numero_cuota'] ?>, <?= (float)$q['monto_cuota'] ?>, '<?= $q['fecha_vencimiento'] ?>', <?= (float)($q['saldo_pagado'] ?? 0) ?>, <?= (float)($q['monto_mora'] ?? 0) ?>)"
                                        class="btn-ic <?= $q['estado']==='PARCIAL' ? 'btn-warning' : 'btn-success' ?> btn-sm"
                                        title="<?= $q['estado']==='PARCIAL' ? 'Completar pago parcial' : 'Registrar pago directo' ?>">
                                        <i class="fa fa-dollar-sign"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($q['estado'] === 'CAP_PAGADA' && $q['mora_calc'] > 0): ?>
                                    <button
                                        onclick="abrirCondonarMora(<?= $q['id'] ?>, <?= $q['numero_cuota'] ?>, <?= $q['mora_calc'] ?>)"
                                        class="btn-ic btn-warning btn-sm"
                                        title="Condonar mora congelada">
                                        <i class="fa fa-times-circle"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (in_array($q['estado'], ['PAGADA', 'PARCIAL']) && $pc_id): ?>
                                    <?php if (es_admin()): ?>
                                        <button
                                            onclick="abrirRevertir(<?= $pc_id ?>, <?= $q['numero_cuota'] ?>, <?= $sol_baja ?>)"
                                            class="btn-ic btn-sm <?= $sol_baja ? 'btn-warning' : 'btn-danger' ?>"
                                            title="<?= $sol_baja ? 'Reversa solicitada — Revertir' : 'Revertir último pago' ?>">
                                            <i class="fa fa-undo"></i>
                                        </button>
                                    <?php elseif (es_supervisor()): ?>
                                        <?php if (!$sol_baja): ?>
                                            <button
                                                onclick="abrirSolBaja(<?= $pc_id ?>, <?= $q['numero_cuota'] ?>)"
                                                class="btn-ic btn-warning btn-sm" title="Solicitar reversa">
                                                <i class="fa fa-flag"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-warning" style="font-size:.75rem" title="Solicitud enviada — pendiente de admin">
                                                <i class="fa fa-clock"></i>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($historial_pagos)): ?>
<!-- HISTORIAL DE PAGOS -->
<div class="card-ic mt-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-history"></i> Historial de Pagos</span>
        <span style="font-size:.78rem;color:var(--text-muted)">
            <?= count($historial_pagos) ?> pago<?= count($historial_pagos) !== 1 ? 's' : '' ?> confirmado<?= count($historial_pagos) !== 1 ? 's' : '' ?>
            — Total cobrado: <strong style="color:var(--success)"><?= formato_pesos($hist_total) ?></strong>
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic" style="font-size:.85rem">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha Jornada</th>
                    <th>Cuota</th>
                    <th style="text-align:right">Efectivo</th>
                    <th style="text-align:right">Transferencia</th>
                    <th style="text-align:right">Mora</th>
                    <th style="text-align:right">Total</th>
                    <th>Cobrador / Aprobador</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial_pagos as $i => $h): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td class="nowrap">
                        <?= date('d/m/Y', strtotime($h['fecha_jornada'])) ?>
                        <br><span class="text-muted" style="font-size:.72rem">
                            <?= date('d/m/Y H:i', strtotime($h['fecha_registro'])) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-secondary">#<?= $h['numero_cuota'] ?></span>
                        <?php if ($h['estado_cuota'] === 'PARCIAL'): ?>
                            <br><span style="font-size:.7rem;color:var(--warning)">parcial</span>
                        <?php elseif ($h['estado_cuota'] === 'CAP_PAGADA'): ?>
                            <br><span style="font-size:.7rem;color:#60a5fa">cap. pagado</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?= $h['monto_efectivo'] > 0 ? formato_pesos($h['monto_efectivo']) : '—' ?>
                    </td>
                    <td style="text-align:right">
                        <?= $h['monto_transferencia'] > 0 ? formato_pesos($h['monto_transferencia']) : '—' ?>
                    </td>
                    <td style="text-align:right;<?= $h['monto_mora_cobrada'] > 0 ? 'color:var(--danger)' : '' ?>">
                        <?= $h['monto_mora_cobrada'] > 0 ? formato_pesos($h['monto_mora_cobrada']) : '—' ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:var(--success)">
                        <?= formato_pesos($h['monto_total']) ?>
                    </td>
                    <td style="font-size:.78rem">
                        <?php if ($h['origen'] === 'manual'): ?>
                            <span style="background:rgba(99,102,241,.2);color:#a5b4fc;font-size:.65rem;padding:1px 5px;border-radius:4px">Manual</span>
                            <br><?= e($h['aprobador_nombre']) ?>
                        <?php else: ?>
                            <i class="fa fa-user" style="opacity:.5"></i> <?= e($h['cobrador_nombre']) ?>
                            <br><span class="text-muted">Aprobó: <?= e($h['aprobador_nombre']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(79,70,229,.08);font-weight:700">
                    <td colspan="3" style="text-align:right;font-size:.8rem;padding-right:10px">TOTAL</td>
                    <td style="text-align:right;color:var(--success)"><?= formato_pesos($hist_total_ef) ?></td>
                    <td style="text-align:right;color:var(--primary-light)"><?= formato_pesos($hist_total_tr) ?></td>
                    <td style="text-align:right;color:var(--danger)"><?= $hist_total_mora > 0 ? formato_pesos($hist_total_mora) : '—' ?></td>
                    <td style="text-align:right;color:var(--success)"><?= formato_pesos($hist_total) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($ref_historial)): ?>
<!-- HISTORIAL DE REFINANCIACIONES (#7/#I) -->
<div class="card-ic mt-4">
    <div class="card-ic-header" style="cursor:pointer;user-select:none"
         onclick="const b=this.nextElementSibling;b.style.display=b.style.display==='none'?'block':'none'">
        <span class="card-title"><i class="fa fa-sync-alt"></i> Historial de Refinanciaciones</span>
        <span><span class="badge-ic badge-warning"><?= count($ref_historial) ?></span>
        <i class="fa fa-chevron-down" style="font-size:.75rem;margin-left:6px;opacity:.5"></i></span>
    </div>
    <div>
        <div style="overflow-x:auto">
            <table class="table-ic" style="font-size:.85rem">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th style="text-align:center">Cuotas ant.</th>
                        <th>Cuota ant. $</th>
                        <th style="text-align:center">Cuotas nuevas</th>
                        <th>Cuota nueva $</th>
                        <th>Capital ref.</th>
                        <th>Frecuencia</th>
                        <th>Usuario</th>
                        <th>Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ref_historial as $rh): ?>
                    <tr>
                        <td class="nowrap"><?= date('d/m/Y H:i', strtotime($rh['fecha'])) ?></td>
                        <td class="text-center"><?= (int)$rh['cuotas_anteriores'] ?></td>
                        <td class="nowrap"><?= formato_pesos($rh['monto_cuota_anterior']) ?></td>
                        <td class="text-center fw-bold"><?= (int)$rh['cuotas_nuevas'] ?></td>
                        <td class="nowrap fw-bold"><?= formato_pesos($rh['monto_cuota_nueva']) ?></td>
                        <td class="nowrap"><?= formato_pesos($rh['deuda_capital']) ?></td>
                        <td><?= ucfirst($rh['frecuencia_nueva']) ?></td>
                        <td><?= e($rh['nombre'] . ' ' . $rh['apellido']) ?></td>
                        <td style="max-width:200px;font-size:.78rem;color:var(--text-muted)">
                            <?= !empty($rh['observaciones']) ? e($rh['observaciones']) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (es_admin()): ?>
<!-- MODAL REVERTIR PAGO (admin) -->
<div class="modal-overlay" id="modal-revertir">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-undo"></i> Revertir Pago</div>
            <button class="modal-close" onclick="closeModal('modal-revertir')">✕</button>
        </div>
        <div id="info-revertir"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="gestionar_pago" class="form-ic">
            <input type="hidden" name="accion" value="revertir_confirmado">
            <input type="hidden" name="pago_conf_id" id="rev_pc_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-danger w-100" style="justify-content:center">
                    <i class="fa fa-undo"></i> Confirmar Reversa
                </button>
                <button type="button" onclick="closeModal('modal-revertir')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php elseif (es_supervisor()): ?>
<!-- MODAL SOLICITAR REVERSA (supervisor) -->
<div class="modal-overlay" id="modal-sol-baja-conf">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-flag"></i> Solicitar Reversa de Pago</div>
            <button class="modal-close" onclick="closeModal('modal-sol-baja-conf')">✕</button>
        </div>
        <div id="info-sol-baja"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:14px;font-size:.875rem"></div>
        <form method="POST" action="gestionar_pago" class="form-ic">
            <input type="hidden" name="accion" value="solicitar_baja_confirmado">
            <input type="hidden" name="pago_conf_id" id="sol_pc_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="form-group mb-4">
                <label>Motivo *</label>
                <textarea name="motivo" rows="3" required
                    placeholder="Ej: Pago duplicado, error de importe, cliente equivocado..."
                    style="resize:vertical"></textarea>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-warning w-100" style="justify-content:center">
                    <i class="fa fa-paper-plane"></i> Enviar Solicitud
                </button>
                <button type="button" onclick="closeModal('modal-sol-baja-conf')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (es_admin() || es_supervisor()): ?>
<!-- MODAL CONDONAR MORA -->
<div class="modal-overlay" id="modal-condonar-mora">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-times-circle"></i> Condonar Mora</div>
            <button class="modal-close" onclick="closeModal('modal-condonar-mora')">✕</button>
        </div>
        <div id="info-condonar"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="condonar_mora" class="form-ic">
            <input type="hidden" name="cuota_id" id="cond_cuota_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-warning w-100" style="justify-content:center">
                    <i class="fa fa-check"></i> Confirmar Condonación
                </button>
                <button type="button" onclick="closeModal('modal-condonar-mora')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PAGO DIRECTO -->
<div class="modal-overlay" id="modal-pago-directo">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-dollar-sign"></i> Registrar Pago</div>
            <button class="modal-close" onclick="closeModal('modal-pago-directo')">✕</button>
        </div>
        <div id="info-pago-dir"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="pagar_cuota" class="form-ic">
            <input type="hidden" name="cuota_id" id="dir_cuota_id">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="form-grid">
                <div class="form-group" style="grid-column:span 2">
                    <label>Fecha de Pago</label>
                    <input type="date" name="fecha_pago" id="dir_fecha_pago"
                           value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"
                           onchange="recalcularMoraDirecto()">
                    <small class="text-muted">Si el pago cae dentro del período la mora desaparece automáticamente.</small>
                </div>
                <div class="form-group">
                    <label>Efectivo $</label>
                    <input type="number" name="monto_efectivo" id="dir_efectivo"
                        step="0.01" min="0" value="0" oninput="dirTotal()">
                </div>
                <div class="form-group">
                    <label>Transferencia $</label>
                    <input type="number" name="monto_transferencia" id="dir_transfer"
                        step="0.01" min="0" value="0" oninput="dirTotal()">
                </div>
            </div>
            <div style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <span class="text-muted">Total:</span>
                <span id="dir_total" style="font-size:1.2rem;font-weight:800;color:var(--success)">$ 0,00</span>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-success w-100" style="justify-content:center">
                    <i class="fa fa-save"></i> Confirmar Pago
                </button>
                <button type="button" onclick="closeModal('modal-pago-directo')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL SIMULADOR (#8) -->
<div class="modal-overlay" id="modal-simulador">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-calculator"></i> ¿Cuánto debe hoy?</div>
            <button class="modal-close" onclick="closeModal('modal-simulador')">✕</button>
        </div>
        <div style="padding:4px 0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div style="background:rgba(0,0,0,.25);border-radius:8px;padding:14px;text-align:center">
                    <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">Capital pendiente</div>
                    <div style="font-size:1.3rem;font-weight:800;color:var(--warning)"><?= formato_pesos($sim_capital) ?></div>
                </div>
                <div style="background:rgba(0,0,0,.25);border-radius:8px;padding:14px;text-align:center">
                    <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px">Mora acumulada</div>
                    <div style="font-size:1.3rem;font-weight:800;color:var(--danger)"><?= formato_pesos($sim_mora) ?></div>
                </div>
            </div>
            <div style="background:rgba(79,70,229,.15);border:1px solid rgba(79,70,229,.4);border-radius:8px;padding:16px;text-align:center;margin-bottom:14px">
                <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:4px">TOTAL A PAGAR HOY</div>
                <div style="font-size:2rem;font-weight:900;color:var(--primary)"><?= formato_pesos($sim_total) ?></div>
            </div>
            <p style="font-size:.74rem;color:var(--text-muted);margin:0">
                <i class="fa fa-info-circle"></i> Calculado al <?= date('d/m/Y') ?>.
                La mora aumenta cada día hábil. El monto puede variar si se realizan pagos.
            </p>
        </div>
    </div>
</div>

<?php
$page_scripts = <<<JS
<script>
const PCT_MORA_SEMANAL = {$cr['interes_moratorio_pct']};
let _dir = {};

function diasHabilesAtraso(vencStr, refStr) {
    const venc = new Date(vencStr + 'T00:00:00');
    const ref  = new Date(refStr  + 'T00:00:00');
    if (ref <= venc) return 0;
    let count = 0, cur = new Date(venc.getTime());
    cur.setDate(cur.getDate() + 1);
    while (cur <= ref) {
        if (cur.getDay() !== 0) count++;
        cur.setDate(cur.getDate() + 1);
    }
    return count;
}

function calcularMoraJS(capital, vencStr, fechaRef) {
    const dias = diasHabilesAtraso(vencStr, fechaRef);
    if (dias <= 0) return 0;
    return Math.round(capital * (PCT_MORA_SEMANAL / 6 / 100) * dias * 100) / 100;
}

function abrirPagoDirecto(cuota_id, num_cuota, capital, vencimiento, saldo_prev, mora_frozen) {
    _dir = { cuota_id, num_cuota, capital, vencimiento,
             saldo_prev: saldo_prev || 0, mora_frozen: mora_frozen || 0 };
    document.getElementById('dir_cuota_id').value = cuota_id;
    const hoy = new Date().toISOString().split('T')[0];
    document.getElementById('dir_fecha_pago').value = hoy;
    document.getElementById('dir_fecha_pago').max   = hoy;
    document.getElementById('dir_transfer').value = '0';
    recalcularMoraDirecto();
    openModal('modal-pago-directo');
}

function recalcularMoraDirecto() {
    const fecha = document.getElementById('dir_fecha_pago').value
                  || new Date().toISOString().split('T')[0];
    const { capital, vencimiento, saldo_prev, mora_frozen, num_cuota } = _dir;
    const mora     = mora_frozen > 0 ? mora_frozen : calcularMoraJS(capital, vencimiento, fecha);
    const pendiente = Math.max(0, capital + mora - saldo_prev);

    document.getElementById('dir_efectivo').value = pendiente.toFixed(2);

    let info = 'Cuota <strong>#' + num_cuota + '</strong><br>Capital: ' + formatPesos(capital);
    if (mora > 0) info += ' + Mora: <span style="color:var(--danger)">' + formatPesos(mora) + '</span>';
    if (saldo_prev > 0) info += '<br><span style="color:var(--warning)"><i class="fa fa-info-circle"></i> Ya abonado: ' + formatPesos(saldo_prev) + '</span>';
    info += '<br><strong>Total sugerido: ' + formatPesos(pendiente) + '</strong>';
    document.getElementById('info-pago-dir').innerHTML = info;
    dirTotal();
}

function dirTotal() {
    const ef = parseFloat(document.getElementById('dir_efectivo').value) || 0;
    const tr = parseFloat(document.getElementById('dir_transfer').value) || 0;
    document.getElementById('dir_total').textContent = formatPesos(ef + tr);
}

// Admin: revertir pago confirmado
function abrirRevertir(pc_id, num_cuota, sol_baja) {
    document.getElementById('rev_pc_id').value = pc_id;
    document.getElementById('info-revertir').innerHTML =
        'Se revertirá el pago de la <strong>Cuota #' + num_cuota + '</strong>.' +
        (sol_baja ? '<br><span style="color:var(--warning)"><i class="fa fa-flag"></i> Hay una solicitud de reversa del supervisor.</span>' : '') +
        '<br><span style="color:var(--danger);font-size:.82rem">La cuota volverá a PENDIENTE o VENCIDA y el pago quedará anulado.</span>';
    openModal('modal-revertir');
}

// Admin/supervisor: condonar mora congelada (CAP_PAGADA)
function abrirCondonarMora(cuota_id, num_cuota, mora) {
    document.getElementById('cond_cuota_id').value = cuota_id;
    document.getElementById('info-condonar').innerHTML =
        'Se condonará la mora de la <strong>Cuota #' + num_cuota + '</strong>.<br>' +
        'Mora a eliminar: <span style="color:var(--warning);font-weight:700">' + formatPesos(mora) + '</span><br>' +
        '<span style="font-size:.82rem;color:var(--text-muted)">La cuota pasará a estado PAGADA sin cobrar la mora. Esta acción no se puede deshacer.</span>';
    openModal('modal-condonar-mora');
}

// Supervisor: solicitar reversa de pago confirmado
function abrirSolBaja(pc_id, num_cuota) {
    document.getElementById('sol_pc_id').value = pc_id;
    document.getElementById('info-sol-baja').innerHTML =
        'Solicitar reversa del pago de la <strong>Cuota #' + num_cuota + '</strong>.<br>' +
        '<span style="font-size:.82rem;color:var(--text-muted)">El administrador revisará la solicitud y decidirá si revierte el pago.</span>';
    openModal('modal-sol-baja-conf');
}
</script>
JS;
?>

<?php if (es_admin()): ?>
<!-- MODAL ELIMINAR CRÉDITO -->
<div class="modal-overlay" id="modal-eliminar-credito">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--danger)"><i class="fa fa-trash"></i> Eliminar Crédito</div>
            <button class="modal-close" onclick="closeModal('modal-eliminar-credito')">✕</button>
        </div>
        <div style="background:rgba(220,53,69,.1);border:1px solid rgba(220,53,69,.3);border-radius:8px;padding:14px;margin-bottom:16px;font-size:.9rem">
            <p style="margin:0 0 8px"><strong>¿Eliminar el crédito #<?= $id ?>?</strong></p>
            <p style="margin:0 0 6px">Cliente: <strong><?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?></strong></p>
            <p style="margin:0 0 6px">Artículo: <?= e($cr['articulo']) ?></p>
            <p style="margin:0;color:var(--danger);font-weight:600"><i class="fa fa-exclamation-triangle"></i>
                Esta acción es irreversible. Solo se puede eliminar si el crédito no tiene pagos confirmados.
            </p>
        </div>
        <form method="POST" action="eliminar">
            <input type="hidden" name="credito_id" value="<?= $id ?>">
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-danger w-100" style="justify-content:center">
                    <i class="fa fa-trash"></i> Sí, eliminar crédito
                </button>
                <button type="button" onclick="closeModal('modal-eliminar-credito')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($timeline)): ?>
<!-- TIMELINE (#4) -->
<div class="card-ic mt-4">
    <div class="card-ic-header" style="cursor:pointer;user-select:none"
         onclick="const b=this.nextElementSibling;b.style.display=b.style.display==='none'?'block':'none'">
        <span class="card-title"><i class="fa fa-stream"></i> Línea de Tiempo</span>
        <span><span class="badge-ic" style="background:rgba(255,255,255,.1)"><?= count($timeline) ?> eventos</span>
        <i class="fa fa-chevron-down" style="font-size:.75rem;margin-left:6px;opacity:.5"></i></span>
    </div>
    <div style="display:none">
        <div style="padding:16px 20px">
            <?php foreach ($timeline as $ev): ?>
            <div style="display:flex;gap:14px;margin-bottom:16px;align-items:flex-start">
                <div style="flex-shrink:0;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.06);
                            border:2px solid <?= $ev['color'] ?>;display:flex;align-items:center;justify-content:center">
                    <i class="fa <?= $ev['icon'] ?>" style="font-size:.75rem;color:<?= $ev['color'] ?>"></i>
                </div>
                <div style="flex:1;padding-top:4px">
                    <div style="font-weight:600;font-size:.875rem"><?= $ev['texto'] ?></div>
                    <div style="font-size:.78rem;color:var(--text-muted);margin-top:1px"><?= $ev['sub'] ?></div>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px;opacity:.7">
                        <i class="fa fa-clock"></i> <?= date('d/m/Y H:i', strtotime($ev['fecha'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (es_admin() || es_supervisor()): ?>
<!-- NOTAS INTERNAS DEL CRÉDITO -->
<div class="card-ic mt-4" id="notas-credito-section">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-sticky-note"></i> Notas Internas</span>
        <span style="font-size:.78rem;color:var(--text-muted)">Solo visibles para admin y supervisores</span>
    </div>
    <div id="notas-lista" style="padding:12px;min-height:40px">
        <p class="text-muted" style="font-size:.85rem">Cargando notas...</p>
    </div>
    <div style="padding:12px;border-top:1px solid var(--dark-border)">
        <form id="form-nota" style="display:flex;gap:8px;align-items:flex-end">
            <div style="flex:1">
                <textarea id="nota-texto" rows="2" maxlength="500"
                    placeholder="Agregar nota interna sobre este crédito..."
                    style="width:100%;resize:vertical;font-size:.875rem"></textarea>
            </div>
            <button type="submit" class="btn-ic btn-primary btn-sm" style="align-self:flex-end">
                <i class="fa fa-paper-plane"></i> Guardar
            </button>
        </form>
    </div>
</div>
<script>
(function() {
    const CID = <?= $id ?>;
    const BASE = '<?= BASE_URL ?>';
    const esAdmin = <?= es_admin() ? 'true' : 'false' ?>;

    function cargarNotas() {
        fetch(BASE + 'creditos/notas_ajax?credito_id=' + CID)
            .then(r => r.json())
            .then(notas => {
                const el = document.getElementById('notas-lista');
                if (!notas.length) {
                    el.innerHTML = '<p class="text-muted" style="font-size:.85rem;padding:4px 0">Sin notas aún.</p>';
                    return;
                }
                el.innerHTML = notas.map(n => `
                    <div style="padding:8px 0;border-bottom:1px solid var(--dark-border);display:flex;gap:10px;align-items:start">
                        <div style="flex:1">
                            <div style="font-size:.82rem;margin-bottom:2px">${n.nota.replace(/\n/g,'<br>')}</div>
                            <div class="text-muted" style="font-size:.72rem">${n.autor} — ${n.created_at}</div>
                        </div>
                        ${esAdmin ? `<button onclick="eliminarNota(${n.id})" class="btn-ic btn-ghost btn-icon btn-sm" style="opacity:.5;flex-shrink:0" title="Eliminar"><i class="fa fa-trash"></i></button>` : ''}
                    </div>`).join('');
            });
    }

    document.getElementById('form-nota').addEventListener('submit', function(e) {
        e.preventDefault();
        const texto = document.getElementById('nota-texto').value.trim();
        if (!texto) return;
        const fd = new FormData();
        fd.append('credito_id', CID);
        fd.append('nota', texto);
        fetch(BASE + 'creditos/notas_ajax', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    document.getElementById('nota-texto').value = '';
                    cargarNotas();
                }
            });
    });

    window.eliminarNota = function(notaId) {
        if (!confirm('¿Eliminar esta nota?')) return;
        fetch(BASE + 'creditos/notas_ajax?credito_id=' + CID + '&nota_id=' + notaId, {method:'DELETE'})
            .then(() => cargarNotas());
    };

    cargarNotas();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>