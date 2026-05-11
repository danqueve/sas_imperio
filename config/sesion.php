<?php
// ============================================================
// Sistema Imperio Comercial — Gestión de Sesión y Roles
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    // Endurecer ID de sesión
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

define('ROLES', ['admin', 'supervisor', 'cobrador', 'vendedor']);
define('SESSION_IDLE_TIMEOUT', 2 * 60 * 60); // 2 horas de inactividad

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
    'registrar_ventas' => ['admin', 'supervisor', 'vendedor'],
    'ver_ventas'       => ['admin', 'supervisor', 'vendedor'],
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
