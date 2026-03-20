<?php
// ============================================================
// admin/clientes_finalizados.php — Reporte de créditos finalizados
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros ──────────────────────────────────────────────────
$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
$motivo_f    = $_GET['motivo'] ?? '';
$cobrador_f  = (int)($_GET['cobrador_id'] ?? 0);

// Exportación CSV
if (isset($_GET['export'])) {
    $cobradores_list = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol IN ('cobrador','supervisor') AND activo=1 ORDER BY nombre")->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="finalizados_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID Crédito','Cliente','DNI','Artículo','Total','Fecha Alta','Fecha Fin','Motivo','Cobrador','Puntaje','¿Renovó?'], ';');

    $stmt_exp = $pdo->prepare("
        SELECT cr.id, CONCAT(cl.apellidos,', ',cl.nombres) AS cliente, cl.dni,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
               cr.monto_total, cr.fecha_alta, cr.fecha_finalizacion, cr.motivo_finalizacion,
               CONCAT(u.nombre,' ',u.apellido) AS cobrador,
               cl.puntaje_pago,
               (SELECT COUNT(*) FROM ic_creditos cr2
                WHERE cr2.cliente_id=cr.cliente_id AND cr2.fecha_alta > cr.fecha_finalizacion) AS renovo
        FROM ic_creditos cr
        JOIN ic_clientes cl ON cr.cliente_id=cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
        JOIN ic_usuarios u ON cr.cobrador_id=u.id
        WHERE cr.estado='FINALIZADO'
          AND cr.fecha_finalizacion BETWEEN ? AND ?
          " . ($motivo_f ? "AND cr.motivo_finalizacion=?" : "") . "
          " . ($cobrador_f ? "AND cr.cobrador_id=?" : "") . "
        ORDER BY cr.fecha_finalizacion DESC
    ");
    $params = [$fecha_desde, $fecha_hasta];
    if ($motivo_f) $params[] = $motivo_f;
    if ($cobrador_f) $params[] = $cobrador_f;
    $stmt_exp->execute($params);
    $puntaje_labels = [1=>'Excelente',2=>'Bueno',3=>'Regular',4=>'Malo'];
    foreach ($stmt_exp->fetchAll() as $row) {
        fputcsv($out, [
            $row['id'], $row['cliente'], $row['dni'], $row['articulo'],
            $row['monto_total'], $row['fecha_alta'], $row['fecha_finalizacion'],
            $row['motivo_finalizacion'], $row['cobrador'],
            $puntaje_labels[$row['puntaje_pago']] ?? '—',
            $row['renovo'] > 0 ? 'Sí' : 'No'
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Cobradores para filtros ───────────────────────────────────
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol IN ('cobrador','supervisor') AND activo=1 ORDER BY nombre")->fetchAll();

// ── Consulta principal ────────────────────────────────────────
$params = [$fecha_desde, $fecha_hasta];
$where_extra = '';
if ($motivo_f) { $where_extra .= " AND cr.motivo_finalizacion=?"; $params[] = $motivo_f; }
if ($cobrador_f) { $where_extra .= " AND cr.cobrador_id=?"; $params[] = $cobrador_f; }

$stmt = $pdo->prepare("
    SELECT cr.id, CONCAT(cl.apellidos,', ',cl.nombres) AS cliente, cl.id AS cliente_id,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           cr.monto_total, cr.fecha_alta, cr.fecha_finalizacion, cr.motivo_finalizacion,
           CONCAT(u.nombre,' ',u.apellido) AS cobrador,
           cl.puntaje_pago,
           (SELECT COUNT(*) FROM ic_creditos cr2
            WHERE cr2.cliente_id=cr.cliente_id
              AND cr2.fecha_alta > cr.fecha_finalizacion) AS renovo
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    WHERE cr.estado='FINALIZADO'
      AND cr.fecha_finalizacion BETWEEN ? AND ?
      $where_extra
    ORDER BY cr.fecha_finalizacion DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Métricas del reporte ──────────────────────────────────────
$total_registros = count($rows);
$con_renovacion  = count(array_filter($rows, fn($r) => $r['renovo'] > 0));
$tasa_renovacion = $total_registros > 0 ? round($con_renovacion / $total_registros * 100) : 0;
$motivos_validos = ['PAGO_COMPLETO','PAGO_COMPLETO_CON_MORA','RETIRO_PRODUCTO','INCOBRABILIDAD','ACUERDO_EXTRAJUDICIAL'];
$motivos_labels  = [
    'PAGO_COMPLETO'          => '✅ Pago completo',
    'PAGO_COMPLETO_CON_MORA' => '⚠️ Pago c/mora',
    'RETIRO_PRODUCTO'        => '📦 Retiro producto',
    'INCOBRABILIDAD'         => '❌ Incobrable',
    'ACUERDO_EXTRAJUDICIAL'  => '🤝 Acuerdo extraj.',
];

$page_title   = 'Créditos Finalizados';
$page_current = 'finalizados';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── Filtros ──────────────────────────────────────────────── -->
<form method="GET" class="card-ic mb-4" style="padding:16px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end">
        <div class="form-group" style="margin:0">
            <label style="font-size:.78rem">Desde</label>
            <input type="date" name="desde" value="<?= e($fecha_desde) ?>" class="form-control">
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.78rem">Hasta</label>
            <input type="date" name="hasta" value="<?= e($fecha_hasta) ?>" class="form-control">
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.78rem">Motivo</label>
            <select name="motivo" class="form-control">
                <option value="">— Todos —</option>
                <?php foreach ($motivos_labels as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= $motivo_f === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.78rem">Cobrador</label>
            <select name="cobrador_id" class="form-control">
                <option value="0">— Todos —</option>
                <?php foreach ($cobradores as $cob): ?>
                <option value="<?= $cob['id'] ?>" <?= $cobrador_f === (int)$cob['id'] ? 'selected' : '' ?>>
                    <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="submit" class="btn-ic btn-primary" style="flex:1">
                <i class="fa fa-filter"></i> Filtrar
            </button>
            <a href="?desde=<?= e($fecha_desde) ?>&hasta=<?= e($fecha_hasta) ?>&motivo=<?= e($motivo_f) ?>&cobrador_id=<?= $cobrador_f ?>&export=1"
               class="btn-ic btn-ghost btn-icon" title="Exportar CSV">
                <i class="fa fa-download"></i>
            </a>
        </div>
    </div>
</form>

<!-- ── KPI del reporte ──────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px">
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981"><i class="fa fa-flag-checkered"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Finalizados en período</div>
            <div class="kpi-value"><?= number_format($total_registros) ?></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary-light)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(103,112,210,.15);--icon-color:#6770d2"><i class="fa fa-recycle"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Con renovación posterior</div>
            <div class="kpi-value"><?= number_format($con_renovacion) ?></div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(6,182,212,.15);--icon-color:#06b6d4"><i class="fa fa-percent"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Tasa de renovación</div>
            <div class="kpi-value"><?= $tasa_renovacion ?>%</div>
        </div>
    </div>
</div>

<!-- ── Tabla ─────────────────────────────────────────────────── -->
<?php if (empty($rows)): ?>
    <div class="card-ic" style="padding:40px;text-align:center">
        <i class="fa fa-inbox fa-3x" style="opacity:.2;margin-bottom:12px;display:block"></i>
        <p class="text-muted">No hay créditos finalizados en el período seleccionado.</p>
    </div>
<?php else: ?>
<div class="card-ic" style="overflow-x:auto">
    <table class="table-ic">
        <thead>
            <tr>
                <th>#</th>
                <th>Cliente</th>
                <th>Artículo</th>
                <th>Total</th>
                <th>Fecha Alta</th>
                <th>Fecha Fin</th>
                <th>Motivo</th>
                <th>Puntaje</th>
                <th>¿Renovó?</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td class="text-muted" style="font-size:.78rem">#<?= $r['id'] ?></td>
            <td class="fw-bold" style="font-size:.85rem">
                <a href="<?= BASE_URL ?>clientes/ver?id=<?= $r['cliente_id'] ?>" style="color:inherit;text-decoration:underline dotted">
                    <?= e($r['cliente']) ?>
                </a>
            </td>
            <td style="font-size:.82rem"><?= e($r['articulo']) ?></td>
            <td class="nowrap fw-bold"><?= formato_pesos($r['monto_total']) ?></td>
            <td class="nowrap" style="font-size:.82rem"><?= $r['fecha_alta'] ? date('d/m/Y', strtotime($r['fecha_alta'])) : '—' ?></td>
            <td class="nowrap" style="font-size:.82rem"><?= $r['fecha_finalizacion'] ? date('d/m/Y', strtotime($r['fecha_finalizacion'])) : '—' ?></td>
            <td><span style="font-size:.78rem"><?= e($motivos_labels[$r['motivo_finalizacion']] ?? $r['motivo_finalizacion'] ?? '—') ?></span></td>
            <td><?= badge_puntaje_pago($r['puntaje_pago'] ? (int)$r['puntaje_pago'] : null) ?></td>
            <td>
                <?php if ($r['renovo'] > 0): ?>
                    <span class="badge-ic badge-success">✅ Sí</span>
                <?php else: ?>
                    <span class="badge-ic badge-secondary">No</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?= BASE_URL ?>creditos/ver?id=<?= $r['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver crédito">
                    <i class="fa fa-eye"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
