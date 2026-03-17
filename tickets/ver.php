<?php
// tickets/ver.php — Ver ticket + hilo de respuestas
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

$pdo       = obtener_conexion();
$uid       = (int) $_SESSION['user_id'];
$rol       = $_SESSION['rol'];
$ticket_id = (int) ($_GET['id'] ?? 0);

if (!$ticket_id) {
    header('Location: index'); exit;
}

// ── Cargar ticket ────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT tk.*,
           CONCAT(uc.nombre, ' ', uc.apellido) AS creado_por_nombre, uc.rol AS creado_por_rol,
           CONCAT(ud.nombre, ' ', ud.apellido) AS delegado_nombre
    FROM ic_tickets tk
    JOIN ic_usuarios uc ON uc.id = tk.creado_por
    LEFT JOIN ic_usuarios ud ON ud.id = tk.delegado_a_usuario
    WHERE tk.id = ?
");
$stmt->execute([$ticket_id]);
$tk = $stmt->fetch();

if (!$tk) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Ticket no encontrado.'];
    header('Location: index'); exit;
}

// ── Cargar respuestas ────────────────────────────────────────
$resp_stmt = $pdo->prepare("
    SELECT tr.*, CONCAT(u.nombre, ' ', u.apellido) AS autor, u.rol AS autor_rol
    FROM ic_ticket_respuestas tr
    JOIN ic_usuarios u ON u.id = tr.usuario_id
    WHERE tr.ticket_id = ?
    ORDER BY tr.created_at ASC
");
$resp_stmt->execute([$ticket_id]);
$respuestas = $resp_stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$es_creador = ((int)$tk['creado_por'] === $uid);
$es_admin   = es_admin();
$puede_cerrar = $es_creador || $es_admin;

// Helpers de badge
function badge_est(string $e): string {
    return match($e) {
        'abierto'     => '<span class="badge-status open">Abierto</span>',
        'en_progreso' => '<span class="badge-status progress">En progreso</span>',
        'resuelto'    => '<span class="badge-status resolved">Resuelto</span>',
        default       => '<span class="badge bg-secondary">' . htmlspecialchars($e) . '</span>',
    };
}
function badge_prio(string $p): string {
    return match($p) {
        'alta'  => '<span class="badge-prio high"><i class="fa fa-angles-up me-1"></i>Alta</span>',
        'media' => '<span class="badge-prio medium"><i class="fa fa-minus me-1"></i>Media</span>',
        'baja'  => '<span class="badge-prio low"><i class="fa fa-angles-down me-1"></i>Baja</span>',
        default => '',
    };
}

$page_title   = 'Ticket #' . $ticket_id;
$page_current = 'tickets';
$topbar_actions = '<a href="index" class="btn-ic btn-sm" style="background:rgba(107,114,128,.2);color:#9ca3af;border:1px solid rgba(107,114,128,.3)"><i class="fa fa-arrow-left me-1"></i> Volver</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php
$is_light = ($rol === 'cobrador');
$card_bg  = $is_light ? '#ffffff' : 'rgba(15,23,42,.4)';
$card_bor = $is_light ? '#d1d5db' : 'rgba(255,255,255,.05)';
$text_main = $is_light ? '#1f2937' : '#f1f5f9';
$text_muted = $is_light ? '#64748b' : '#94a3b8';
$bubble_other_bg = $is_light ? '#f3f4f6' : 'rgba(30,41,59,.8)';
$header_bg = $is_light ? '#f9fafb' : 'rgba(30,41,59,.6)';
$sidebar_bg = $is_light ? '#ffffff' : 'rgba(30,41,59,.4)';
?>

<style>
/* Reutilizamos y refinamos los estilos de badges */
.badge-status {
    padding: 4px 10px; border-radius: 6px; font-size: .72rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .3px; display: inline-flex; align-items: center;
}
.badge-status.open     { background: rgba(239,68,68,<?= $is_light?'.1':'.15' ?>); color: <?= $is_light?'#dc2626':'#fca5a5' ?>; border: 1px solid rgba(239,68,68,.2); }
.badge-status.progress { background: rgba(245,158,11,<?= $is_light?'.1':'.15' ?>); color: <?= $is_light?'#d97706':'#fcd34d' ?>; border: 1px solid rgba(245,158,11,.2); }
.badge-status.resolved { background: rgba(34,197,94,<?= $is_light?'.1':'.15' ?>); color: <?= $is_light?'#16a34a':'#86efac' ?>; border: 1px solid rgba(34,197,94,.2); }

.badge-prio { font-size: .75rem; font-weight: 500; display: inline-flex; align-items: center; }
.badge-prio.high   { color: <?= $is_light?'#dc2626':'#fca5a5' ?>; }
.badge-prio.medium { color: <?= $is_light?'#4f46e5':'#a5b4fc' ?>; }
.badge-prio.low    { color: <?= $is_light?'#6b7280':'#9ca3af' ?>; }

.chat-container {
    background: <?= $card_bg ?>; border-radius: 16px; border: 1px solid <?= $card_bor ?>;
    display: flex; flex-direction: column; overflow: hidden;
}

.chat-header {
    background: <?= $header_bg ?>; padding: 16px 20px;
    border-bottom: 1px solid <?= $card_bor ?>;
}

.chat-body {
    padding: 24px; max-height: 500px; overflow-y: auto;
    background: <?= $is_light ? '#ffffff' : 'radial-gradient(circle at top right, rgba(99,102,241,0.03), transparent)' ?>;
}

.bubble-wrap { display:flex; margin-bottom:20px; gap:12px; max-width: 85%; }
.bubble-wrap.mine { flex-direction:row-reverse; margin-left: auto; }

.avatar {
    width:38px; height:38px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:.8rem; font-weight:700; letter-spacing:.5px;
    box-shadow: 0 4px 6px rgba(0,0,0,.1);
}

.bubble {
    padding:12px 16px; border-radius:14px;
    font-size:.9rem; line-height:1.55; white-space:pre-wrap; word-break:break-word;
    position: relative;
    box-shadow: 0 2px 4px rgba(0,0,0,<?= $is_light ? '.05' : '.1' ?>);
}
.bubble.mine {
    background: linear-gradient(135deg, #3c50e0 0%, #2c3ec0 100%); color: #fff;
    border-bottom-right-radius: 2px;
}
.bubble.other {
    background: <?= $bubble_other_bg ?>; color: <?= $is_light ? '#1f2937' : '#e5e7eb' ?>; border: 1px solid <?= $card_bor ?>;
    border-top-left-radius: 2px;
}

.bubble-meta { font-size:.68rem; color:<?= $text_muted ?>; margin-top:6px; display: flex; gap: 8px; }
.bubble-wrap.mine .bubble-meta { justify-content: flex-end; }

.desc-box {
    background: <?= $sidebar_bg ?>; border: 1px solid <?= $card_bor ?>;
    border-radius: 12px; padding: 20px;
}

.sidebar-card {
    background: <?= $sidebar_bg ?>; border: 1px solid <?= $card_bor ?>;
    border-radius: 12px; padding: 20px;
}

<?php if ($is_light): ?>
.chat-container textarea {
    background: #ffffff !important; border-color: #d1d5db !important;
    color: #000000 !important; font-weight: 500 !important;
}
.chat-container .bg-dark { background: #f9fafb !important; }
.desc-box h4 { color: #000000 !important; }
.sidebar-card .text-light { color: #000000 !important; }
.avatar[style*="rgba(255,255,255,.05)"] { background: #f3f4f6 !important; }
.bubble.other { color: #000000 !important; font-weight: 450 !important; }
.form-label-premium { color: #000000 !important; opacity: 0.8; }
<?php endif; ?>

/* Agrandar botón de envío y mejorar input group */
.chat-input-wrapper {
    background: <?= $header_bg ?>; padding: 20px;
    border-top: 1px solid <?= $card_bor ?>;
}
.btn-send-ticket {
    width: 60px; height: 60px; border-radius: 14px !important;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem !important; transition: all .2s;
    box-shadow: 0 4px 12px rgba(60,80,224,0.3);
}
.btn-send-ticket:hover { transform: scale(1.05); box-shadow: 0 6px 15px rgba(60,80,224,0.4); }
</style>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> mb-3"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Columna principal -->
    <div class="col-12 col-lg-8">
        <!-- Descripción original -->
        <div class="desc-box mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small fw-bold" style="letter-spacing:1px">ASUNTO DEL TICKET</span>
                <div class="d-flex gap-2">
                    <?= badge_est($tk['estado']) ?>
                    <?= badge_prio($tk['prioridad']) ?>
                </div>
            </div>
            <h4 class="mb-3 fw-bold text-light"><?= e($tk['titulo']) ?></h4>
            <div style="color:#cbd5e1; font-size:.95rem; white-space:pre-wrap; line-height:1.7">
                <?= e($tk['descripcion']) ?>
            </div>
            <div class="mt-4 pt-3 border-top border-secondary border-opacity-10 d-flex align-items-center gap-3" style="font-size:.75rem;color:#64748b">
                <div class="d-flex align-items-center gap-1">
                    <i class="fa fa-user-circle opacity-50"></i> <?= e($tk['creado_por_nombre']) ?>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <i class="fa fa-calendar-alt opacity-50"></i> <?= date('d/m/Y H:i', strtotime($tk['created_at'])) ?>
                </div>
            </div>
        </div>

        <!-- Hilo de respuestas -->
        <div class="chat-container mb-4">
            <div class="chat-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa fa-comments text-primary"></i>
                    <span class="fw-bold small text-light" style="letter-spacing:.5px">CONVERSACIÓN</span>
                </div>
                <span class="badge rounded-pill bg-dark text-muted" style="font-size:.65rem"><?= count($respuestas) ?> respuestas</span>
            </div>
            
            <div id="hilo-respuestas" class="chat-body">
                <?php if (empty($respuestas)): ?>
                    <div class="text-center py-4 opacity-30">
                        <i class="fa fa-comment-slash fa-2x mb-2"></i>
                        <p class="small mb-0">Sin respuestas todavía.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($respuestas as $r): ?>
                        <?php 
                            $es_mio = ((int)$r['usuario_id'] === $uid); 
                            $bg_avatar = $es_mio ? '#3c50e0' : 'rgba(255,255,255,.1)';
                            $tx_avatar = $es_mio ? '#fff' : '#cbd5e1';
                        ?>
                        <div class="bubble-wrap <?= $es_mio ? 'mine' : '' ?>">
                            <div class="avatar" style="background:<?= $bg_avatar ?>; color:<?= $tx_avatar ?>">
                                <?= mb_strtoupper(mb_substr($r['autor'], 0, 1) . mb_substr(explode(' ', $r['autor'])[1] ?? '', 0, 1)) ?>
                            </div>
                            <div class="bubble-body">
                                <div class="bubble <?= $es_mio ? 'mine' : 'other' ?>"><?= e($r['mensaje']) ?></div>
                                <div class="bubble-meta">
                                    <span class="fw-bold"><?= $es_mio ? 'Tú' : e($r['autor']) ?></span>
                                    <span><?= date('H:i', strtotime($r['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Formulario de respuesta integrado en el chat -->
            <!-- Formulario de respuesta con botón más grande -->
            <?php if ($tk['estado'] !== 'resuelto'): ?>
                <div class="chat-input-wrapper">
                    <form method="POST" action="procesar_respuesta">
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                        <div class="d-flex gap-3 align-items-center">
                            <div class="flex-grow-1">
                                <textarea name="mensaje" class="form-control input-premium"
                                          rows="2" placeholder="Escribí un mensaje aquí..." required
                                          style="resize: none; min-height: 60px; padding-top: 15px;"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-send-ticket" title="Enviar mensaje">
                                <i class="fa fa-paper-plane"></i>
                            </button>
                        </div>
                        <div class="d-flex gap-2 mt-3 flex-wrap justify-content-center">
                            <?php if ($puede_cerrar): ?>
                                <button type="submit" name="solo_estado" value="resuelto" class="btn-ic btn-sm"
                                        style="background:rgba(34,197,94,.1);color:#16a34a;border:1px solid rgba(34,197,94,.2); font-weight: 700;">
                                    <i class="fa fa-check-double me-1"></i> Resolver Ticket
                                </button>
                            <?php endif; ?>
                            <?php if ($tk['estado'] === 'abierto'): ?>
                                <button type="submit" name="solo_estado" value="en_progreso" class="btn-ic btn-sm"
                                        style="background:rgba(245,158,11,.1);color:#d97706;border:1px solid rgba(245,158,11,.2); font-weight: 700;">
                                    <i class="fa fa-spinner fa-spin-fast me-1"></i> Asignar "En Progreso"
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="p-4 text-center bg-dark bg-opacity-50 border-top">
                    <span class="text-success fw-bold">
                        <i class="fa fa-check-circle me-1"></i> TICKET RESUELTO Y CERRADO
                    </span>
                    <?php if ($puede_cerrar): ?>
                        <form method="POST" action="procesar_respuesta" class="d-inline ms-3">
                            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                            <button type="submit" name="solo_estado" value="abierto"
                                    class="btn-ic btn-sm" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color: #9ca3af">
                                <i class="fa fa-rotate-left me-1"></i>Reabrir
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar info -->
    <div class="col-12 col-lg-4">
        <div class="sidebar-card sticky-top" style="top: 80px">
            <h6 class="text-muted small fw-bold mb-4" style="letter-spacing:1.5px">PROPIEDADES</h6>
            
            <div class="d-flex flex-column gap-4">
                <div class="detail-item">
                    <label class="text-muted smaller d-block mb-1">Estado actual</label>
                    <?= badge_est($tk['estado']) ?>
                </div>

                <div class="detail-item">
                    <label class="text-muted smaller d-block mb-1">Prioridad asignada</label>
                    <?= badge_prio($tk['prioridad']) ?>
                </div>

                <div class="detail-item">
                    <label class="text-muted smaller d-block mb-1">Titular del ticket</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="avatar" style="width:28px; height:28px; background:rgba(255,255,255,.05); font-size:.6rem">
                            <?= mb_substr($tk['creado_por_nombre'], 0, 1) ?>
                        </div>
                        <span class="text-light small"><?= e($tk['creado_por_nombre']) ?></span>
                    </div>
                </div>

                <div class="detail-item">
                    <label class="text-muted smaller d-block mb-1">Encargado / Delegado</label>
                    <?php if ($tk['delegado_nombre']): ?>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar" style="width:28px; height:28px; background:rgba(99,102,241,.1); color:#a5b4fc; font-size:.6rem">
                                <?= mb_substr($tk['delegado_nombre'], 0, 1) ?>
                            </div>
                            <span class="text-light small"><?= e($tk['delegado_nombre']) ?></span>
                        </div>
                    <?php elseif ($tk['delegado_a_rol']): ?>
                        <div class="d-flex align-items-center gap-2 text-primary">
                            <i class="fa fa-users-gear" style="font-size:.8rem"></i>
                            <span class="small fw-bold"><?= ucfirst(e($tk['delegado_a_rol'])) ?></span>
                        </div>
                    <?php else: ?>
                        <span class="text-muted italic opacity-50 small">Sin asignar todavía</span>
                    <?php endif; ?>
                </div>

                <div class="pt-3 border-top border-secondary border-opacity-10">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted smaller">ID Ticket</span>
                        <span class="text-light small fw-bold">#<?= str_pad($tk['id'], 6, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted smaller">Versión</span>
                        <span class="text-muted smaller">v2.1 (Premium)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-scroll al final del hilo
(function(){
    const hilo = document.getElementById('hilo-respuestas');
    if (hilo) hilo.scrollTop = hilo.scrollHeight;
})();
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
