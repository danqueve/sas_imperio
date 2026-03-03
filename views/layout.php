<?php
// ============================================================
// views/layout.php — incluir al inicio de cada página interna
// ============================================================
$user = usuario_actual();
$rol = $user['rol'];
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

                <?php if ($rol !== 'cobrador'): ?>
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
                <?php endif; ?>

                <div class="nav-label">Cobranzas</div>
                <a class="nav-item <?= ($page_current ?? '') === 'agenda' ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>cobrador/agenda"
                   data-tooltip="Agenda del Día">
                    <i class="fa fa-calendar-check"></i>
                    <span class="nav-text">Agenda del Día</span>
                </a>

                <?php if ($rol === 'admin' || $rol === 'supervisor'): ?>
                    <a class="nav-item <?= ($page_current ?? '') === 'rendiciones' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/rendiciones"
                       data-tooltip="Rendiciones">
                        <i class="fa fa-clipboard-check"></i>
                        <span class="nav-text">Rendiciones</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'liquidaciones' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/liquidaciones"
                       data-tooltip="Liquidaciones">
                        <i class="fa fa-money-bill-wave"></i>
                        <span class="nav-text">Liquidaciones</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol === 'admin'): ?>
                    <div class="nav-label">Administración</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'vendedores' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>vendedores/index"
                       data-tooltip="Vendedores">
                        <i class="fa fa-user-tag"></i>
                        <span class="nav-text">Vendedores</span>
                    </a>
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
