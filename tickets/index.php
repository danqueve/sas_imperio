<?php
// tickets/index.php — Tablero de Reclamos y Posventa (Kanban por estado)
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_reclamos');

$pdo = obtener_conexion();
$uid = (int) $_SESSION['user_id'];
$is_cobrador = es_cobrador();
$puede_filtrar_cobrador = es_admin() || es_supervisor();

// ── Filtros ──────────────────────────────────────────────────
$f_tipo      = $_GET['tipo'] ?? 'todos';
$f_cobrador  = $puede_filtrar_cobrador ? (int) ($_GET['cobrador_id'] ?? 0) : 0;
$f_q         = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($is_cobrador) {
    $where[]  = 'cl.cobrador_id = ?';
    $params[] = $uid;
} elseif ($f_cobrador > 0) {
    $where[]  = 'cl.cobrador_id = ?';
    $params[] = $f_cobrador;
}

if (in_array($f_tipo, ['reclamo', 'posventa'], true)) {
    $where[]  = 'tk.tipo = ?';
    $params[] = $f_tipo;
}

if ($f_q !== '') {
    $where[]  = "(cl.nombres LIKE ? OR cl.apellidos LIKE ?)";
    $params[] = "%{$f_q}%";
    $params[] = "%{$f_q}%";
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT tk.id, tk.tipo, tk.titulo, tk.descripcion, tk.estado, tk.prioridad,
           tk.created_at, tk.updated_at, tk.cliente_id, tk.credito_id,
           cl.nombres AS cliente_nombres, cl.apellidos AS cliente_apellidos,
           cob.nombre AS cobrador_nombre, cob.apellido AS cobrador_apellido,
           COALESCE(cr.articulo_desc, art.descripcion, 'Sin artículo') AS articulo,
           (SELECT COUNT(*) FROM ic_ticket_respuestas tr WHERE tr.ticket_id = tk.id) AS num_resp
    FROM ic_tickets tk
    JOIN ic_clientes cl  ON cl.id = tk.cliente_id
    LEFT JOIN ic_usuarios cob ON cob.id = cl.cobrador_id
    JOIN ic_creditos cr  ON cr.id = tk.credito_id
    LEFT JOIN ic_articulos art ON art.id = cr.articulo_id
    WHERE {$where_sql}
    ORDER BY FIELD(tk.prioridad,'alta','media','baja'), tk.updated_at DESC
");
$stmt->execute($params);
$todos = $stmt->fetchAll();

$columnas = ['abierto' => [], 'en_progreso' => [], 'resuelto' => []];
foreach ($todos as $t) {
    $columnas[$t['estado']][] = $t;
}

$cobradores = [];
if ($puede_filtrar_cobrador) {
    $cobradores = $pdo->query("SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY apellido, nombre")->fetchAll();
}

function tk_tiempo_relativo(string $fecha): string
{
    $diff = time() - strtotime($fecha);
    if ($diff < 3600)  return 'hace ' . max(1, (int) ($diff / 60)) . ' min';
    if ($diff < 86400) return 'hace ' . (int) ($diff / 3600) . 'h';
    $dias = (int) ($diff / 86400);
    return $dias === 1 ? 'hace 1 día' : "hace {$dias} días";
}

function tk_badge_prioridad(string $p): string
{
    $map = [
        'alta'  => ['danger',  'fa-angles-up',   'Alta'],
        'media' => ['info',    'fa-minus',       'Media'],
        'baja'  => ['muted',   'fa-angles-down', 'Baja'],
    ];
    [$clase, $icono, $label] = $map[$p] ?? ['muted', 'fa-minus', $p];
    return "<span class=\"badge-ic badge-{$clase}\"><i class=\"fa {$icono}\"></i> {$label}</span>";
}

$COLUMNAS_DEF = [
    'abierto'     => ['label' => 'Abierto',     'color' => 'var(--danger)',  'icon' => 'fa-circle-exclamation'],
    'en_progreso' => ['label' => 'En Progreso', 'color' => 'var(--warning)', 'icon' => 'fa-spinner'],
    'resuelto'    => ['label' => 'Resuelto',    'color' => 'var(--success)', 'icon' => 'fa-circle-check'],
];

$page_title   = 'Reclamos y Posventa';
$page_current = 'tickets';
$topbar_actions = '<a href="nuevo" class="btn-ic btn-accent btn-sm"><i class="fa fa-plus"></i> Nuevo Caso</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<style>
.tk-card {
    background: var(--dark-card); border: 1px solid var(--dark-border); border-radius: var(--radius-sm);
    padding: 14px; margin-bottom: 12px; cursor: pointer; transition: transform .12s, box-shadow .12s;
}
.tk-card:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
.tk-card--alta  { border-left: 3px solid var(--danger); }
.tk-card--media { border-left: 3px solid var(--accent); }
.tk-card--baja  { border-left: 3px solid var(--dark-border-2); }

.tk-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 8px; }
.tk-card-tag { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; display: inline-flex; align-items: center; gap: 5px; }
.tk-card-tag.reclamo  { color: var(--danger-light); }
.tk-card-tag.posventa { color: var(--accent); }
.tk-card-id { font-size: .7rem; color: var(--text-muted); font-family: monospace; }

.tk-card-cliente { font-weight: 700; font-size: .9rem; color: var(--text-main); margin-bottom: 3px; }
.tk-card-cliente:hover { color: var(--primary-light); }
.tk-card-credito { font-size: .76rem; color: var(--text-muted); margin-bottom: 8px; }
.tk-card-desc { font-size: .8rem; color: var(--text-main); line-height: 1.4; margin-bottom: 10px; }

.tk-card-footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: .7rem; color: var(--text-muted); margin-bottom: 10px; flex-wrap: wrap; }
.tk-card-footer .resp-count { display: inline-flex; align-items: center; gap: 4px; }

.tk-card-action { display: block; }
.tk-card-action button {
    width: 100%; justify-content: center; padding: 7px 10px; font-size: .78rem;
}

.kanban-board { display: flex; gap: 16px; overflow-x: auto; padding-bottom: 10px; scroll-snap-type: x proximity; }
.kanban-col { flex: 1 1 320px; min-width: 300px; max-width: 400px; scroll-snap-align: start; }
.kanban-col-header {
    display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: var(--radius-sm);
    background: var(--dark-card); border: 1px solid var(--dark-border); margin-bottom: 12px;
    font-weight: 700; font-size: .85rem; color: var(--text-main);
}
.kanban-col-header .dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.kanban-col-header .count { margin-left: auto; font-size: .72rem; color: var(--text-muted); font-weight: 600; }
.kanban-col-body { min-height: 60px; }
.kanban-empty { text-align: center; padding: 24px 10px; color: var(--text-muted); font-size: .8rem; opacity: .7; }

body.theme-cobrador .tk-card { background: #ffffff; border-color: #e5e7eb; }
body.theme-cobrador .kanban-col-header { background: #ffffff; border-color: #e5e7eb; }
</style>

<form method="GET" class="filter-bar">
    <div>
        <label style="display:block;font-size:.68rem;color:var(--text-muted);margin-bottom:4px">TIPO</label>
        <select name="tipo">
            <option value="todos"    <?= $f_tipo === 'todos'    ? 'selected' : '' ?>>Todos</option>
            <option value="reclamo"  <?= $f_tipo === 'reclamo'  ? 'selected' : '' ?>>Reclamo</option>
            <option value="posventa" <?= $f_tipo === 'posventa' ? 'selected' : '' ?>>Posventa</option>
        </select>
    </div>
    <?php if ($puede_filtrar_cobrador): ?>
    <div>
        <label style="display:block;font-size:.68rem;color:var(--text-muted);margin-bottom:4px">COBRADOR</label>
        <select name="cobrador_id">
            <option value="0">Todos</option>
            <?php foreach ($cobradores as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $f_cobrador === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['apellido'] . ', ' . $c['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div>
        <label style="display:block;font-size:.68rem;color:var(--text-muted);margin-bottom:4px">CLIENTE</label>
        <input type="text" name="q" placeholder="Buscar por nombre..." value="<?= e($f_q) ?>">
    </div>
    <div>
        <button type="submit" class="btn-ic btn-primary btn-sm"><i class="fa fa-filter"></i> Filtrar</button>
        <?php if ($f_q || $f_tipo !== 'todos' || $f_cobrador): ?>
            <a href="index" class="btn-ic btn-ghost btn-sm"><i class="fa fa-times"></i> Limpiar</a>
        <?php endif; ?>
    </div>
</form>

<div class="kanban-board">
    <?php foreach ($COLUMNAS_DEF as $estado_key => $def): ?>
        <div class="kanban-col">
            <div class="kanban-col-header">
                <span class="dot" style="background:<?= $def['color'] ?>"></span>
                <i class="fa <?= $def['icon'] ?>"></i>
                <?= $def['label'] ?>
                <span class="count"><?= count($columnas[$estado_key]) ?></span>
            </div>
            <div class="kanban-col-body">
                <?php if (empty($columnas[$estado_key])): ?>
                    <div class="kanban-empty">Sin casos en este estado.</div>
                <?php else: ?>
                    <?php foreach ($columnas[$estado_key] as $t): ?>
                        <?php
                        $es_reclamo = $t['tipo'] === 'reclamo';
                        $icono_tipo = $es_reclamo ? 'fa-triangle-exclamation' : 'fa-screwdriver-wrench';
                        $cobrador_nombre = $t['cobrador_nombre'] ? trim($t['cobrador_nombre'] . ' ' . $t['cobrador_apellido']) : null;
                        ?>
                        <div class="tk-card tk-card--<?= e($t['prioridad']) ?>" onclick="window.location='ver?id=<?= (int) $t['id'] ?>'">
                            <div class="tk-card-top">
                                <span class="tk-card-tag <?= $t['tipo'] ?>">
                                    <i class="fa <?= $icono_tipo ?>"></i> <?= ucfirst($t['tipo']) ?>
                                </span>
                                <span class="tk-card-id">#<?= (int) $t['id'] ?></span>
                            </div>
                            <a href="../clientes/ver?id=<?= (int) $t['cliente_id'] ?>" class="tk-card-cliente" onclick="event.stopPropagation()">
                                <?= e($t['cliente_apellidos'] . ', ' . $t['cliente_nombres']) ?>
                            </a>
                            <div class="tk-card-credito">
                                <i class="fa fa-file-invoice-dollar" style="opacity:.6"></i>
                                Crédito #<?= (int) $t['credito_id'] ?> — <?= e(mb_strimwidth($t['articulo'], 0, 40, '...')) ?>
                            </div>
                            <div class="tk-card-desc"><?= e(mb_strimwidth($t['titulo'], 0, 90, '...')) ?></div>
                            <div class="tk-card-footer">
                                <?= tk_badge_prioridad($t['prioridad']) ?>
                                <?php if ($cobrador_nombre && !$is_cobrador): ?>
                                    <span><i class="fa fa-id-badge" style="opacity:.6"></i> <?= e($cobrador_nombre) ?></span>
                                <?php endif; ?>
                                <span class="resp-count"><i class="fa fa-comments"></i> <?= (int) $t['num_resp'] ?></span>
                                <span><?= tk_tiempo_relativo($t['updated_at']) ?></span>
                            </div>
                            <?php if ($estado_key !== 'resuelto'): ?>
                                <div class="tk-card-action" onclick="event.stopPropagation()">
                                    <form method="POST" action="procesar_respuesta">
                                        <?php csrf_input(); ?>
                                        <input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>">
                                        <?php if ($estado_key === 'abierto'): ?>
                                            <input type="hidden" name="solo_estado" value="en_progreso">
                                            <button type="submit" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-right"></i> Pasar a En Progreso</button>
                                        <?php else: ?>
                                            <input type="hidden" name="solo_estado" value="resuelto">
                                            <button type="submit" class="btn-ic btn-success btn-sm"><i class="fa fa-check"></i> Resolver</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="tk-card-action" onclick="event.stopPropagation()">
                                    <form method="POST" action="procesar_respuesta">
                                        <?php csrf_input(); ?>
                                        <input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>">
                                        <input type="hidden" name="solo_estado" value="abierto">
                                        <button type="submit" class="btn-ic btn-ghost btn-sm"><i class="fa fa-rotate-left"></i> Reabrir</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
