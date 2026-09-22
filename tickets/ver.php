<?php
// tickets/ver.php — Detalle de un Reclamo/Posventa + hilo de conversación
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_reclamos');

$pdo       = obtener_conexion();
$uid       = (int) $_SESSION['user_id'];
$ticket_id = (int) ($_GET['id'] ?? 0);

if (!$ticket_id) {
    header('Location: index'); exit;
}

// ── Cargar ticket + cliente + crédito + artículo + cobrador ────
$stmt = $pdo->prepare("
    SELECT tk.*,
           cl.nombres AS cliente_nombres, cl.apellidos AS cliente_apellidos, cl.cobrador_id,
           cob.nombre AS cobrador_nombre, cob.apellido AS cobrador_apellido,
           cr.fecha_alta AS credito_fecha_alta, cr.estado AS credito_estado,
           COALESCE(cr.articulo_desc, art.descripcion, 'Sin artículo') AS articulo,
           CONCAT(uc.nombre, ' ', uc.apellido) AS creado_por_nombre
    FROM ic_tickets tk
    JOIN ic_clientes cl ON cl.id = tk.cliente_id
    LEFT JOIN ic_usuarios cob ON cob.id = cl.cobrador_id
    JOIN ic_creditos cr ON cr.id = tk.credito_id
    LEFT JOIN ic_articulos art ON art.id = cr.articulo_id
    JOIN ic_usuarios uc ON uc.id = tk.creado_por
    WHERE tk.id = ?
");
$stmt->execute([$ticket_id]);
$tk = $stmt->fetch();

if (!$tk) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Caso no encontrado.'];
    header('Location: index'); exit;
}

// Scoping de cartera: un cobrador solo puede ver casos de sus propios clientes.
if (es_cobrador() && (int) $tk['cobrador_id'] !== $uid) {
    http_response_code(403);
    require __DIR__ . '/../views/403.php';
    exit;
}

// ── Cargar respuestas ────────────────────────────────────────
$resp_stmt = $pdo->prepare("
    SELECT tr.*, CONCAT(u.nombre, ' ', u.apellido) AS autor
    FROM ic_ticket_respuestas tr
    JOIN ic_usuarios u ON u.id = tr.usuario_id
    WHERE tr.ticket_id = ?
    ORDER BY tr.created_at ASC
");
$resp_stmt->execute([$ticket_id]);
$respuestas = $resp_stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function tv_badge_estado(string $e): string
{
    $map = [
        'abierto'     => ['danger',  'Abierto'],
        'en_progreso' => ['warning', 'En Progreso'],
        'resuelto'    => ['success', 'Resuelto'],
    ];
    [$clase, $label] = $map[$e] ?? ['muted', $e];
    return "<span class=\"badge-ic badge-{$clase}\">{$label}</span>";
}

function tv_badge_prioridad(string $p): string
{
    $map = ['alta' => ['danger', 'Alta'], 'media' => ['info', 'Media'], 'baja' => ['muted', 'Baja']];
    [$clase, $label] = $map[$p] ?? ['muted', $p];
    return "<span class=\"badge-ic badge-{$clase}\">{$label}</span>";
}

$tipo_label = $tk['tipo'] === 'posventa' ? 'Posventa' : 'Reclamo';
$tipo_icon  = $tk['tipo'] === 'posventa' ? 'fa-screwdriver-wrench' : 'fa-triangle-exclamation';

$page_title   = ucfirst($tk['tipo']) . ' #' . $ticket_id;
$page_current = 'tickets';
$topbar_actions = '<a href="index" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<style>
.tv-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 900px) { .tv-layout { grid-template-columns: 1fr; } }

.tv-desc-box { margin-bottom: 20px; }
.tv-desc-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.tv-desc-tag { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
.tv-desc-badges { display: flex; gap: 8px; }
.tv-titulo { font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin-bottom: 10px; }
.tv-desc-text { color: var(--text-body); font-size: .92rem; line-height: 1.7; white-space: pre-wrap; margin-bottom: 14px; }
.tv-desc-meta { display: flex; gap: 16px; flex-wrap: wrap; font-size: .76rem; color: var(--text-muted); padding-top: 12px; border-top: 1px solid var(--dark-border); }
.tv-desc-meta span { display: inline-flex; align-items: center; gap: 6px; }

.tv-chat { display: flex; flex-direction: column; overflow: hidden; }
.tv-chat-header { display: flex; align-items: center; justify-content: space-between; padding: 4px 0 14px; border-bottom: 1px solid var(--dark-border); margin-bottom: 14px; }
.tv-chat-header .title { font-weight: 700; font-size: .85rem; color: var(--text-main); display: flex; align-items: center; gap: 8px; }
.tv-chat-body { max-height: 460px; overflow-y: auto; padding-right: 4px; margin-bottom: 16px; }

.tv-bubble-wrap { display: flex; gap: 10px; margin-bottom: 16px; max-width: 88%; }
.tv-bubble-wrap.mine { flex-direction: row-reverse; margin-left: auto; }
.tv-avatar {
    width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center;
    justify-content: center; font-size: .75rem; font-weight: 700; background: var(--dark-input); color: var(--text-muted);
}
.tv-bubble-wrap.mine .tv-avatar { background: var(--primary); color: #fff; }
.tv-bubble { padding: 10px 14px; border-radius: 12px; font-size: .87rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
.tv-bubble.other { background: var(--dark-input); color: var(--text-main); border: 1px solid var(--dark-border); border-top-left-radius: 3px; }
.tv-bubble.mine  { background: var(--primary); color: #fff; border-top-right-radius: 3px; }
.tv-bubble-meta { font-size: .66rem; color: var(--text-muted); margin-top: 5px; display: flex; gap: 8px; }
.tv-bubble-wrap.mine .tv-bubble-meta { justify-content: flex-end; }

.tv-empty-thread { text-align: center; padding: 20px; color: var(--text-muted); opacity: .6; font-size: .82rem; }

.tv-reply-form textarea { width: 100%; margin-bottom: 10px; }
.tv-reply-actions { display: flex; gap: 10px; flex-wrap: wrap; }

.tv-side { display: flex; flex-direction: column; gap: 16px; }
.tv-side-item { margin-bottom: 14px; }
.tv-side-item:last-child { margin-bottom: 0; }
.tv-side-label { font-size: .68rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 6px; }
.tv-side-value { font-size: .88rem; color: var(--text-main); }
.tv-side-value a { color: var(--primary-light); }

.tv-resuelto-box { text-align: center; padding: 18px; }
</style>

<?php if ($flash): ?>
    <div class="alert-ic alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="tv-layout">
    <!-- Columna principal -->
    <div>
        <div class="card-ic tv-desc-box">
            <div class="tv-desc-top">
                <span class="tv-desc-tag"><i class="fa <?= $tipo_icon ?>"></i> <?= e($tipo_label) ?> #<?= $ticket_id ?></span>
                <div class="tv-desc-badges">
                    <?= tv_badge_estado($tk['estado']) ?>
                    <?= tv_badge_prioridad($tk['prioridad']) ?>
                </div>
            </div>
            <div class="tv-titulo"><?= e($tk['titulo']) ?></div>
            <div class="tv-desc-text"><?= e($tk['descripcion']) ?></div>
            <div class="tv-desc-meta">
                <span><i class="fa fa-user-circle" style="opacity:.6"></i> <?= e($tk['creado_por_nombre']) ?></span>
                <span><i class="fa fa-calendar-alt" style="opacity:.6"></i> <?= date('d/m/Y H:i', strtotime($tk['created_at'])) ?></span>
            </div>
        </div>

        <div class="card-ic tv-chat">
            <div class="tv-chat-header">
                <span class="title"><i class="fa fa-comments" style="color:var(--primary-light)"></i> CONVERSACIÓN</span>
                <span class="badge-ic badge-muted"><?= count($respuestas) ?> respuestas</span>
            </div>

            <div id="tv-hilo" class="tv-chat-body">
                <?php if (empty($respuestas)): ?>
                    <div class="tv-empty-thread"><i class="fa fa-comment-slash" style="font-size:1.4rem;display:block;margin-bottom:6px"></i>Sin respuestas todavía.</div>
                <?php else: ?>
                    <?php foreach ($respuestas as $r): ?>
                        <?php $es_mio = ((int) $r['usuario_id'] === $uid); ?>
                        <div class="tv-bubble-wrap <?= $es_mio ? 'mine' : '' ?>">
                            <div class="tv-avatar"><?= mb_strtoupper(mb_substr($r['autor'], 0, 1)) ?></div>
                            <div>
                                <div class="tv-bubble <?= $es_mio ? 'mine' : 'other' ?>"><?= e($r['mensaje']) ?></div>
                                <div class="tv-bubble-meta">
                                    <span><?= $es_mio ? 'Tú' : e($r['autor']) ?></span>
                                    <span><?= date('d/m H:i', strtotime($r['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($tk['estado'] !== 'resuelto'): ?>
                <form method="POST" action="procesar_respuesta" class="form-ic tv-reply-form">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                    <textarea name="mensaje" rows="3" placeholder="Escribí una respuesta o novedad..." required></textarea>
                    <div class="tv-reply-actions">
                        <button type="submit" class="btn-ic btn-primary"><i class="fa fa-paper-plane"></i> Enviar</button>
                        <?php if ($tk['estado'] === 'abierto'): ?>
                            <button type="submit" name="solo_estado" value="en_progreso" class="btn-ic btn-warning"><i class="fa fa-arrow-right"></i> Pasar a En Progreso</button>
                        <?php endif; ?>
                        <button type="submit" name="solo_estado" value="resuelto" class="btn-ic btn-success"><i class="fa fa-check-double"></i> Resolver y Cerrar</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="tv-resuelto-box">
                    <span class="badge-ic badge-success" style="font-size:.85rem"><i class="fa fa-check-circle"></i> RESUELTO</span>
                    <form method="POST" action="procesar_respuesta" style="display:inline;margin-left:10px">
                        <?php csrf_input(); ?>
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                        <input type="hidden" name="solo_estado" value="abierto">
                        <button type="submit" class="btn-ic btn-ghost btn-sm"><i class="fa fa-rotate-left"></i> Reabrir</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="card-ic tv-side">
        <div>
            <span class="tv-side-label">Cliente</span>
            <div class="tv-side-value">
                <a href="../clientes/ver?id=<?= (int) $tk['cliente_id'] ?>">
                    <?= e($tk['cliente_apellidos'] . ', ' . $tk['cliente_nombres']) ?>
                </a>
            </div>
        </div>
        <div class="tv-side-item">
            <span class="tv-side-label">Crédito</span>
            <div class="tv-side-value">
                <a href="../creditos/ver?id=<?= (int) $tk['credito_id'] ?>">Crédito #<?= (int) $tk['credito_id'] ?></a>
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px"><?= e($tk['articulo']) ?></div>
            </div>
        </div>
        <?php if ($tk['cobrador_nombre']): ?>
        <div class="tv-side-item">
            <span class="tv-side-label">Cobrador del cliente</span>
            <div class="tv-side-value"><?= e($tk['cobrador_nombre'] . ' ' . $tk['cobrador_apellido']) ?></div>
        </div>
        <?php endif; ?>
        <div class="tv-side-item">
            <span class="tv-side-label">Creado por</span>
            <div class="tv-side-value"><?= e($tk['creado_por_nombre']) ?></div>
        </div>
        <div class="tv-side-item" style="border-top:1px solid var(--dark-border);padding-top:12px">
            <span class="tv-side-label">ID de caso</span>
            <div class="tv-side-value">#<?= str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT) ?></div>
        </div>
    </div>
</div>

<script>
(function () {
    const hilo = document.getElementById('tv-hilo');
    if (hilo) hilo.scrollTop = hilo.scrollHeight;
})();
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
