<?php
// ============================================================
// views/layout.php — incluir al inicio de cada página interna
// Uso: require_once '../views/layout.php';
//      $page_title = 'Mi Página';
//      $page_current = 'menu_item_key';
// ============================================================
$user = usuario_actual();
$rol = $user['rol'];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= e($page_title ?? 'Imperio Comercial') ?> — Imperio Comercial
    </title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>
    <div class="app-wrapper">

        <!-- ── SIDEBAR ── -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <div class="logo-text">💼 Imperio Comercial</div>
                <div class="logo-sub">Gestión de Créditos</div>
            </div>

            <nav class="sidebar-nav">
                <?php if ($rol === 'admin' || $rol === 'supervisor'): ?>
                    <div class="nav-label">General</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'dashboard' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>admin/dashboard.php">
                        <i class="fa fa-chart-pie"></i> Dashboard
                    </a>
                <?php endif; ?>

                <?php if ($rol !== 'cobrador'): ?>
                    <div class="nav-label">Gestión</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'clientes' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>clientes/index.php">
                        <i class="fa fa-users"></i> Clientes
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'creditos' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>creditos/index.php">
                        <i class="fa fa-file-invoice-dollar"></i> Créditos
                    </a>
                <?php endif; ?>

                <div class="nav-label">Cobranzas</div>
                <a class="nav-item <?= ($page_current ?? '') === 'agenda' ? 'active' : '' ?>"
                    href="<?= BASE_URL ?>cobrador/agenda.php">
                    <i class="fa fa-calendar-check"></i> Agenda del Día
                </a>

                <?php if ($rol === 'admin' || $rol === 'supervisor'): ?>
                    <a class="nav-item <?= ($page_current ?? '') === 'rendiciones' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>admin/rendiciones.php">
                        <i class="fa fa-clipboard-check"></i> Rendiciones
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'liquidaciones' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>admin/liquidaciones.php">
                        <i class="fa fa-money-bill-wave"></i> Liquidaciones
                    </a>
                <?php endif; ?>

                <?php if ($rol === 'admin'): ?>
                    <div class="nav-label">Administración</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'vendedores' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>vendedores/index.php">
                        <i class="fa fa-user-tag"></i> Vendedores
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'usuarios' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>admin/usuarios.php">
                        <i class="fa fa-user-cog"></i> Usuarios
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'log' ? 'active' : '' ?>"
                        href="<?= BASE_URL ?>admin/log.php">
                        <i class="fa fa-history"></i> Actividad
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user-name">
                    <?= e($user['nombre'] . ' ' . $user['apellido']) ?>
                </div>
                <div class="sidebar-user-rol">
                    <?= e(strtoupper($rol)) ?>
                </div>
                <hr class="divider">
                <a href="<?= BASE_URL ?>auth/logout.php" class="btn-ic btn-ghost btn-sm w-100"
                    style="justify-content:center">
                    <i class="fa fa-sign-out-alt"></i> Salir
                </a>
            </div>
        </aside>

        <!-- ── SIDEBAR BACKDROP (mobile) ── -->
        <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleSidebar()"></div>

        <!-- ── TOPBAR ── -->
        <header class="topbar">
            <button class="btn-ic btn-ghost btn-icon" id="sidebar-toggle" onclick="toggleSidebar()" title="Menú">
                <i class="fa fa-bars"></i>
            </button>
            <span class="topbar-title">
                <?= e($page_title ?? '') ?>
            </span>
            <div class="topbar-actions">
                <?php if (!empty($topbar_actions))
                    echo $topbar_actions; ?>
                <div class="topbar-user">
                    <span class="topbar-avatar">
                        <?= strtoupper(substr($user['nombre'], 0, 1) . substr($user['apellido'], 0, 1)) ?>
                    </span>
                </div>
            </div>
        </header>

        <!-- ── MAIN ── -->
        <main class="main-content">