<?php
// tickets/index.php — Lista de tickets (todos los roles)
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

$pdo    = obtener_conexion();
$uid    = (int) $_SESSION['user_id'];
$rol    = $_SESSION['rol'];

// ── Filtros ──────────────────────────────────────────────────
$f_estado = $_GET['estado'] ?? '';
$f_tipo   = $_GET['tipo']   ?? 'todos'; // todos | mios | para_mi
$f_q      = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($f_estado && in_array($f_estado, ['abierto','en_progreso','resuelto'])) {
    $where[]  = 'tk.estado = ?';
    $params[] = $f_estado;
}

if ($f_tipo === 'mios') {
    $where[]  = 'tk.creado_por = ?';
    $params[] = $uid;
} elseif ($f_tipo === 'para_mi') {
    $where[]  = '(tk.delegado_a_usuario = ? OR tk.delegado_a_rol = ?)';
    $params[] = $uid;
    $params[] = $rol;
}

if ($f_q !== '') {
    $where[]  = '(tk.titulo LIKE ? OR tk.descripcion LIKE ?)';
    $params[] = "%{$f_q}%";
    $params[] = "%{$f_q}%";
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT tk.id, tk.titulo, tk.estado, tk.prioridad, tk.created_at, tk.updated_at,
           CONCAT(uc.nombre, ' ', uc.apellido) AS creado_por_nombre,
           CONCAT(ud.nombre, ' ', ud.apellido) AS delegado_nombre,
           tk.delegado_a_rol,
           (SELECT COUNT(*) FROM ic_ticket_respuestas tr WHERE tr.ticket_id = tk.id) AS num_resp
    FROM ic_tickets tk
    JOIN ic_usuarios uc ON uc.id = tk.creado_por
    LEFT JOIN ic_usuarios ud ON ud.id = tk.delegado_a_usuario
    WHERE {$where_sql}
    ORDER BY FIELD(tk.estado,'abierto','en_progreso','resuelto'),
             FIELD(tk.prioridad,'alta','media','baja'),
             tk.updated_at DESC
");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// conteos para badges de filtro
$cnt = $pdo->prepare("SELECT
    SUM(estado='abierto') AS ab,
    SUM(estado='en_progreso') AS ep,
    SUM(estado='resuelto') AS res
  FROM ic_tickets");
$cnt->execute();
$totales = $cnt->fetch();

// ── Layout ───────────────────────────────────────────────────
$page_title   = 'Tickets de Soporte';
$page_current = 'tickets';
$topbar_actions = '<a href="nuevo" class="btn-ic btn-accent btn-sm"><i class="fa fa-plus me-1"></i> Nuevo Ticket</a>';
require_once __DIR__ . '/../views/layout.php';

function badge_estado_ticket(string $e): string {
    return match($e) {
        'abierto'     => '<span class="badge-status open">Abierto</span>',
        'en_progreso' => '<span class="badge-status progress">En progreso</span>',
        'resuelto'    => '<span class="badge-status resolved">Resuelto</span>',
        default       => '<span class="badge bg-secondary">' . htmlspecialchars($e) . '</span>',
    };
}

function badge_prioridad(string $p): string {
    return match($p) {
        'alta'  => '<span class="badge-prio high"><i class="fa fa-angles-up me-1"></i>Alta</span>',
        'media' => '<span class="badge-prio medium"><i class="fa fa-minus me-1"></i>Media</span>',
        'baja'  => '<span class="badge-prio low"><i class="fa fa-angles-down me-1"></i>Baja</span>',
        default => '',
    };
}
?>

<?php
$is_light = ($rol === 'cobrador');
$card_bg  = $is_light ? '#ffffff' : 'rgba(30,41,59,.4)';
$card_bor = $is_light ? '#d1d5db' : 'rgba(255,255,255,.05)';
$text_main = $is_light ? '#1f2937' : '#e5e7eb';
$text_muted = $is_light ? '#6b7280' : '#94a3b8';
$filter_bg = $is_light ? '#f9fafb' : 'rgba(30,41,59,.4)';
?>

<style>
.badge-status {
    padding: 4px 10px; border-radius: 6px; font-size: .72rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .3px; display: inline-flex; align-items: center;
}
.badge-status.open     { background: rgba(239,68,68,<?= $is_light ? '.1' : '.15' ?>); color: <?= $is_light ? '#dc2626' : '#fca5a5' ?>; border: 1px solid rgba(239,68,68,.2); }
.badge-status.progress { background: rgba(245,158,11,<?= $is_light ? '.1' : '.15' ?>); color: <?= $is_light ? '#d97706' : '#fcd34d' ?>; border: 1px solid rgba(245,158,11,.2); }
.badge-status.resolved { background: rgba(34,197,94,<?= $is_light ? '.1' : '.15' ?>); color: <?= $is_light ? '#16a34a' : '#86efac' ?>; border: 1px solid rgba(34,197,94,.2); }

.badge-prio { font-size: .75rem; font-weight: 500; display: inline-flex; align-items: center; }
.badge-prio.high   { color: <?= $is_light ? '#dc2626' : '#fca5a5' ?>; }
.badge-prio.medium { color: <?= $is_light ? '#4f46e5' : '#a5b4fc' ?>; }
.badge-prio.low    { color: <?= $is_light ? '#6b7280' : '#9ca3af' ?>; }

.ticket-row { transition: all .2s ease; border-bottom: 1px solid <?= $is_light ? '#e5e7eb' : 'rgba(255,255,255,.03)' ?> !important; }
.ticket-row:hover { background: rgba(99,102,241,<?= $is_light ? '.04' : '.06' ?>) !important; transform: translateX(4px); }
.ticket-id { font-family: monospace; font-size: .8rem; opacity: <?= $is_light ? '.7' : '.5' ?>; color: <?= $text_muted ?>; }
.ticket-title { font-size: .92rem; color: <?= $text_main ?>; transition: color .2s; }
.ticket-row:hover .ticket-title { color: #3c50e0; }

.filter-card {
    background: <?= $filter_bg ?>; border: 1px solid <?= $card_bor ?>;
    border-radius: 12px; padding: 20px; margin-bottom: 24px;
}

/* Ajustes para inputs en tema claro */
<?php if ($is_light): ?>
.filter-card .input-group-text { background: #f3f4f6 !important; border-color: #d1d5db !important; color: #6b7280 !important; }
.filter-card input, .filter-card select { background: #ffffff !important; border-color: #d1d5db !important; color: #1f2937 !important; }
.table-dark { background: #ffffff !important; color: #1f2937 !important; }
.table-dark thead tr { background: #f9fafb !important; color: #6b7280 !important; border-bottom: 2px solid #e5e7eb !important; }
.ticket-row .text-light { color: #1f2937 !important; }
<?php endif; ?>
</style>

<div class="filter-card">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label <?= $is_light ? 'text-dark' : 'text-muted' ?> small fw-bold mb-1">BUSCAR POR TEXTO</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-dark border-secondary text-muted"><i class="fa fa-search"></i></span>
                <input type="text" name="q" class="form-control bg-dark border-secondary text-light"
                       placeholder="Título o descripción..." value="<?= htmlspecialchars($f_q) ?>">
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label <?= $is_light ? 'text-dark' : 'text-muted' ?> small fw-bold mb-1">ESTADO</label>
            <select name="estado" class="form-select form-select-sm bg-dark border-secondary text-light">
                <option value="">Cualquiera</option>
                <option value="abierto"     <?= $f_estado==='abierto'     ? 'selected':'' ?>>Abierto</option>
                <option value="en_progreso" <?= $f_estado==='en_progreso' ? 'selected':'' ?>>En progreso</option>
                <option value="resuelto"    <?= $f_estado==='resuelto'    ? 'selected':'' ?>>Resuelto</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label <?= $is_light ? 'text-dark' : 'text-muted' ?> small fw-bold mb-1">PERTENENCIA</label>
            <select name="tipo" class="form-select form-select-sm bg-dark border-secondary text-light">
                <option value="todos"   <?= $f_tipo==='todos'   ? 'selected':'' ?>>Todos los tickets</option>
                <option value="mios"    <?= $f_tipo==='mios'    ? 'selected':'' ?>>Creados por mí</option>
                <option value="para_mi" <?= $f_tipo==='para_mi' ? 'selected':'' ?>>Delegados a mí</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn-ic btn-sm btn-primary">
                <i class="fa fa-filter me-1"></i> Filtrar
            </button>
            <?php if ($f_q || $f_estado || $f_tipo !== 'todos'): ?>
                <a href="index" class="btn-ic btn-sm btn-ghost ms-1">
                    <i class="fa fa-times me-1"></i> Limpiar
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="d-flex gap-4 mt-3 pt-3 border-top border-secondary border-opacity-10" style="font-size:.78rem">
        <div class="d-flex align-items-center gap-2">
            <span class="rounded-circle" style="width:8px;height:8px;background:#ef4444"></span>
            <span class="<?= $is_light ? 'text-dark' : 'text-muted' ?>"><?= (int)$totales['ab'] ?> abiertos</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="rounded-circle" style="width:8px;height:8px;background:#f59e0b"></span>
            <span class="<?= $is_light ? 'text-dark' : 'text-muted' ?>"><?= (int)$totales['ep'] ?> en progreso</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="rounded-circle" style="width:8px;height:8px;background:#10b981"></span>
            <span class="<?= $is_light ? 'text-dark' : 'text-muted' ?>"><?= (int)$totales['res'] ?> resueltos</span>
        </div>
    </div>
</div>

<div class="card-ic p-0 overflow-hidden" style="background:<?= $card_bg ?>; border:1px solid <?= $card_bor ?>">
    <?php if (empty($tickets)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-ticket-alt fa-3x mb-3 d-block opacity-20"></i>
            <p class="mb-0">No se encontraron tickets con los criterios actuales.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table <?= $is_light ? '' : 'table-dark' ?> mb-0 align-middle" style="font-size:.88rem">
                <thead>
                    <tr style="<?= $is_light ? '' : 'background: rgba(255,255,255,.02);' ?> color:#9ca3af; font-size:.72rem; text-transform:uppercase; letter-spacing:1px">
                        <th class="ps-4" style="width:80px">ID</th>
                        <th>Asunto del Ticket</th>
                        <th style="width:130px">Estado</th>
                        <th style="width:110px">Prioridad</th>
                        <th style="width:160px">Responsables</th>
                        <th style="width:80px" class="text-center"><i class="fa fa-comments"></i></th>
                        <th class="pe-4 text-end" style="width:130px">Actualizado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr class="ticket-row" style="cursor:pointer" onclick="window.location='ver?id=<?= $t['id'] ?>'">
                            <td class="ps-4 ticket-id">#<?= str_pad($t['id'], 4, '0', STR_PAD_LEFT) ?></td>
                            <td>
                                <div class="ticket-title fw-semibold mb-1"><?= e(mb_strimwidth($t['titulo'], 0, 80, '...')) ?></div>
                                <div class="text-muted smaller" style="font-size:.72rem">
                                    <i class="fa fa-user-circle me-1 opacity-50"></i><?= e($t['creado_por_nombre']) ?>
                                </div>
                            </td>
                            <td><?= badge_estado_ticket($t['estado']) ?></td>
                            <td><?= badge_prioridad($t['prioridad']) ?></td>
                            <td>
                                <div class="d-flex flex-column gap-1" style="font-size:.75rem">
                                    <?php if ($t['delegado_nombre']): ?>
                                        <span class="<?= $is_light ? 'text-dark' : 'text-light' ?>"><i class="fa fa-user me-1 opacity-50"></i><?= e($t['delegado_nombre']) ?></span>
                                    <?php elseif ($t['delegado_a_rol']): ?>
                                        <span style="color:#4f46e5"><i class="fa fa-users me-1 opacity-50"></i><?= ucfirst(e($t['delegado_a_rol'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted italic opacity-40">Sin asignar</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php if ($t['num_resp'] > 0): ?>
                                    <span class="badge rounded-pill bg-primary" style="font-size:.65rem"><?= (int)$t['num_resp'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted opacity-20">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end text-muted" style="font-size:.75rem">
                                <?= date('d/m/Y', strtotime($t['updated_at'])) ?><br>
                                <span class="opacity-50"><?= date('H:i', strtotime($t['updated_at'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
