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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=6">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
</head>

<body<?= $rol === 'cobrador' ? ' class="theme-cobrador"' : '' ?>>
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
                <?php if ($rol === 'admin'): ?>
                    <div class="nav-label">General</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'dashboard' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/dashboard"
                       data-tooltip="Dashboard">
                        <i class="fa fa-chart-pie"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol !== 'cobrador' && $rol !== 'vendedor'): ?>
                    <div class="nav-label"><?= $rol === 'supervisor' ? 'General' : 'Gestión' ?></div>
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
                    <a class="nav-item <?= ($page_current ?? '') === 'atrasados' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/atrasados"
                       data-tooltip="Créditos Atrasados">
                        <i class="fa fa-hand-holding-dollar"></i>
                        <span class="nav-text">Atrasados</span>
                    </a>
                <?php endif; ?>
                <?php if ($rol === 'admin'): ?>
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
                    <?php if ($rol === 'admin'): ?>
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
                    <?php endif; ?>
                    <a class="nav-item <?= ($page_current ?? '') === 'vendedores_stats' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>vendedores/estadisticas"
                       data-tooltip="Estadísticas Ventas">
                        <i class="fa fa-chart-bar"></i>
                        <span class="nav-text">Estad. Ventas</span>
                    </a>
                <?php endif; ?>

                <?php if ($rol === 'vendedor'): ?>
                    <div class="nav-label">Mis Clientes</div>
                    <a class="nav-item <?= ($page_current ?? '') === 'mis_clientes' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>ventas/mis_clientes"
                       data-tooltip="Mis Clientes">
                        <i class="fa fa-users"></i>
                        <span class="nav-text">Mis Clientes</span>
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
                    <a class="nav-item <?= ($page_current ?? '') === 'migrar_cobrador' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>admin/migrar_cobrador"
                       data-tooltip="Migrar Cobrador">
                        <i class="fa fa-right-left"></i>
                        <span class="nav-text">Migrar Cobrador</span>
                    </a>
                <?php endif; ?>

                <!-- Soporte — visible para todos los roles excepto vendedor -->
                <?php if ($rol !== 'vendedor'): ?>
                <div class="nav-label">Soporte</div>
                <a class="nav-item <?= ($page_current ?? '') === 'tickets' ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>tickets/index"
                   data-tooltip="Tickets">
                    <i class="fa fa-ticket-simple"></i>
                    <span class="nav-text">Tickets</span>
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

        <?php
        $_sv_mins = supervisor_minutos_restantes();
        if ($_sv_mins !== null && $_sv_mins > 0 && $_sv_mins <= 30):
        $_sv_urgent = $_sv_mins <= 10;
        ?>
        <style>
        /* Banner de vencimiento — fijo debajo del topbar, a la derecha del sidebar */
        #sv-warn {
            position: fixed;
            top: var(--topbar-h);
            left: var(--sidebar-w);
            right: 0;
            z-index: 1020;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 9px 20px;
            font-size: .8rem;
            border-bottom: 2px solid;
            animation: sv-slidein .3s ease;
            transition: background .4s, border-color .4s, color .4s;
        }
        .sidebar.collapsed ~ * #sv-warn,
        .sidebar.collapsed ~ #sv-warn { left: var(--sidebar-collapsed-w); }
        @media (max-width: 768px) { #sv-warn { left: 0; } }

        #sv-warn.normal {
            background: rgba(245,158,11,.13);
            border-color: rgba(245,158,11,.35);
            color: #fbbf24;
        }
        #sv-warn.urgent {
            background: rgba(239,68,68,.14);
            border-color: rgba(239,68,68,.4);
            color: #f87171;
        }
        @keyframes sv-slidein {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        #sv-warn .sv-icon { font-size: 1rem; flex-shrink: 0; }
        #sv-warn.urgent .sv-icon { animation: sv-pulse 1.1s ease-in-out infinite; }
        @keyframes sv-pulse { 0%,100%{opacity:1} 50%{opacity:.35} }

        #sv-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 10px 2px 7px;
            border-radius: 20px;
            font-weight: 700;
            font-size: .78rem;
            letter-spacing: .03em;
            white-space: nowrap;
        }
        #sv-warn.normal #sv-badge { background: rgba(245,158,11,.22); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); }
        #sv-warn.urgent #sv-badge { background: rgba(239,68,68,.2);   color: #ef4444; border: 1px solid rgba(239,68,68,.35); }

        #sv-warn .sv-msg { flex: 1; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        #sv-warn .sv-msg strong { font-weight: 600; }

        #sv-close {
            flex-shrink: 0;
            background: none;
            border: none;
            cursor: pointer;
            opacity: .55;
            width: 24px; height: 24px;
            border-radius: 50%;
            font-size: 1rem;
            line-height: 24px;
            text-align: center;
            padding: 0;
            transition: opacity .2s, background .2s;
        }
        #sv-close:hover { opacity: 1; background: rgba(255,255,255,.12); }

        /* Empujar el main-content hacia abajo cuando el banner está visible */
        body.sv-active .main-content { padding-top: calc(var(--topbar-h) + 36px + 24px) !important; }
        </style>
        <div id="sv-warn" class="<?= $_sv_urgent ? 'urgent' : 'normal' ?>">
            <i class="fa <?= $_sv_urgent ? 'fa-triangle-exclamation' : 'fa-clock' ?> sv-icon"></i>
            <div class="sv-msg">
                Tu acceso vence en
                <span id="sv-badge">
                    <i class="fa fa-hourglass-half" style="font-size:.65rem"></i>
                    <span id="sv-mins"><?= $_sv_mins ?></span>&nbsp;min
                </span>
                &mdash; <strong>Solicitá una extensión a un administrador para continuar.</strong>
            </div>
            <button id="sv-close" title="Cerrar aviso">&times;</button>
        </div>
        <script>
        (function(){
            var m    = <?= (int)$_sv_mins ?>;
            var el   = document.getElementById('sv-mins');
            var wrap = document.getElementById('sv-warn');
            var icon = wrap ? wrap.querySelector('.sv-icon') : null;
            if (!el || !wrap) return;

            document.body.classList.add('sv-active');

            document.getElementById('sv-close').addEventListener('click', function(){
                wrap.remove();
                document.body.classList.remove('sv-active');
            });

            setInterval(function(){
                if (m > 0) { m--; el.textContent = m; }
                if (m <= 10) {
                    wrap.classList.replace('normal', 'urgent');
                    if (icon) { icon.classList.replace('fa-clock', 'fa-triangle-exclamation'); }
                }
                if (m <= 0) { window.location.href = '<?= BASE_URL ?>auth/acceso_restringido'; }
            }, 60000);
        })();
        </script>
        <?php endif; ?>

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
