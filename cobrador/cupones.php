<?php
// ============================================================
// cobrador/cupones.php — Consulta de cupones de pago generados
// El cobrador ve solo los suyos; admin/supervisor ven todos (con
// selector de cobrador).
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_pagos');

$pdo = obtener_conexion();
$user_id     = $_SESSION['user_id'];
$is_cobrador = es_cobrador();

// ── Filtros ──────────────────────────────────────────────────
$cobrador_filtro = $is_cobrador ? $user_id : (int) ($_GET['cobrador_id'] ?? 0);
$q_cliente = trim($_GET['q'] ?? '');
$f_desde   = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$f_hasta   = $_GET['hasta'] ?? date('Y-m-d');
$pagina    = max(1, (int) ($_GET['pag'] ?? 1));
$por_pagina = 25;

$where  = ['cp.fecha_jornada BETWEEN ? AND ?'];
$params = [$f_desde, $f_hasta];

if ($cobrador_filtro) {
    $where[]  = 'cp.cobrador_id = ?';
    $params[] = $cobrador_filtro;
}
if ($q_cliente !== '') {
    $where[]  = "(cl.nombres LIKE ? OR cl.apellidos LIKE ? OR cl.dni LIKE ?)";
    $like = '%' . $q_cliente . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$cnt_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM ic_cupones_pago cp
    JOIN ic_clientes cl ON cl.id = cp.cliente_id
    $sql_where
");
$cnt_stmt->execute($params);
$total = (int) $cnt_stmt->fetchColumn();

$total_paginas = max(1, (int) ceil($total / $por_pagina));
$pagina = min($pagina, $total_paginas);
$offset = ($pagina - 1) * $por_pagina;

$stmt = $pdo->prepare("
    SELECT cp.id, cp.fecha_jornada, cp.monto_total, cp.cant_cuotas, cp.created_at,
           cl.nombres, cl.apellidos, cl.dni,
           u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido
    FROM ic_cupones_pago cp
    JOIN ic_clientes cl ON cl.id = cp.cliente_id
    JOIN ic_usuarios u  ON u.id  = cp.cobrador_id
    $sql_where
    ORDER BY cp.created_at DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$cupones = $stmt->fetchAll();

$cobradores = $is_cobrador ? [] :
    $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

function cupon_page_url(int $p): string {
    $q = $_GET;
    $q['pag'] = $p;
    return '?' . http_build_query($q);
}

$page_title   = 'Cupones de Pago';
$page_current = 'cupones';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- Filtros -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar" style="flex-wrap:wrap;gap:12px">
        <?php if (!$is_cobrador): ?>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Cobrador</label>
            <select name="cobrador_id" style="min-width:180px">
                <option value="0">— Todos —</option>
                <?php foreach ($cobradores as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $cobrador_filtro === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['nombre'] . ' ' . $c['apellido']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Cliente / DNI</label>
            <input type="text" name="q" value="<?= e($q_cliente) ?>" placeholder="Buscar..." style="min-width:160px">
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Desde</label>
            <input type="date" name="desde" value="<?= e($f_desde) ?>" style="min-width:140px">
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Hasta</label>
            <input type="date" name="hasta" value="<?= e($f_hasta) ?>" style="min-width:140px">
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px">
            <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
            <a href="cupones" class="btn-ic btn-ghost"><i class="fa fa-times"></i></a>
        </div>
    </form>
</div>

<!-- Tabla -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-receipt"></i> Cupones de Pago</span>
        <span class="text-muted" style="font-size:.82rem"><?= number_format($total) ?> cupón<?= $total !== 1 ? 'es' : '' ?></span>
    </div>

    <?php if (empty($cupones)): ?>
        <p class="text-muted text-center" style="padding:30px">Sin cupones para los filtros seleccionados.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <?php if (!$is_cobrador): ?><th>Cobrador</th><?php endif; ?>
                        <th>Cliente</th>
                        <th>DNI</th>
                        <th class="text-center">Cuotas</th>
                        <th class="text-right">Monto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cupones as $c): ?>
                        <tr>
                            <td class="nowrap text-muted" style="font-size:.85rem">
                                <?= date('d/m/Y', strtotime($c['fecha_jornada'])) ?>
                            </td>
                            <?php if (!$is_cobrador): ?>
                                <td><?= e($c['cobrador_nombre'] . ' ' . $c['cobrador_apellido']) ?></td>
                            <?php endif; ?>
                            <td class="fw-bold"><?= e($c['apellidos'] . ', ' . $c['nombres']) ?></td>
                            <td class="text-muted"><?= e($c['dni'] ?: '—') ?></td>
                            <td class="text-center"><?= (int) $c['cant_cuotas'] ?></td>
                            <td class="text-right fw-bold"><?= formato_pesos((float) $c['monto_total']) ?></td>
                            <td class="nowrap" style="display:flex;gap:6px">
                                <a href="cupon_ver.php?id=<?= $c['id'] ?>" target="_blank" class="btn-ic btn-ghost btn-sm">
                                    <i class="fa fa-file-pdf"></i> Ver
                                </a>
                                <button type="button" class="btn-ic btn-sm" style="background:#25d366;color:#fff;border-color:#25d366"
                                        onclick="compartirCuponWhatsApp('cupon_ver.php?id=<?= $c['id'] ?>')">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_paginas > 1): ?>
            <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap">
                <?php if ($pagina > 1): ?>
                    <a href="<?= cupon_page_url($pagina - 1) ?>" class="btn-ic btn-ghost btn-sm">‹ Anterior</a>
                <?php endif; ?>
                <?php
                $inicio = max(1, $pagina - 2);
                $fin    = min($total_paginas, $pagina + 2);
                for ($p = $inicio; $p <= $fin; $p++): ?>
                    <a href="<?= cupon_page_url($p) ?>"
                       class="btn-ic btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-ghost' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
                <?php if ($pagina < $total_paginas): ?>
                    <a href="<?= cupon_page_url($pagina + 1) ?>" class="btn-ic btn-ghost btn-sm">Siguiente ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
