<?php
// ============================================================
// index.php — Punto de entrada del módulo
// Redirige al login si no hay sesión, o al dashboard/agenda
// ============================================================
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/sesion.php';
require_once __DIR__ . '/config/funciones.php';

if (!empty($_SESSION['user_id'])) {
    if (es_cobrador()) {
        header('Location: cobrador/agenda');
    } elseif (es_vendedor()) {
        header('Location: ventas/index');
    } else {
        header('Location: admin/dashboard');
    }
} else {
    header('Location: auth/login');
}
exit;
