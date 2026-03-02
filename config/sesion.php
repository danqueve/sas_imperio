<?php
// ============================================================
// Sistema Imperio Comercial — Gestión de Sesión y Roles
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('ROLES', ['admin', 'supervisor', 'cobrador']);

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
        header('Location: ' . BASE_URL . 'auth/login.php');
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

function usuario_actual(): array
{
    return [
        'id' => $_SESSION['user_id'] ?? 0,
        'nombre' => $_SESSION['nombre'] ?? '',
        'apellido' => $_SESSION['apellido'] ?? '',
        'rol' => $_SESSION['rol'] ?? '',
    ];
}
