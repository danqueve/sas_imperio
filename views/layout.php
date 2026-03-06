<?php
// ============================================================
// views/layout.php — incluir al inicio de cada página interna
// ============================================================
$user = usuario_actual();
$rol = $user['rol'];

// Solicitudes de baja pendientes (badge sidebar — solo admin)
$n_sol_baja = 0;
if ($rol === 'admin') {
    $_pdo_lay = obtener_conexion();
    $n_sol_baja = (int)$_pdo_lay->query("
        SELECT
          (SELECT COUNT(*) FROM ic_pagos_temporales  WHERE solicitud_baja = 1) +
          (SELECT COUNT(*) FROM ic_pagos_confirmados WHERE solicitud_baja = 1)
    ")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Imperio Comercial') ?> — Imperio Comercial</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/logo.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=4">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>
    <div class="app-wrapper">

        <!-- ── SIDEBAR ── -->
        <aside class="sidebar" id="sidebar">

            <div class="sidebar-brand">
                <div class="sidebar-brand-icon">
                    <img src="<?= BASE_URL ?>assets/img/logo.png" alt="Logo" style="width:26px;height:26px;object-fit:contain;border-radius:4px">
                </div>
                <div class="sidebar-brand-text">
                    <div class="logo-text">Imperio Comercial</div>
                    <div class="logo-sub">Gestión de Créditos</div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <?php if ($rol === 'admin' || $rol === 'supervisor'): ?>
                    <div class="nav-label">General</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'dashboard' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/dashboard"
                       data-tooltip="Dashboard">
                        <i class="fa fa-chart-pie"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol !== 'cobrador' && $rol !== 'vendedor'): ?>
                    <div class="nav-label">Gestión</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'clientes' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>clientes/index"
                       data-tooltip="Clientes">
                        <i class="fa fa-users"></i>
                        <span class="nav-text">Clientes</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'creditos' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>creditos/index"
                       data-tooltip="Créditos">
                        <i class="fa fa-file-invoice-dollar"></i>
                        <span class="nav-text">Créditos</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'articulos' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>articulos/index"
                       data-tooltip="Artículos / Stock">
                        <i class="fa fa-box-open"></i>
                        <span class="nav-text">Artículos / Stock</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol !== 'vendedor'): ?>
                <div class="nav-label">Cobranzas</div>
                <a class="nav-item <?= ($page_current ?? '') === 'agenda' ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>cobrador/agenda"
                   data-tooltip="Agenda del Día">
                    <i class="fa fa-calendar-check"></i>
                    <span class="nav-text">Agenda del Día</span>
                </a>
                <?php endif; ?>

                <?php if ($rol === 'admin' || $rol === 'supervisor'): ?>
                    <a class="nav-item <?= ($page_current ?? '') === 'rendiciones' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/rendiciones"
                       data-tooltip="Rendiciones">
                        <i class="fa fa-clipboard-check"></i>
                        <span class="nav-text">Rendiciones</span>
                        <?php if ($n_sol_baja > 0): ?>
                            <span class="nav-badge" title="<?= $n_sol_baja ?> solicitud<?= $n_sol_baja !== 1 ? 'es' : '' ?> de anulación pendiente<?= $n_sol_baja !== 1 ? 's' : '' ?>">
                                <?= $n_sol_baja ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'liquidaciones' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/liquidaciones"
                       data-tooltip="Liquidaciones">
                        <i class="fa fa-money-bill-wave"></i>
                        <span class="nav-text">Liquidaciones</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol === 'admin' || $rol === 'supervisor'): ?>
                    <div class="nav-label">Ventas</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'ventas' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>ventas/index"
                       data-tooltip="Ventas">
                        <i class="fa fa-receipt"></i>
                        <span class="nav-text">Ventas</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'vendedores' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>vendedores/index"
                       data-tooltip="Vendedores">
                        <i class="fa fa-user-tag"></i>
                        <span class="nav-text">Vendedores</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'vendedores_stats' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>vendedores/estadisticas"
                       data-tooltip="Estadísticas Ventas">
                        <i class="fa fa-chart-bar"></i>
                        <span class="nav-text">Estad. Ventas</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol === 'vendedor'): ?>
                    <div class="nav-label">Ventas</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'ventas_nueva' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>ventas/nueva"
                       data-tooltip="Nueva Venta">
                        <i class="fa fa-cart-plus"></i>
                        <span class="nav-text">Nueva Venta</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'ventas' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>ventas/index"
                       data-tooltip="Mis Ventas">
                        <i class="fa fa-receipt"></i>
                        <span class="nav-text">Mis Ventas</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol === 'admin'): ?>
                    <div class="nav-label">Administración</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'usuarios' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/usuarios"
                       data-tooltip="Usuarios">
                        <i class="fa fa-user-cog"></i>
                        <span class="nav-text">Usuarios</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'log' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/log"
                       data-tooltip="Actividad">
                        <i class="fa fa-history"></i>
                        <span class="nav-text">Actividad</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user-row">
                    <div class="sidebar-avatar">
                        <?= strtoupper(substr($user['nombre'], 0, 1) . substr($user['apellido'], 0, 1)) ?>
                    </div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">
                            <?= e($user['nombre'] . ' ' . $user['apellido']) ?>
                        </div>
                        <div class="sidebar-user-rol"><?= e(strtoupper($rol)) ?></div>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>auth/logout" class="sidebar-logout">
                    <i class="fa fa-sign-out-alt"></i>
                    <span class="logout-text">Salir</span>
                </a>
            </div>
        </aside>

        <!-- ── SIDEBAR BACKDROP (mobile) ── -->
        <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleSidebar()"></div>

        <!-- ── TOPBAR ── -->
        <header class="topbar">
            <button class="btn-ic btn-ghost btn-icon" id="sidebar-toggle"
                    onclick="toggleSidebar()" title="Menú">
                <i class="fa fa-bars"></i>
            </button>
            <span class="topbar-breadcrumb">
                <i class="fa fa-home" style="font-size:.72rem;opacity:.5"></i>
                <span class="topbar-breadcrumb-sep">/</span>
                <span><?= e($page_title ?? '') ?></span>
            </span>
            <div class="topbar-right">
                <div class="topbar-user">
                    <div class="topbar-avatar-wrap">
                        <span class="topbar-avatar">
                            <?= strtoupper(substr($user['nombre'], 0, 1) . substr($user['apellido'], 0, 1)) ?>
                        </span>
                        <div class="topbar-user-info">
                            <span class="topbar-user-name"><?= e($user['nombre'] . ' ' . $user['apellido']) ?></span>
                            <span class="topbar-user-rol"><?= e(strtoupper($rol)) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ── MAIN ── -->
        <main class="main-content">
            <?php if (!empty($page_title) || !empty($topbar_actions)): ?>
            <div class="page-header">
                <div class="page-header-left">
                    <h1 class="page-header-title"><?= e($page_title ?? '') ?></h1>
                </div>
                <?php if (!empty($topbar_actions)): ?>
                <div class="page-header-actions">
                    <?= $topbar_actions ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
