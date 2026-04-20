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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=5">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <?php if ($rol === 'cobrador'): ?>
    <style>
        :root {
            /* Tema claro para cobradores / interfaz de campo */
            --body-bg: #f3f4f6;
            --dark-bg: #ffffff;
            --darker-bg: #e5e7eb;
            --dark-border: #d1d5db;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --secondary: #f9fafb;
            --secondary-hover: #f3f4f6;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --accent: #0ea5e9;
            --accent-hover: #0284c7;
            /* Se mantienen rojo, verde y amarillo con más brillo */
            --danger: #ef4444;    
            --success: #10b981;
            --warning: #f59e0b;
        }

        /* Ajustes finos para que el texto resalte sobre blanco */
        body {
            color: var(--text-main);
            background-color: var(--body-bg);
        }
        
        /* Ajuste de tarjetas y paneles */
        .card-ic, .modal-box {
            background: var(--dark-bg);
            border-color: var(--dark-border);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }
        
        .card-ic-header, .modal-header {
            border-bottom: 1px solid var(--dark-border);
            background: rgba(0,0,0,0.015);
        }
        
        .sidebar {
            background: #ffffff;
            border-right: 1px solid var(--dark-border);
        }
        
        .topbar {
            background: #ffffff;
            border-bottom: 1px solid var(--dark-border);
        }
        
        .nav-item { color: var(--text-main); }
        .nav-item:hover { background: var(--secondary-hover); }
        .nav-item.active { 
            background: rgba(79, 70, 229, 0.08); 
            color: var(--primary); 
            border-right: 3px solid var(--primary);
        }
        .nav-label { color: var(--text-muted); font-weight: 700; }
        
        /* Ajustar inputs para que luzcan bien en blanco */
        input, select, textarea {
            background: #ffffff !important;
            border: 1px solid var(--dark-border) !important;
            color: var(--text-main) !important;
        }
        input:focus, select:focus {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1) !important;
        }
        
        /* Ajustar textos que en oscuro forzaban blanco */
        .page-header-title { color: var(--text-main); }
        
        /* El modal info block necesita contraste en light mode */
        #modal-info { background: #f3f4f6 !important; border: 1px solid #e5e7eb; }
        
        /* Botones secundarios (ghost) */
        .btn-ghost { color: var(--text-main); }
        .btn-ghost:hover { background: var(--secondary-hover); }

        /* Ajustes específicos para agenda.php en tema claro */
        .agenda-row { border-bottom: 1px solid #e5e7eb; }
        .agenda-row:hover { background: #f9fafb; }
        .agenda-header { border-bottom: 2px solid #e5e7eb; color: var(--text-muted); }
        .agenda-articulo { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .kpi-card { background: #ffffff; border: 1px solid #e5e7eb; }
        .kpi-label { color: var(--text-muted); }
        .kpi-value { color: var(--text-main); }
    </style>
    <?php endif; ?>
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
                    <?php if ($rol === 'supervisor'): ?>
                    <a class="nav-item <?= ($page_current ?? '') === 'supervisor' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>supervisor/index"
                       data-tooltip="Panel Supervisor">
                        <i class="fa fa-user-shield"></i>
                        <span class="nav-text">Mi Panel</span>
                    </a>
                    <?php endif; ?>
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
                    <a class="nav-item <?= ($page_current ?? '') === 'estadisticas' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/estadisticas_cobranza"
                       data-tooltip="Estadísticas de Cobranza">
                        <i class="fa fa-chart-bar"></i>
                        <span class="nav-text">Estadísticas</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'ranking_cobradores' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/ranking_cobradores"
                       data-tooltip="Ranking de Cobradores">
                        <i class="fa fa-trophy"></i>
                        <span class="nav-text">Ranking Cob.</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'estadisticas_cob' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/estadisticas_cobrador"
                       data-tooltip="Estadísticas por Cobrador">
                        <i class="fa fa-chart-column"></i>
                        <span class="nav-text">Estad. Cobrador</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'atrasados' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/atrasados"
                       data-tooltip="Créditos Atrasados">
                        <i class="fa fa-hand-holding-dollar"></i>
                        <span class="nav-text">Atrasados</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'riesgo_cartera' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/riesgo_cartera"
                       data-tooltip="Riesgo de Cartera">
                        <i class="fa fa-shield-halved"></i>
                        <span class="nav-text">Riesgo Cartera</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'finalizados' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/finalizados"
                       data-tooltip="Créditos Finalizados">
                        <i class="fa fa-circle-check"></i>
                        <span class="nav-text">Finalizados</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'aging_report' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/aging_report"
                       data-tooltip="Antigüedad de Deuda">
                        <i class="fa fa-layer-group"></i>
                        <span class="nav-text">Antig. Deuda</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'cohortes' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/cohortes"
                       data-tooltip="Análisis de Cohortes">
                        <i class="fa fa-chart-gantt"></i>
                        <span class="nav-text">Cohortes</span>
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
                    <a class="nav-item <?= ($page_current ?? '') === 'metas' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/metas"
                       data-tooltip="Metas Semanales">
                        <i class="fa fa-bullseye"></i>
                        <span class="nav-text">Metas</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'log' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/log"
                       data-tooltip="Actividad">
                        <i class="fa fa-history"></i>
                        <span class="nav-text">Actividad</span>
                    </a>
                    <a class="nav-item <?= ($page_current ?? '') === 'interes_cero' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/interes_cero"
                       data-tooltip="Interés a Cero">
                        <i class="fa fa-percent"></i>
                        <span class="nav-text">Interés a Cero</span>
                    </a>
                <?php endif; ?>

                <!-- Soporte — visible para todos los roles -->
                <div class="nav-label">Soporte</div>
                <a class="nav-item <?= ($page_current ?? '') === 'tickets' ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>tickets/index"
                   data-tooltip="Tickets">
                    <i class="fa fa-ticket-simple"></i>
                    <span class="nav-text">Tickets</span>
                </a>

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
