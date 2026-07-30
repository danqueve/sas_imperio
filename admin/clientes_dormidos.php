<?php
// ============================================================
// admin/clientes_dormidos.php — Clientes sin crédito activo +90 días
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// Filtros
$cobrador_f = (int)($_GET['cobrador_id'] ?? 0);
$where_cob  = $cobrador_f ? 'AND u.id = ?' : '';

$cobradores = $pdo->query("
    SELECT id, nombre, apellido FROM ic_usuarios
    WHERE rol IN ('cobrador','supervisor') AND activo=1
    ORDER BY nombre
")->fetchAll();

// Misma condición usada en el KPI del dashboard: cliente ACTIVO, sin crédito
// EN_CURSO/MOROSO, y con al menos un crédito finalizado hace más de 90 días.
$params = $cobrador_f ? [$cobrador_f] : [];
$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.dni, cl.telefono, cl.zona,
           cl.puntaje_pago, cl.total_creditos_finalizados, cl.creditos_sin_mora,
           ult.credito_id, ult.articulo, ult.monto_total, ult.fecha_finalizacion,
           DATEDIFF(CURDATE(), ult.fecha_finalizacion) AS dias_dormido,
           CONCAT(u.nombre,' ',u.apellido) AS cobrador
    FROM ic_clientes cl
    JOIN (
        SELECT cr.cliente_id, cr.id AS credito_id, cr.cobrador_id, cr.fecha_finalizacion,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo, cr.monto_total,
               ROW_NUMBER() OVER (PARTITION BY cr.cliente_id ORDER BY cr.fecha_finalizacion DESC) AS rn
        FROM ic_creditos cr
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.estado = 'FINALIZADO'
    ) ult ON ult.cliente_id = cl.id AND ult.rn = 1
    JOIN ic_usuarios u ON u.id = ult.cobrador_id
    WHERE cl.estado = 'ACTIVO'
      AND NOT EXISTS (SELECT 1 FROM ic_creditos cr2 WHERE cr2.cliente_id = cl.id AND cr2.estado IN ('EN_CURSO','MOROSO'))
      AND EXISTS (SELECT 1 FROM ic_creditos cr3 WHERE cr3.cliente_id = cl.id AND cr3.fecha_finalizacion < DATE_SUB(CURDATE(), INTERVAL 90 DAY))
      $where_cob
    ORDER BY ult.fecha_finalizacion ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total        = count($rows);
$dias_prom    = $total > 0 ? round(array_sum(array_column($rows, 'dias_dormido')) / $total) : 0;
$excelentes   = count(array_filter($rows, fn($r) => (int)$r['puntaje_pago'] === 1));
$buenos_reg   = count(array_filter($rows, fn($r) => in_array((int)$r['puntaje_pago'], [2,3], true)));
$malos_sin    = count(array_filter($rows, fn($r) => empty($r['puntaje_pago']) || (int)$r['puntaje_pago'] === 4));

$page_title   = 'Clientes Dormidos';
$page_current = 'clientes';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── Filtros ──────────────────────────────────────────────── -->
<form method="GET" class="card-ic mb-4" style="padding:14px">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div class="form-group" style="margin:0;min-width:200px">
            <label style="font-size:.78rem">Cobrador (último crédito)</label>
            <select name="cobrador_id" class="form-control">
                <option value="0">— Todos —</option>
                <?php foreach ($cobradores as $cob): ?>
                <option value="<?= $cob['id'] ?>" <?= $cobrador_f === (int)$cob['id'] ? 'selected' : '' ?>>
                    <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-ic btn-primary" style="align-self:flex-end">
            <i class="fa fa-filter"></i> Filtrar
        </button>
    </div>
</form>

<!-- ── Resumen ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px">
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(245,158,11,.15);--icon-color:#f59e0b"><i class="fa fa-user-clock"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Clientes dormidos</div>
            <div class="kpi-value"><?= $total ?></div>
            <div class="kpi-sub">sin crédito activo +90 días</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary-light)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(103,112,210,.15);--icon-color:#6770d2"><i class="fa fa-hourglass-half"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Promedio dormidos</div>
            <div class="kpi-value"><?= $dias_prom ?> <span style="font-size:.9rem">días</span></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981"><i class="fa fa-star"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">⭐⭐⭐ Excelente</div>
            <div class="kpi-value"><?= $excelentes ?></div>
            <div class="kpi-sub">sin mora histórica</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(239,68,68,.15);--icon-color:#ef4444"><i class="fa fa-triangle-exclamation"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">⭐⭐/⭐ Bueno-Regular</div>
            <div class="kpi-value"><?= $buenos_reg ?></div>
            <div class="kpi-sub"><?= $malos_sin ?> malos / sin calificación</div>
        </div>
    </div>
</div>

<!-- ── Tabla ──────────────────────────────────────────────────── -->
<?php if (!$rows): ?>
<div class="card-ic" style="padding:50px;text-align:center">
    <i class="fa fa-check-circle fa-3x text-success" style="margin-bottom:14px;display:block"></i>
    <p class="text-muted">No hay clientes dormidos<?= $cobrador_f ? ' para el cobrador seleccionado' : '' ?>.</p>
</div>
<?php else: ?>
<div class="card-ic" style="overflow-x:auto">
    <table class="table-ic" style="font-size:.85rem">
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Zona</th>
                <th>Cobrador</th>
                <th>Último crédito</th>
                <th>Finalizó</th>
                <th>Días dormido</th>
                <th>Puntaje</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $dias = (int) $r['dias_dormido'];
            $color_dias = $dias >= 180 ? 'var(--danger)' : ($dias >= 120 ? 'var(--warning)' : 'inherit');
            $wa_msg = '¡Hola ' . $r['nombres'] . '! Hace un tiempo finalizaste tu crédito de ' . $r['articulo']
                    . ' con nosotros. Queríamos saber cómo estás y contarte que tenemos nuevas opciones disponibles. ¡Te esperamos!';
            ?>
            <tr>
                <td>
                    <a href="<?= BASE_URL ?>clientes/ver?id=<?= (int)$r['cliente_id'] ?>"
                       class="fw-bold" style="color:inherit;text-decoration:underline dotted" target="_blank">
                        <?= e($r['apellidos'] . ', ' . $r['nombres']) ?>
                    </a>
                    <?php if ($r['dni']): ?><br><span class="text-muted" style="font-size:.75rem">DNI: <?= e($r['dni']) ?></span><?php endif; ?>
                    <?php if ($r['telefono']): ?>
                    <br>
                    <a href="<?= e(whatsapp_url($r['telefono'], $wa_msg)) ?>" target="_blank"
                       class="text-muted" style="font-size:.72rem;text-decoration:none">
                        <i class="fa-brands fa-whatsapp" style="color:#25d366"></i> <?= e($r['telefono']) ?>
                    </a>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.8rem"><?= e($r['zona'] ?: '—') ?></td>
                <td class="text-muted" style="font-size:.8rem"><?= e($r['cobrador']) ?></td>
                <td style="font-size:.8rem">
                    <?= e($r['articulo']) ?>
                    <br><span class="text-muted">#<?= $r['credito_id'] ?> · <?= formato_pesos($r['monto_total']) ?></span>
                </td>
                <td class="nowrap" style="font-size:.8rem">
                    <?= $r['fecha_finalizacion'] ? date('d/m/Y', strtotime($r['fecha_finalizacion'])) : '—' ?>
                </td>
                <td>
                    <span style="color:<?= $color_dias ?>;font-weight:700"><?= $dias ?></span>
                    <span class="text-muted" style="font-size:.75rem"> días</span>
                </td>
                <td><?= badge_puntaje_pago($r['puntaje_pago'] ? (int)$r['puntaje_pago'] : null) ?: '<span class="badge-ic badge-secondary">Sin datos</span>' ?></td>
                <td>
                    <div style="display:flex;gap:6px">
                        <a href="<?= BASE_URL ?>creditos/ver?id=<?= (int)$r['credito_id'] ?>"
                           class="btn-ic btn-ghost btn-icon btn-sm" title="Ver último crédito" target="_blank">
                            <i class="fa fa-eye"></i>
                        </a>
                        <?php if ($r['telefono']): ?>
                        <a href="<?= e(whatsapp_url($r['telefono'], $wa_msg)) ?>" target="_blank"
                           class="btn-ic btn-ghost btn-icon btn-sm" title="Reenganchar por WhatsApp"
                           style="color:#25d366">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
