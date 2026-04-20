<?php
// supervisor/index.php — Panel dedicado al supervisor
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── KPIs principales ─────────────────────────────────────────
$pagos_pend = (int)$pdo->query("
    SELECT COUNT(*) FROM ic_pagos_temporales WHERE estado='PENDIENTE'
")->fetchColumn();

$cobradores_pend = (int)$pdo->query("
    SELECT COUNT(DISTINCT cobrador_id) FROM ic_pagos_temporales WHERE estado='PENDIENTE'
")->fetchColumn();

$creditos_riesgo = (int)$pdo->query("
    SELECT COUNT(DISTINCT cr.id) FROM ic_creditos cr
    JOIN ic_cuotas cu ON cu.credito_id = cr.id
    WHERE cr.estado IN ('EN_CURSO','MOROSO')
      AND cu.estado IN ('VENCIDA','PARCIAL')
      AND cu.fecha_vencimiento < CURDATE()
    HAVING COUNT(cu.id) >= 2
")->fetchColumn();

$cobrado_hoy = (float)$pdo->query("
    SELECT COALESCE(SUM(monto_total),0) FROM ic_pagos_confirmados WHERE fecha_pago=CURDATE()
")->fetchColumn();

// ── Pagos pendientes por cobrador ────────────────────────────
$stmt_pend = $pdo->query("
    SELECT u.id AS cobrador_id, CONCAT(u.nombre,' ',u.apellido) AS cobrador,
           COUNT(*) AS n_pagos,
           COALESCE(SUM(pt.monto_total),0) AS total,
           MIN(pt.fecha_registro) AS primer_registro
    FROM ic_pagos_temporales pt
    JOIN ic_usuarios u ON pt.cobrador_id = u.id
    WHERE pt.estado = 'PENDIENTE'
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY primer_registro ASC
");
$pendientes_cob = $stmt_pend->fetchAll();

// ── Cobradores rezagados (sin pagos hoy ni ayer) ─────────────
$rezagados = $pdo->query("
    SELECT u.id, CONCAT(u.nombre,' ',u.apellido) AS cobrador,
           (SELECT COUNT(*) FROM ic_clientes WHERE cobrador_id=u.id AND estado='ACTIVO') AS clientes_activos,
           (SELECT COALESCE(SUM(monto_total),0) FROM ic_pagos_confirmados
            WHERE cobrador_id=u.id AND fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS cobrado_semana
    FROM ic_usuarios u
    WHERE u.rol='cobrador' AND u.activo=1
      AND u.id NOT IN (
          SELECT DISTINCT cobrador_id FROM ic_pagos_temporales
          WHERE estado='PENDIENTE' AND DATE(fecha_registro) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
      )
      AND u.id NOT IN (
          SELECT DISTINCT cobrador_id FROM ic_pagos_confirmados
          WHERE fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
      )
    ORDER BY cobrado_semana ASC
")->fetchAll();

// ── Créditos en riesgo alto ───────────────────────────────────
$stmt_risc = $pdo->query("
    SELECT cr.id, CONCAT(cl.apellidos,', ',cl.nombres) AS cliente, cl.telefono,
           CONCAT(u.nombre,' ',u.apellido) AS cobrador,
           COALESCE(cr.articulo_desc, a.descripcion,'—') AS articulo,
           COUNT(CASE WHEN cu.estado IN('VENCIDA','PARCIAL') AND cu.fecha_vencimiento<CURDATE() THEN 1 END) AS vencidas,
           MAX(CASE WHEN cu.fecha_vencimiento<CURDATE() AND cu.estado IN('VENCIDA','PARCIAL')
                    THEN DATEDIFF(CURDATE(),cu.fecha_vencimiento) END) AS max_dias
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    JOIN ic_cuotas cu ON cu.credito_id=cr.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    WHERE cr.estado IN ('EN_CURSO','MOROSO')
    GROUP BY cr.id, cl.apellidos, cl.nombres, cl.telefono, u.nombre, u.apellido, cr.articulo_desc, a.descripcion
    HAVING vencidas >= 2
    ORDER BY max_dias DESC
    LIMIT 15
");
$riesgo_lista = $stmt_risc->fetchAll();

$page_title   = 'Panel Supervisor';
$page_current = 'supervisor';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- KPIs -->
<div class="kpi-grid mb-4" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(245,158,11,.15);--icon-color:#f59e0b">
            <i class="fa fa-clock"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Pagos Pendientes</div>
            <div class="kpi-value"><?= $pagos_pend ?></div>
            <div class="kpi-sub"><?= $cobradores_pend ?> cobrador<?= $cobradores_pend !== 1 ? 'es' : '' ?></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(239,68,68,.15);--icon-color:#ef4444">
            <i class="fa fa-triangle-exclamation"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Créditos en Riesgo</div>
            <div class="kpi-value"><?= $creditos_riesgo ?></div>
            <div class="kpi-sub">2+ cuotas vencidas</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981">
            <i class="fa fa-dollar-sign"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cobrado Hoy</div>
            <div class="kpi-value" style="font-size:1.3rem"><?= formato_pesos($cobrado_hoy) ?></div>
            <div class="kpi-sub"><a href="../admin/rendiciones" style="color:var(--success);text-decoration:underline dotted">Ir a rendiciones →</a></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:#f97316">
        <div class="kpi-icon-box" style="--icon-bg:rgba(249,115,22,.15);--icon-color:#f97316">
            <i class="fa fa-user-clock"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cobradores Sin Actividad</div>
            <div class="kpi-value"><?= count($rezagados) ?></div>
            <div class="kpi-sub">sin pagos últimas 24h</div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- Pendientes por cobrador -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-hourglass-half" style="color:var(--warning)"></i> Pendientes de Aprobación</span>
        <?php if ($pagos_pend): ?>
        <a href="../admin/rendiciones" class="btn-ic btn-warning btn-sm">Aprobar Todo</a>
        <?php endif; ?>
    </div>
    <?php if (empty($pendientes_cob)): ?>
        <p class="text-muted text-center" style="padding:24px">Sin pagos pendientes. ✓</p>
    <?php else: ?>
    <table class="table-ic">
        <thead><tr><th>Cobrador</th><th>Pagos</th><th>Total</th><th>Desde</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pendientes_cob as $p): ?>
        <tr>
            <td class="fw-bold"><?= e($p['cobrador']) ?></td>
            <td><span class="badge-ic badge-warning"><?= $p['n_pagos'] ?></span></td>
            <td class="fw-bold"><?= formato_pesos((float)$p['total']) ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= date('d/m H:i', strtotime($p['primer_registro'])) ?></td>
            <td><a href="../admin/rendiciones?cobrador_id=<?= $p['cobrador_id'] ?>" class="btn-ic btn-ghost btn-sm">Ver</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Cobradores rezagados -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-user-slash" style="color:#f97316"></i> Sin Actividad Reciente</span>
    </div>
    <?php if (empty($rezagados)): ?>
        <p class="text-muted text-center" style="padding:24px">Todos los cobradores activos. ✓</p>
    <?php else: ?>
    <table class="table-ic">
        <thead><tr><th>Cobrador</th><th>Clientes</th><th>Cobrado 7d</th></tr></thead>
        <tbody>
        <?php foreach ($rezagados as $r): ?>
        <tr>
            <td class="fw-bold"><?= e($r['cobrador']) ?></td>
            <td><?= $r['clientes_activos'] ?></td>
            <td <?= (float)$r['cobrado_semana'] === 0.0 ? 'style="color:var(--danger)"' : '' ?>>
                <?= formato_pesos((float)$r['cobrado_semana']) ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</div>

<!-- Créditos en riesgo -->
<?php if ($riesgo_lista): ?>
<div class="card-ic mt-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-fire" style="color:var(--danger)"></i> Créditos en Riesgo Alto</span>
        <a href="../admin/riesgo_cartera" class="btn-ic btn-ghost btn-sm">Ver completo</a>
    </div>
    <div style="overflow-x:auto">
    <table class="table-ic">
        <thead><tr><th>#</th><th>Cliente</th><th>Artículo</th><th>Cobrador</th><th>Vencidas</th><th>Max. días</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($riesgo_lista as $r): ?>
        <tr>
            <td class="text-muted">#<?= $r['id'] ?></td>
            <td class="fw-bold"><?= e($r['cliente']) ?></td>
            <td style="font-size:.82rem"><?= e($r['articulo']) ?></td>
            <td style="font-size:.82rem"><?= e($r['cobrador']) ?></td>
            <td><span class="badge-ic badge-danger"><?= $r['vencidas'] ?></span></td>
            <td <?= (int)$r['max_dias'] > 30 ? 'style="color:var(--danger);font-weight:700"' : '' ?>>
                <?= $r['max_dias'] ?>d
            </td>
            <td><a href="../creditos/ver?id=<?= $r['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon"><i class="fa fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
