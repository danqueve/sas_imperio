<?php
// ============================================================
// auth/acceso_restringido.php — Página de acceso fuera de horario
// NO llama a verificar_sesion() para evitar loop de redirección.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';

// Sin sesión → login
if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'auth/login');
    exit;
}

// Si no es supervisor → su destino normal
if (($_SESSION['rol'] ?? '') !== 'supervisor') {
    header('Location: ' . BASE_URL . 'admin/dashboard');
    exit;
}

// Si ya tiene acceso (horario normal o extensión válida) → dashboard
$hora = (int) date('G');
$dentro = ($hora >= SUPERVISOR_HORA_INICIO && $hora < SUPERVISOR_HORA_FIN);

if (!$dentro) {
    try {
        $pdo  = obtener_conexion();
        $stmt = $pdo->prepare(
            "SELECT acceso_extendido_hasta FROM ic_usuarios WHERE id=? AND activo=1"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $ext = $stmt->fetchColumn();
        if ($ext && new DateTime($ext) > new DateTime()) {
            header('Location: ' . BASE_URL . 'admin/dashboard');
            exit;
        }
    } catch (Throwable $e) { /* mostrar la página igualmente */ }
} else {
    header('Location: ' . BASE_URL . 'admin/dashboard');
    exit;
}

// Obtener admins con teléfono para mostrar contactos
$admins = [];
try {
    $pdo    = $pdo ?? obtener_conexion();
    $admins = $pdo->query(
        "SELECT nombre, apellido, telefono FROM ic_usuarios
         WHERE rol = 'admin' AND activo = 1
         ORDER BY apellido, nombre"
    )->fetchAll();
} catch (Throwable $e) { /* sin contactos */ }

$usuario         = usuario_actual();
$hora_inicio_fmt = sprintf('%02d:00', SUPERVISOR_HORA_INICIO);
$hora_fin_fmt    = sprintf('%02d:00', SUPERVISOR_HORA_FIN);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Restringido — Imperio Comercial</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/style.css?v=4">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .ar-card {
            max-width: 460px;
            width: 100%;
            background: var(--dark-card);
            border: 1px solid var(--dark-border);
            border-radius: 16px;
            padding: 40px 36px 32px;
            box-shadow: 0 20px 60px rgba(0,0,0,.45);
            text-align: center;
        }
        .ar-icon {
            width: 68px; height: 68px;
            border-radius: 50%;
            background: rgba(245,158,11,.14);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 24px;
        }
        .ar-icon i { font-size: 1.7rem; color: #f59e0b; }
        .ar-card h2 { font-size: 1.35rem; font-weight: 700; margin-bottom: 10px; }
        .ar-card .ar-sub { color: var(--text-muted); font-size: .88rem; line-height: 1.55; margin-bottom: 28px; }
        .ar-card strong { color: var(--text-main); }

        .ar-time-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(99,102,241,.12);
            border: 1px solid rgba(99,102,241,.25);
            border-radius: 99px;
            padding: 5px 16px;
            font-size: .82rem;
            color: #818cf8;
            font-weight: 600;
            margin-bottom: 28px;
        }

        .ar-admins {
            background: rgba(0,0,0,.22);
            border: 1px solid var(--dark-border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: left;
        }
        .ar-admins-title {
            font-size: .7rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 14px;
        }
        .ar-admin-row {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 10px;
        }
        .ar-admin-row:last-child { margin-bottom: 0; }
        .ar-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .75rem;
            color: #fff; flex-shrink: 0;
        }
        .ar-admin-name { font-weight: 600; font-size: .88rem; }
        .ar-admin-wa {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .78rem; color: #25d366; text-decoration: none;
            margin-top: 2px;
        }
        .ar-admin-wa:hover { text-decoration: underline; }

        .ar-status {
            font-size: .78rem;
            color: var(--text-muted);
            margin-bottom: 20px;
            min-height: 1.2em;
        }
        .ar-logout {
            font-size: .82rem;
            color: var(--text-muted);
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .ar-logout:hover { color: var(--text-main); }
    </style>
</head>
<body>

<div class="ar-card">
    <div class="ar-icon"><i class="fa fa-clock"></i></div>

    <h2>Acceso fuera de horario</h2>
    <p class="ar-sub">
        Hola, <strong><?= e($usuario['nombre']) ?></strong>.
        El sistema está disponible para supervisores de
        <strong><?= $hora_inicio_fmt ?></strong> a <strong><?= $hora_fin_fmt ?></strong>.
        En este momento son las <strong><?= date('H:i') ?></strong>.
    </p>

    <div class="ar-time-badge">
        <i class="fa fa-calendar-check"></i>
        Horario habilitado: <?= $hora_inicio_fmt ?> – <?= $hora_fin_fmt ?>
    </div>

    <?php if ($admins): ?>
    <div class="ar-admins">
        <div class="ar-admins-title">
            <i class="fa fa-headset"></i> Solicitá acceso extendido a un administrador
        </div>
        <?php foreach ($admins as $adm): ?>
        <div class="ar-admin-row">
            <div class="ar-avatar">
                <?= strtoupper(substr($adm['nombre'], 0, 1) . substr($adm['apellido'], 0, 1)) ?>
            </div>
            <div>
                <div class="ar-admin-name"><?= e($adm['apellido'] . ', ' . $adm['nombre']) ?></div>
                <?php if ($adm['telefono']): ?>
                <a href="<?= e(whatsapp_url($adm['telefono'], 'Hola, soy ' . $usuario['nombre'] . ' ' . $usuario['apellido'] . ' y necesito acceso extendido al sistema.')) ?>"
                   target="_blank" rel="noopener" class="ar-admin-wa">
                    <i class="fa-brands fa-whatsapp"></i>
                    <?= e($adm['telefono']) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="ar-status" id="ar-status">
        Verificando acceso automáticamente…
    </div>

    <a href="<?= BASE_URL ?>auth/logout" class="ar-logout">
        <i class="fa fa-right-from-bracket"></i> Cerrar sesión
    </a>
</div>

<script>
(function poll() {
    setTimeout(function () {
        fetch('<?= BASE_URL ?>admin/api_acceso_supervisor', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var el = document.getElementById('ar-status');
                if (d.tiene_acceso) {
                    el.style.color = '#4ade80';
                    el.textContent = 'Acceso concedido. Redirigiendo…';
                    setTimeout(function () {
                        window.location.href = '<?= BASE_URL ?>admin/dashboard';
                    }, 1500);
                } else {
                    var t = new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
                    el.textContent = 'Última verificación: ' + t + '. Reintentando en 60 seg…';
                    poll();
                }
            })
            .catch(function () { poll(); });
    }, 60000);
})();
</script>

</body>
</html>
