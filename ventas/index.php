<?php
// ============================================================
// ventas/index.php — Listado de ventas
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_ventas');

$pdo = obtener_conexion();

// Filtros
$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
$q           = trim($_GET['q'] ?? '');
$filtro_vend = (int)($_GET['vendedor_id'] ?? 0);

// Si es vendedor, obtener su vendedor_id
$mi_vendedor_id = null;
if (es_vendedor()) {
    $stmt = $pdo->prepare("SELECT id FROM ic_vendedores WHERE usuario_id=? AND activo=1 LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $mi_vendedor_id = $stmt->fetchColumn() ?: null;
}

// Construir WHERE
$where  = "v.fecha_venta BETWEEN :desde AND :hasta";
$params = [':desde' => $fecha_desde, ':hasta' => $fecha_hasta];

if ($mi_vendedor_id !== null) {
    $where .= " AND v.vendedor_id = :vend_id";
    $params[':vend_id'] = $mi_vendedor_id;
} elseif ($filtro_vend) {
    $where .= " AND v.vendedor_id = :vend_id";
    $params[':vend_id'] = $filtro_vend;
}

if ($q !== '') {
    $where .= " AND (a.descripcion LIKE :q OR a.sku LIKE :q)";
    $params[':q'] = "%$q%";
}

$ventas = $pdo->prepare("
    SELECT v.*,
           a.descripcion AS cat_desc, a.sku,
           vd.nombre AS vend_nombre, vd.apellido AS vend_apellido
    FROM ic_ventas v
    JOIN ic_articulos a  ON v.articulo_id = a.id
    JOIN ic_vendedores vd ON v.vendedor_id = vd.id
    WHERE $where
    ORDER BY v.fecha_venta DESC, v.id DESC
");
$ventas->execute($params);
$lista = $ventas->fetchAll();

// KPIs
$total_ventas   = 0;
$total_efectivo = 0;
$total_tarjeta  = 0;
foreach ($lista as $vt) {
    $monto = (float)$vt['precio_venta'] * (int)$vt['cantidad'];
    $total_ventas += $monto;
    if ($vt['forma_pago'] === 'efectivo') $total_efectivo += $monto;
    else $total_tarjeta += $monto;
}

// Lista de vendedores para filtro (solo admin/supervisor)
$vendedores_filtro = [];
if (!es_vendedor()) {
    $vendedores_filtro = $pdo->query("SELECT id, nombre, apellido FROM ic_vendedores WHERE activo=1 ORDER BY nombre")->fetchAll();
}

$page_title    = 'Ventas';
$page_current  = 'ventas';
$topbar_actions = '<a href="nueva" class="btn-ic btn-primary btn-sm"><i class="fa fa-cart-plus"></i> Nueva Venta</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Total Período</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--primary-light);margin-top:4px">
            <?= formato_pesos($total_ventas) ?>
        </div>
        <div class="text-muted" style="font-size:.78rem"><?= count($lista) ?> venta<?= count($lista) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Efectivo</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--success);margin-top:4px">
            <?= formato_pesos($total_efectivo) ?>
        </div>
    </div>
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Tarjeta</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--warning);margin-top:4px">
            <?= formato_pesos($total_tarjeta) ?>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar" style="flex-wrap:wrap;gap:10px">
        <input type="date" name="desde" value="<?= e($fecha_desde) ?>" style="max-width:140px">
        <input type="date" name="hasta" value="<?= e($fecha_hasta) ?>" style="max-width:140px">
        <input type="text"  name="q"    value="<?= e($q) ?>"          placeholder="Artículo / SKU..." style="flex:1;min-width:140px">
        <?php if (!es_vendedor() && !empty($vendedores_filtro)): ?>
            <select name="vendedor_id" style="max-width:180px">
                <option value="">Todos los vendedores</option>
                <?php foreach ($vendedores_filtro as $vf): ?>
                    <option value="<?= $vf['id'] ?>" <?= $filtro_vend == $vf['id'] ? 'selected' : '' ?>>
                        <?= e($vf['nombre'] . ' ' . $vf['apellido']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="?" class="btn-ic btn-ghost">Limpiar</a>
        <a href="nueva" class="btn-ic btn-primary" style="margin-left:auto">
            <i class="fa fa-cart-plus"></i> Nueva Venta
        </a>
    </form>
</div>

<!-- Tabla -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-receipt"></i> Registro de Ventas</span>
        <span class="text-muted" style="font-size:.82rem"><?= count($lista) ?> resultado<?= count($lista) !== 1 ? 's' : '' ?></span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Artículo</th>
                    <th>SKU</th>
                    <?php if (!es_vendedor()): ?>
                        <th>Vendedor</th>
                    <?php endif; ?>
                    <th class="text-center">Qty</th>
                    <th class="text-right">Precio Unit.</th>
                    <th>Forma Pago</th>
                    <th class="text-right fw-bold">Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lista)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted" style="padding:40px">
                            Sin ventas en el período seleccionado.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lista as $vt): ?>
                        <?php $monto_total = (float)$vt['precio_venta'] * (int)$vt['cantidad']; ?>
                        <tr>
                            <td class="nowrap text-muted" style="font-size:.85rem">
                                <?= date('d/m/Y', strtotime($vt['fecha_venta'])) ?>
                            </td>
                            <td>
                                <span class="fw-bold"><?= e($vt['articulo_desc']) ?></span>
                            </td>
                            <td class="text-muted" style="font-family:monospace;font-size:.8rem">
                                <?= e($vt['sku'] ?: '—') ?>
                            </td>
                            <?php if (!es_vendedor()): ?>
                                <td><?= e($vt['vend_nombre'] . ' ' . $vt['vend_apellido']) ?></td>
                            <?php endif; ?>
                            <td class="text-center">
                                <span class="badge-ic badge-muted"><?= $vt['cantidad'] ?></span>
                            </td>
                            <td class="text-right nowrap"><?= formato_pesos((float)$vt['precio_venta']) ?></td>
                            <td>
                                <?php if ($vt['forma_pago'] === 'efectivo'): ?>
                                    <span class="badge-ic badge-success"><i class="fa fa-money-bill"></i> Efectivo</span>
                                <?php else: ?>
                                    <span class="badge-ic badge-warning"><i class="fa fa-credit-card"></i> Tarjeta</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right nowrap fw-bold"><?= formato_pesos($monto_total) ?></td>
                            <td class="nowrap">
                                <?php if (es_admin()): ?>
                                    <a href="eliminar?id=<?= $vt['id'] ?>" class="btn-ic btn-danger btn-sm btn-icon"
                                       title="Eliminar y restaurar stock"
                                       data-confirm="¿Eliminar esta venta? El stock del artículo se restaurará.">
                                        <i class="fa fa-trash"></i>
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

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
