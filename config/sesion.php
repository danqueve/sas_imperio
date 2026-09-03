<?php
// ============================================================
// Sistema Imperio Comercial — Gestión de Sesión y Roles
// ============================================================

// Fijar explícitamente la zona horaria de la app, sin depender de la
// config `date.timezone` del php.ini de cada servidor (verificado:
// tanto WAMP local como, potencialmente, producción pueden tener UTC
// por default — eso corría las restricciones horarias y cualquier
// cálculo de fecha/mora 3 horas respecto a la hora real de Argentina).
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (session_status() === PHP_SESSION_NONE) {
    // Detecta HTTPS directo (Apache termina TLS) o detrás de un proxy
    // inverso que termina TLS y reenvía por HTTP (nginx, balanceador, etc.)
    $es_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $es_https,
    ]);
    // Endurecer ID de sesión
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

define('ROLES', ['admin', 'supervisor', 'cobrador', 'vendedor']);
define('SESSION_IDLE_TIMEOUT', 2 * 60 * 60); // 2 horas de inactividad

// ── Restricción horaria para supervisores y cobradores ────────
// date('G') usa el timezone configurado en php.ini (America/Argentina/Buenos_Aires)
define('SUPERVISOR_HORA_INICIO', 8);   // 08:00
define('SUPERVISOR_HORA_FIN',   19);   // 19:00
define('COBRADOR_HORA_INICIO',   8);   // 08:30 (hora + minuto)
define('COBRADOR_MINUTO_INICIO', 30);  // el corte final es medianoche, sin fin explícito

// ── Timeout por inactividad ──────────────────────────────────
if (!empty($_SESSION['user_id'])) {
    $now = time();
    $last = $_SESSION['last_activity'] ?? $now;
    if ($now - $last > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        // Forzar nueva sesión vacía con flash
        session_start();
        $_SESSION['flash_login'] = 'Tu sesión expiró por inactividad.';
        header('Location: ' . BASE_URL . 'auth/login');
        exit;
    }
    $_SESSION['last_activity'] = $now;
}

/**
 * Indica si, a la hora actual, el rol dado tiene acceso normal (sin
 * necesidad de extensión). Roles sin restricción devuelven siempre true.
 */
function dentro_horario_rol(string $rol): bool
{
    $hora = (int) date('G');
    $min  = (int) date('i');
    if ($rol === 'supervisor') {
        return ($hora >= SUPERVISOR_HORA_INICIO && $hora < SUPERVISOR_HORA_FIN);
    }
    if ($rol === 'cobrador') {
        return ($hora > COBRADOR_HORA_INICIO || ($hora === COBRADOR_HORA_INICIO && $min >= COBRADOR_MINUTO_INICIO));
    }
    return true;
}

/**
 * Verifica que el usuario tenga sesión activa.
 * Si no, redirige al login.
 */
function verificar_sesion(): void
{
    // Si no hay user_id o falta el rol (sesión incompleta/corrupta), limpiar y redirigir
    if (empty($_SESSION['user_id']) || !isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ROLES, true)) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . 'auth/login');
        exit;
    }

    // ── Usuario desactivado: invalidar sesión activa (todos los roles) ──
    try {
        $pdo_chk  = obtener_conexion();
        $chk_stmt = $pdo_chk->prepare("SELECT activo FROM ic_usuarios WHERE id=?");
        $chk_stmt->execute([$_SESSION['user_id']]);
        $activo = $chk_stmt->fetchColumn();
        if ($activo === false || (int) $activo === 0) {
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash_login'] = 'Tu usuario fue desactivado. Contactá a un administrador.';
            header('Location: ' . BASE_URL . 'auth/login');
            exit;
        }
    } catch (Throwable $e) {
        error_log('Chequeo activo de usuario: ' . $e->getMessage());
        // fail-open: no bloquear por error de DB
    }

    // ── Restricción horaria para supervisores y cobradores ────
    if (in_array($_SESSION['rol'], ['supervisor', 'cobrador'], true) && !dentro_horario_rol($_SESSION['rol'])) {
        $tiene_ext = false;
        try {
            $pdo  = obtener_conexion();
            $stmt = $pdo->prepare(
                "SELECT acceso_extendido_hasta FROM ic_usuarios WHERE id=? AND activo=1 LIMIT 1"
            );
            $stmt->execute([$_SESSION['user_id']]);
            $ext = $stmt->fetchColumn();
            $tiene_ext = ($ext && new DateTime($ext) > new DateTime());
        } catch (Throwable $e) {
            error_log('Horario de acceso check: ' . $e->getMessage());
            $tiene_ext = true; // fail-open: no bloquear por error de DB
        }
        if (!$tiene_ext) {
            header('Location: ' . BASE_URL . 'auth/acceso_restringido');
            exit;
        }
    }
}

/**
 * Verifica que el usuario tenga uno de los roles permitidos.
 * Si no, muestra error 403.
 */
function verificar_rol(string ...$roles): void
{
    verificar_sesion();
    if (!in_array($_SESSION['rol'], $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/../views/403.php';
        exit;
    }
}

/**
 * Verifica un permiso específico según el rol.
 */
$permisos = [
    'ver_clientes' => ['admin', 'supervisor', 'cobrador'],
    'editar_clientes' => ['admin', 'supervisor'],
    'eliminar_clientes' => ['admin'],
    'alta_creditos' => ['admin', 'supervisor'],
    'ver_agenda' => ['admin', 'supervisor', 'cobrador'],
    'registrar_pagos' => ['admin', 'supervisor', 'cobrador'],
    'aprobar_rendiciones' => ['admin', 'supervisor'],
    'gestionar_usuarios' => ['admin'],
    'ver_reportes' => ['admin', 'supervisor'],
    'ver_estadisticas' => ['admin', 'supervisor'],
    'registrar_ventas'       => ['admin', 'supervisor', 'vendedor'],
    'ver_ventas'             => ['admin', 'supervisor', 'vendedor'],
    'ver_clientes_vendedor'  => ['admin', 'supervisor', 'vendedor'],
];

function verificar_permiso(string $accion): void
{
    global $permisos;
    verificar_sesion(); // ya garantiza que $_SESSION['rol'] es válido
    $rol = $_SESSION['rol'];
    if (!isset($permisos[$accion]) || !in_array($rol, $permisos[$accion], true)) {
        http_response_code(403);
        echo '<div style="font-family:sans-serif;text-align:center;padding:60px">
                <h2>⛔ Acceso denegado</h2>
                <p>No tenés permiso para realizar esta acción.</p>
                <a href="' . BASE_URL . '">← Volver</a>
              </div>';
        exit;
    }
}

function es_admin(): bool
{
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
}

function es_supervisor(): bool
{
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'supervisor';
}

function es_cobrador(): bool
{
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'cobrador';
}

function es_vendedor(): bool
{
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'vendedor';
}

function usuario_actual(): array
{
    return [
        'id' => $_SESSION['user_id'] ?? 0,
        'nombre' => $_SESSION['nombre'] ?? '',
        'apellido' => $_SESSION['apellido'] ?? '',
        'rol' => $_SESSION['rol'] ?? '',
    ];
}

// ── CSRF ─────────────────────────────────────────────────────

/**
 * Devuelve el token CSRF de la sesión actual (lo crea si no existe).
 * Usar en formularios: <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Imprime un input hidden con el token CSRF. Conveniencia para formularios.
 */
function csrf_input(): void
{
    echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifica el token CSRF de un request POST. Si falla, aborta con 403.
 * Llamar al inicio de cada handler que muta estado.
 */
function verificar_csrf(): void
{
    $enviado = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $valido  = $_SESSION['_csrf'] ?? '';
    if (!$enviado || !$valido || !hash_equals($valido, $enviado)) {
        http_response_code(403);
        die('CSRF inválido. Recargá la página e intentalo de nuevo.');
    }
}

/**
 * Para supervisores y cobradores: minutos de acceso restantes (horario
 * normal o extensión). Devuelve null si no aplica (otro rol, o sin corte
 * próximo). Usado por layout.php para el banner de aviso.
 */
function minutos_restantes_acceso(): ?int
{
    $rol = $_SESSION['rol'] ?? '';
    if (!in_array($rol, ['supervisor', 'cobrador'], true)) return null;

    try {
        $pdo  = obtener_conexion();
        $stmt = $pdo->prepare("SELECT acceso_extendido_hasta FROM ic_usuarios WHERE id=? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $ext = $stmt->fetchColumn();
        if ($ext) {
            $fin   = new DateTime($ext);
            $ahora = new DateTime();
            if ($fin > $ahora) {
                return (int) floor(($fin->getTimestamp() - $ahora->getTimestamp()) / 60);
            }
        }
    } catch (Throwable $e) { /* silencioso */ }

    if (!dentro_horario_rol($rol)) return null;

    $hora     = (int) date('G');
    $min      = (int) date('i');
    $fin_hora = $rol === 'supervisor' ? SUPERVISOR_HORA_FIN : 24; // cobrador: hasta medianoche
    return ($fin_hora - $hora) * 60 - $min;
}
