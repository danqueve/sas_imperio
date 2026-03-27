<?php
// ============================================================
// articulos/index.php — Gestión de Artículos
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();

// ── Filtros ──────────────────────────────────────────────────
$q            = trim($_GET['q'] ?? '');
$categoria    = trim($_GET['categoria'] ?? '');
$stock_filtro = trim($_GET['stock_filtro'] ?? '');
$activo_f     = $_GET['activo'] ?? '1';   // default: solo activos
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 25;
$offset       = ($page - 1) * $limit;

// ── WHERE dinámico ───────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[]  = '(a.descripcion LIKE ? OR a.sku LIKE ?)';
    $like     = "%$q%";
    $params[] = $like;
    $params[] = $like;
}
if ($categoria !== '') {
    $where[]  = 'a.categoria = ?';
    $params[] = $categoria;
}
if ($stock_filtro === 'sin_stock') {
    $where[] = 'a.stock = 0';
} elseif ($stock_filtro === 'stock_bajo') {
    $where[] = 'a.stock BETWEEN 1 AND 4';
}
if ($activo_f === '0') {
    $where[]  = 'a.activo = 0';
} elseif ($activo_f === '1') {
    $where[]  = 'a.activo = 1';
}
// activo_f === 'todos' → sin filtro

$whereStr = implode(' AND ', $where);

// ── KPIs (una sola query) ────────────────────────────────────
$kpi = $pdo->query("
    SELECT
        SUM(activo = 1)                           AS total_activos,
        SUM(activo = 1 AND stock = 0)             AS sin_stock,
        SUM(activo = 1 AND stock BETWEEN 1 AND 4) AS stock_bajo,
        SUM(activo = 0)                           AS inactivos
    FROM ic_articulos a
")->fetch();

// ── Total para paginación ────────────────────────────────────
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM ic_articulos a WHERE $whereStr");
$cntStmt->execute($params);
$total     = (int)$cntStmt->fetchColumn();
$totalPags = (int)ceil($total / $limit);

// ── Query principal ──────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT a.*
    FROM ic_articulos a
    WHERE $whereStr
    ORDER BY a.descripcion
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$lista = $stmt->fetchAll();

// ── Categorías para dropdown ─────────────────────────────────
$categorias = $pdo->query("
    SELECT DISTINCT categoria FROM ic_articulos
    WHERE categoria IS NOT NULL AND categoria <> ''
    ORDER BY categoria
")->fetchAll(PDO::FETCH_COLUMN);

// ── Helpers de render (reutilizados en AJAX) ─────────────────
function render_tbody(array $lista): string {
    if (empty($lista)) {
        return '<tr><td colspan="10" class="text-center text-muted" style="padding:40px">Sin artículos para los filtros aplicados.</td></tr>';
    }
    $html = '';
    foreach ($lista as $a) {
        $st = (int)$a['stock'];
        $stock_badge = $st > 4
            ? '<span class="badge-ic badge-success">' . $st . '</span>'
            : ($st > 0
                ? '<span class="badge-ic badge-warning">' . $st . '</span>'
                : '<span class="badge-ic badge-danger">0</span>');
        $activo_badge = $a['activo']
            ? '<span class="badge-ic badge-success">Sí</span>'
            : '<span class="badge-ic badge-muted">No</span>';
        $desc_js = e(addslashes($a['descripcion']));
        $html .= '<tr>'
            . '<td class="text-muted">#' . (int)$a['id'] . '</td>'
            . '<td class="text-muted" style="font-size:.82rem;font-family:monospace">' . e($a['sku'] ?: '—') . '</td>'
            . '<td class="fw-bold">' . e($a['descripcion']) . '</td>'
            . '<td>' . e($a['categoria'] ?: '—') . '</td>'
            . '<td class="nowrap fw-bold">' . ($a['precio_venta'] ? formato_pesos($a['precio_venta']) : '—') . '</td>'
            . '<td class="nowrap">' . ($a['precio_contado'] ? formato_pesos($a['precio_contado']) : '—') . '</td>'
            . '<td class="nowrap">' . ($a['precio_tarjeta'] ? formato_pesos($a['precio_tarjeta']) : '—') . '</td>'
            . '<td class="text-center">' . $stock_badge . '</td>'
            . '<td class="text-center">' . $activo_badge . '</td>'
            . '<td class="nowrap">'
            .   '<button onclick="verClientes(' . (int)$a['id'] . ',\'' . $desc_js . '\')" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver clientes con crédito"><i class="fa fa-users"></i></button> '
            .   '<a href="qr_label?id=' . (int)$a['id'] . '" target="_blank" class="btn-ic btn-ghost btn-sm btn-icon" title="Etiqueta QR"><i class="fa fa-qrcode"></i></a> '
            .   '<a href="editar?id=' . (int)$a['id'] . '" class="btn-ic btn-ghost btn-sm btn-icon" title="Editar"><i class="fa fa-pencil"></i></a> '
            .   '<a href="eliminar?id=' . (int)$a['id'] . '" class="btn-ic btn-danger btn-sm btn-icon" title="Eliminar" data-confirm="¿Eliminar el artículo «' . e($a['descripcion']) . '»?"><i class="fa fa-trash"></i></a>'
            . '</td>'
            . '</tr>';
    }
    return $html;
}

function render_paginador(int $page, int $totalPags, array $get): string {
    if ($totalPags <= 1) return '';
    $html = '<div class="pagination mt-3">';
    for ($p = 1; $p <= $totalPags; $p++) {
        $href = '?' . http_build_query(array_merge($get, ['page' => $p]));
        $html .= '<a href="' . $href . '" class="page-item ' . ($p === $page ? 'active' : '') . '">' . $p . '</a>';
    }
    $html .= '</div>';
    return $html;
}

// ── Respuesta AJAX ───────────────────────────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'tbody'     => render_tbody($lista),
        'paginador' => render_paginador($page, $totalPags, $_GET),
        'total'     => $total,
    ]);
    exit;
}

// ── Parámetros para links PDF (preservar filtros activos) ────
$pdf_params = http_build_query(array_filter([
    'categoria'    => $categoria,
    'stock_filtro' => $stock_filtro,
]));

// ── Render completo ──────────────────────────────────────────
$page_title    = 'Artículos';
$page_current  = 'articulos';
$topbar_actions = '<a href="nuevo" class="btn-ic btn-primary btn-sm"><i class="fa fa-plus"></i> Nuevo Artículo</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Activos</div>
        <div style="font-size:1.6rem;font-weight:800;color:var(--primary-light);margin-top:4px">
            <?= number_format((int)$kpi['total_activos']) ?>
        </div>
        <div class="text-muted" style="font-size:.78rem">artículo<?= (int)$kpi['total_activos'] !== 1 ? 's' : '' ?></div>
    </div>
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Sin Stock</div>
        <div style="font-size:1.6rem;font-weight:800;color:var(--danger);margin-top:4px">
            <?= number_format((int)$kpi['sin_stock']) ?>
        </div>
        <div class="text-muted" style="font-size:.78rem">sin unidades</div>
    </div>
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Stock Bajo</div>
        <div style="font-size:1.6rem;font-weight:800;color:var(--warning);margin-top:4px">
            <?= number_format((int)$kpi['stock_bajo']) ?>
        </div>
        <div class="text-muted" style="font-size:.78rem">1 a 4 unidades</div>
    </div>
    <div class="card-ic" style="padding:16px 20px">
        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.7px">Inactivos</div>
        <div style="font-size:1.6rem;font-weight:800;color:var(--text-muted,#888);margin-top:4px">
            <?= number_format((int)$kpi['inactivos']) ?>
        </div>
        <div class="text-muted" style="font-size:.78rem">desactivados</div>
    </div>
</div>

<!-- Filtros -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar" id="filter-form" style="flex-wrap:wrap;gap:10px">
        <input type="text" name="q" id="q_input" value="<?= e($q) ?>" placeholder="🔍 Descripción o SKU..." style="flex:1;min-width:160px">
        <select name="categoria" id="sel_categoria" style="max-width:180px">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $categoria === $cat ? 'selected' : '' ?>>
                    <?= e($cat) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="stock_filtro" id="sel_stock" style="max-width:150px">
            <option value="">Todo el stock</option>
            <option value="sin_stock"  <?= $stock_filtro === 'sin_stock'  ? 'selected' : '' ?>>Sin stock</option>
            <option value="stock_bajo" <?= $stock_filtro === 'stock_bajo' ? 'selected' : '' ?>>Stock bajo (1-4)</option>
        </select>
        <select name="activo" id="sel_activo" style="max-width:130px">
            <option value="1"     <?= $activo_f === '1'     ? 'selected' : '' ?>>Solo activos</option>
            <option value="0"     <?= $activo_f === '0'     ? 'selected' : '' ?>>Solo inactivos</option>
            <option value="todos" <?= $activo_f === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
        <input type="hidden" name="page" value="1">
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="?" class="btn-ic btn-ghost">Limpiar</a>
        <a href="stock_pdf<?= $pdf_params ? '?' . $pdf_params : '' ?>" target="_blank" class="btn-ic btn-ghost" title="Exportar stock a PDF">
            <i class="fa fa-file-pdf"></i> PDF Stock
        </a>
        <a href="creditos_pdf" target="_blank" class="btn-ic btn-ghost" title="Clientes por artículo">
            <i class="fa fa-users"></i> PDF Clientes
        </a>
        <a href="nuevo" class="btn-ic btn-primary" style="margin-left:auto">
            <i class="fa fa-plus"></i> Nuevo Artículo
        </a>
    </form>
</div>

<!-- Tabla -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-box-open"></i> Catálogo de Artículos</span>
        <span class="text-muted" id="resultado_contador" style="font-size:.82rem">
            <?= number_format($total) ?> artículo<?= $total !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>SKU</th>
                    <th>Descripción</th>
                    <th>Categoría</th>
                    <th>Precio Venta</th>
                    <th>Contado</th>
                    <th>Tarjeta</th>
                    <th>Stock</th>
                    <th>Activo</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="tbody_articulos">
                <?= render_tbody($lista) ?>
            </tbody>
        </table>
    </div>

    <div id="paginador_wrap">
        <?= render_paginador($page, $totalPags, $_GET) ?>
    </div>
</div>

<!-- Modal: clientes con crédito del artículo -->
<div id="modal-clientes" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:16px">
    <div class="card-ic" style="max-width:800px;width:100%;max-height:85vh;display:flex;flex-direction:column">
        <div class="card-ic-header" style="flex-shrink:0">
            <span class="card-title" id="modal-titulo"><i class="fa fa-users"></i> Clientes con crédito</span>
            <button onclick="cerrarModal()" class="btn-ic btn-ghost btn-sm btn-icon"><i class="fa fa-times"></i></button>
        </div>
        <div id="modal-body" style="overflow-y:auto;padding:0"></div>
    </div>
</div>

<script>
(function() {
    var form     = document.getElementById('filter-form');
    var qInput   = document.getElementById('q_input');
    var selects  = ['sel_categoria', 'sel_stock', 'sel_activo'].map(function(id) { return document.getElementById(id); });
    var tbody    = document.getElementById('tbody_articulos');
    var pagWrap  = document.getElementById('paginador_wrap');
    var counter  = document.getElementById('resultado_contador');
    var timer;

    function fetchArticulos() {
        var params = new URLSearchParams(new FormData(form));
        params.set('page', '1');
        params.set('ajax', '1');

        tbody.style.opacity = '0.5';

        fetch('?' + params.toString())
            .then(function(r) { return r.json(); })
            .then(function(d) {
                tbody.innerHTML   = d.tbody;
                pagWrap.innerHTML = d.paginador;
                counter.textContent = d.total.toLocaleString('es-AR') + ' artículo' + (d.total !== 1 ? 's' : '');
                tbody.style.opacity = '1';
                if (typeof bindConfirm === 'function') bindConfirm();
            })
            .catch(function() { tbody.style.opacity = '1'; });
    }

    qInput.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(fetchArticulos, 400);
    });

    selects.forEach(function(sel) {
        if (sel) sel.addEventListener('change', fetchArticulos);
    });
})();

// ── Modal clientes ────────────────────────────────────────────
function verClientes(id, desc) {
    var modal = document.getElementById('modal-clientes');
    var body  = document.getElementById('modal-body');
    var titulo = document.getElementById('modal-titulo');

    titulo.innerHTML = '<i class="fa fa-users"></i> ' + desc;
    body.innerHTML   = '<div style="padding:32px;text-align:center;color:var(--text-muted,#888)"><i class="fa fa-spinner fa-spin"></i> Cargando...</div>';
    modal.style.display = 'flex';

    fetch('clientes_articulo?id=' + id + '&ajax=1')
        .then(function(r) { return r.text(); })
        .then(function(html) { body.innerHTML = html; })
        .catch(function() { body.innerHTML = '<div style="padding:24px;color:var(--danger)">Error al cargar.</div>'; });
}

function cerrarModal() {
    document.getElementById('modal-clientes').style.display = 'none';
}

// Cerrar al click en backdrop
document.getElementById('modal-clientes').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') cerrarModal();
});
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
