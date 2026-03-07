<?php
// ============================================================
// creditos/index.php — Listado de Créditos
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$q = trim($_GET['q'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$frec = trim($_GET['frecuencia'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(cl.nombres LIKE ? OR cl.apellidos LIKE ? OR COALESCE(cr.articulo_desc, a.descripcion) LIKE ?)";
    $l = "%$q%";
    $params = array_merge($params, [$l, $l, $l]);
}
if ($estado !== '') {
    $where[] = 'cr.estado=?';
    $params[] = $estado;
}
if ($frec !== '') {
    $where[] = 'cr.frecuencia=?';
    $params[] = $frec;
}
$whereStr = implode(' AND ', $where);

$totalStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    WHERE $whereStr
");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();
$totalPags = max(1, (int) ceil($total / $limit));

$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a,
           v.nombre AS vendedor_n, v.apellido AS vendedor_a,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    LEFT JOIN ic_usuarios v ON cr.vendedor_id=v.id
    WHERE $whereStr
    ORDER BY cr.fecha_alta DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$creditos = $stmt->fetchAll();

// ── Solicitudes de anulación pendientes (admin y supervisor) ──
$sol_baja_creditos = []; // credito_id => true  (para marcar filas)
$sol_baja_alert    = []; // items para el banner
if (es_admin() || es_supervisor()) {
    $stmt_sb = $pdo->query("
        SELECT 'temporal' AS tipo,
               cr.id AS credito_id,
               CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
               cu.numero_cuota,
               pt.motivo_baja
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu  ON pt.cuota_id    = cu.id
        JOIN ic_creditos cr ON cu.credito_id  = cr.id
        JOIN ic_clientes cl ON cr.cliente_id  = cl.id
        WHERE pt.solicitud_baja = 1
        UNION ALL
        SELECT 'confirmado' AS tipo,
               cr.id AS credito_id,
               CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
               cu.numero_cuota,
               pc.motivo_baja
        FROM ic_pagos_confirmados pc
        JOIN ic_cuotas cu  ON pc.cuota_id    = cu.id
        JOIN ic_creditos cr ON cu.credito_id  = cr.id
        JOIN ic_clientes cl ON cr.cliente_id  = cl.id
        WHERE pc.solicitud_baja = 1
        ORDER BY credito_id DESC
    ");
    foreach ($stmt_sb->fetchAll() as $sb) {
        $sol_baja_alert[] = $sb;
        $sol_baja_creditos[(int)$sb['credito_id']] = true;
    }
}

$page_title = 'Créditos';
$page_current = 'creditos';
$topbar_actions = '<a href="nuevo" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nuevo Crédito</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!empty($sol_baja_alert)): ?>
<div class="alert-ic alert-danger mb-4" style="flex-direction:column;align-items:flex-start;gap:12px">
    <div style="display:flex;align-items:center;gap:10px;width:100%">
        <i class="fa fa-triangle-exclamation fa-lg"></i>
        <strong>
            <?= count($sol_baja_alert) ?> solicitud<?= count($sol_baja_alert) !== 1 ? 'es' : '' ?>
            de anulación de pago pendiente<?= count($sol_baja_alert) !== 1 ? 's' : '' ?> de revisión
        </strong>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php
        $vistos = [];
        foreach ($sol_baja_alert as $sb):
            $cid = (int)$sb['credito_id'];
            if (isset($vistos[$cid])) continue;
            $vistos[$cid] = true;
        ?>
        <a href="ver?id=<?= $cid ?>"
           style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.08);
                  border:1px solid rgba(211,64,83,.5);border-radius:6px;padding:5px 10px;
                  color:inherit;text-decoration:none;font-size:.8rem">
            <i class="fa fa-user" style="opacity:.6;font-size:.7rem"></i>
            <span class="fw-bold"><?= e($sb['cliente']) ?></span>
            <span style="opacity:.6">· cuota #<?= (int)$sb['numero_cuota'] ?></span>
            <?php if ($sb['motivo_baja']): ?>
                <span style="opacity:.5;font-size:.72rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                      title="<?= e($sb['motivo_baja']) ?>">
                    — <?= e($sb['motivo_baja']) ?>
                </span>
            <?php endif; ?>
            <i class="fa fa-arrow-right" style="opacity:.5;font-size:.65rem;margin-left:2px"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Cliente o artículo...">
        <select name="estado">
            <option value="">Todos los estados</option>
            <?php foreach (['EN_CURSO', 'FINALIZADO', 'MOROSO', 'CANCELADO'] as $es): ?>
                <option value="<?= $es ?>" <?= $estado === $es ? 'selected' : '' ?>>
                    <?= $es ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="frecuencia">
            <option value="">Toda frecuencia</option>
            <?php foreach (['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $frec === $k ? 'selected' : '' ?>>
                    <?= $l ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="?" class="btn-ic btn-ghost">Limpiar</a>
    </form>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Créditos</span>
        <span class="text-muted" style="font-size:.82rem">
            <?= number_format($total) ?> registro
            <?= $total !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Artículo</th>
                    <th>Total</th>
                    <th>Cuota</th>
                    <th>Frec.</th>
                    <th>Avance</th>
                    <th>Cobrador</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($creditos)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted" style="padding:40px">Sin resultados.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($creditos as $cr): ?>
                        <?php $tiene_sol_baja = isset($sol_baja_creditos[(int)$cr['id']]); ?>
                        <tr <?= $tiene_sol_baja ? 'style="background:rgba(211,64,83,.08);outline:1px solid rgba(211,64,83,.25)"' : '' ?>>
                            <td class="text-muted nowrap">#
                                <?= $cr['id'] ?>
                            </td>
                            <td>
                                <div class="fw-bold" style="display:flex;align-items:center;gap:6px">
                                    <a href="../clientes/ver?id=<?= $cr['cliente_id'] ?>">
                                        <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                                    </a>
                                    <?php if ($tiene_sol_baja): ?>
                                        <span class="badge-ic badge-danger" title="Solicitud de anulación de pago pendiente"
                                              style="font-size:.6rem;padding:2px 6px">
                                            <i class="fa fa-triangle-exclamation"></i> Anulación
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted" style="font-size:.75rem">
                                    <?= e($cr['telefono']) ?>
                                </div>
                            </td>
                            <td>
                                <?= e($cr['articulo']) ?>
                            </td>
                            <td class="nowrap fw-bold">
                                <?= formato_pesos($cr['monto_total']) ?>
                            </td>
                            <td class="nowrap">
                                <?= formato_pesos($cr['monto_cuota']) ?>
                            </td>
                            <td>
                                <?= ucfirst($cr['frecuencia']) ?>
                            </td>
                            <td class="nowrap">
                                <?= $cr['cuotas_pagadas'] ?>/
                                <?= $cr['cant_cuotas'] ?>
                                <div
                                    style="width:60px;height:4px;background:var(--dark-border);border-radius:4px;margin-top:4px;display:inline-block;vertical-align:middle">
                                    <div
                                        style="width:<?= $cr['cant_cuotas'] > 0 ? round($cr['cuotas_pagadas'] / $cr['cant_cuotas'] * 100) : 0 ?>%;height:100%;background:var(--success);border-radius:4px">
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted">
                                <?= e($cr['cobrador_n'] . ' ' . $cr['cobrador_a']) ?>
                            </td>
                            <td>
                                <?= badge_estado_credito($cr['estado']) ?>
                            </td>
                            <td>
                                <a href="ver?id=<?= $cr['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon"
                                    title="Ver cronograma">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <?php if (es_admin()): ?>
                                    <a href="editar?id=<?= $cr['id'] ?>" class="btn-ic btn-primary btn-sm btn-icon"
                                        title="Editar Crédito">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                                    <a href="finalizar?id=<?= $cr['id'] ?>" class="btn-ic btn-danger btn-sm btn-icon"
                                        title="Finalizar Crédito">
                                        <i class="fa fa-power-off"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPags > 1): ?>
        <div class="pagination mt-3">
            <?php for ($p = 1; $p <= $totalPags; $p++): ?>
                <a href="<?= '?' . http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                    class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>