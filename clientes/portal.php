<?php
// ============================================================
// clientes/portal.php — Portal público del cliente
// Acceso sin login; protegido por token + verificación de DNI
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/funciones.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$token = trim($_GET['token'] ?? '');
if (!$token) { http_response_code(404); die('Página no encontrada.'); }

$pdo         = obtener_conexion();
$session_key = 'portal_verified_' . $token;

// T8: Cerrar sesión
if (isset($_GET['salir'])) {
    unset($_SESSION[$session_key]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?token=' . urlencode($token));
    exit;
}

// T1: Query cliente + datos del cobrador
$stmt = $pdo->prepare("
    SELECT c.id, c.nombres, c.apellidos, c.dni, c.telefono, c.dia_cobro,
           u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido,
           u.telefono AS cobrador_telefono
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.token_acceso = ?
");
$stmt->execute([$token]);
$c = $stmt->fetch();
if (!$c) { http_response_code(404); die('Página no encontrada.'); }

$verificado = !empty($_SESSION[$session_key]);
$error_dni  = '';

// POST: verificar DNI
if (!$verificado && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dni'])) {
    $dni_input = preg_replace('/\D/', '', trim($_POST['dni']));
    $dni_real  = preg_replace('/\D/', '', trim($c['dni'] ?? ''));
    if ($dni_input && $dni_real && $dni_input === $dni_real) {
        $_SESSION[$session_key] = true;
        $verificado = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?token=' . urlencode($token));
        exit;
    } else {
        $error_dni = 'DNI incorrecto. Verificá el número e intentá nuevamente.';
    }
}

// T1: Datos del portal enriquecidos (solo si verificado)
$creditos = [];
if ($verificado) {
    $cr_stmt = $pdo->prepare("
        SELECT cr.id, cr.fecha_alta, cr.monto_total, cr.monto_cuota, cr.cant_cuotas,
               cr.frecuencia, cr.estado,
               COALESCE(cr.articulo_desc, a.descripcion, 'Crédito') AS articulo,
               (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas,
               (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id) AS total_cuotas,
               (SELECT MIN(fecha_vencimiento) FROM ic_cuotas
                  WHERE credito_id=cr.id AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')) AS prox_venc,
               (SELECT monto_cuota FROM ic_cuotas
                  WHERE credito_id=cr.id AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
                  ORDER BY fecha_vencimiento ASC LIMIT 1) AS prox_monto,
               (SELECT COALESCE(SUM(monto_cuota), 0) FROM ic_cuotas
                  WHERE credito_id=cr.id AND estado IN ('PENDIENTE','VENCIDA','PARCIAL')) AS saldo_pendiente,
               (SELECT COALESCE(SUM(pc.monto_total), 0)
                  FROM ic_pagos_confirmados pc
                  JOIN ic_cuotas cu2 ON pc.cuota_id = cu2.id
                  WHERE cu2.credito_id = cr.id) AS total_pagado
        FROM ic_creditos cr
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.cliente_id = ? AND cr.estado IN ('EN_CURSO','MOROSO')
        ORDER BY cr.fecha_alta DESC
    ");
    $cr_stmt->execute([$c['id']]);
    $creditos_raw = $cr_stmt->fetchAll();

    foreach ($creditos_raw as $cr) {
        $cu_stmt = $pdo->prepare("
            SELECT numero_cuota, fecha_vencimiento, monto_cuota, estado
            FROM ic_cuotas WHERE credito_id = ? ORDER BY numero_cuota ASC
        ");
        $cu_stmt->execute([$cr['id']]);
        $cuotas = $cu_stmt->fetchAll();

        // Próxima cuota a pagar (para resaltar en tabla)
        $prox_cuota_num = null;
        foreach ($cuotas as $cu) {
            if (in_array($cu['estado'], ['PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL'])) {
                $prox_cuota_num = $cu['numero_cuota'];
                break;
            }
        }

        $pg_stmt = $pdo->prepare("
            SELECT pc.fecha_pago, pc.monto_total, cu.numero_cuota
            FROM ic_pagos_confirmados pc
            JOIN ic_cuotas cu ON pc.cuota_id = cu.id
            WHERE cu.credito_id = ?
            ORDER BY pc.fecha_pago DESC
            LIMIT 10
        ");
        $pg_stmt->execute([$cr['id']]);

        $creditos[] = [
            'info'           => $cr,
            'cuotas'         => $cuotas,
            'prox_cuota_num' => $prox_cuota_num,
            'pagos'          => $pg_stmt->fetchAll(),
        ];
    }
}

// T5: Helper countdown
function dias_venc(string $fecha): array {
    $hoy  = strtotime(date('Y-m-d'));
    $venc = strtotime($fecha);
    $dias = (int)(($venc - $hoy) / 86400);
    $n    = abs($dias);
    if ($dias < 0)   return ['texto' => 'Venció hace ' . $n . ' día' . ($n !== 1 ? 's' : ''), 'cls' => 'cd-late'];
    if ($dias === 0) return ['texto' => 'Vence hoy',         'cls' => 'cd-today'];
    if ($dias === 1) return ['texto' => 'Vence mañana',      'cls' => 'cd-soon'];
    if ($dias <= 7)  return ['texto' => "Faltan $dias días", 'cls' => 'cd-soon'];
    return                  ['texto' => "Faltan $dias días", 'cls' => 'cd-ok'];
}

$estado_label = [
    'PAGADA'     => ['texto' => 'Pagada',        'color' => '#16a34a', 'bg' => '#dcfce7'],
    'PENDIENTE'  => ['texto' => 'Pendiente',      'color' => '#b45309', 'bg' => '#fef3c7'],
    'VENCIDA'    => ['texto' => 'Atrasada',       'color' => '#dc2626', 'bg' => '#fee2e2'],
    'CAP_PAGADA' => ['texto' => 'Capital pagado', 'color' => '#2563eb', 'bg' => '#dbeafe'],
    'PARCIAL'    => ['texto' => 'Pago parcial',   'color' => '#ea580c', 'bg' => '#ffedd5'],
];

$nombres_frec = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];

$dias_cobro_nombres = [
    'lunes'     => 'Lunes',
    'martes'    => 'Martes',
    'miercoles' => 'Miércoles',
    'jueves'    => 'Jueves',
    'viernes'   => 'Viernes',
    'sabado'    => 'Sábado',
];

// ¿Tiene algún crédito moroso?
$tiene_moroso = false;
foreach ($creditos as $entry) {
    if ($entry['info']['estado'] === 'MOROSO') { $tiene_moroso = true; break; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Crédito — Imperio Comercial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --indigo:    #6366f1;
            --indigo-d:  #4f46e5;
            --indigo-bg: #eef2ff;
            --green:     #16a34a;
            --green-bg:  #dcfce7;
            --red:       #dc2626;
            --red-bg:    #fee2e2;
            --amber:     #b45309;
            --amber-bg:  #fef3c7;
            --slate-50:  #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-700: #334155;
            --slate-900: #0f172a;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.05);
            --shadow-md: 0 4px 16px rgba(0,0,0,.09), 0 1px 4px rgba(0,0,0,.06);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--slate-100);
            color: var(--slate-900);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── T2: Header con gradiente ── */
        .header {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            color: #fff;
            padding: 18px 20px 20px;
            position: relative;
        }
        .header-inner {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .header .brand    { font-size: 1rem; font-weight: 800; letter-spacing: .08em; }
        .header .sub      { font-size: .72rem; color: #a5b4fc; margin-top: 2px; }
        .btn-salir {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .75rem;
            font-weight: 600;
            color: #c7d2fe;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 99px;
            padding: 5px 12px;
            text-decoration: none;
            transition: background .2s;
            white-space: nowrap;
        }
        .btn-salir:hover { background: rgba(255,255,255,.18); color: #fff; }

        /* ── Container ── */
        .container { max-width: 600px; margin: 0 auto; padding: 20px 16px 48px; }

        /* ── T9: Animaciones ── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate { animation: fadeUp .35s ease both; }
        .animate-1 { animation-delay: .05s; }
        .animate-2 { animation-delay: .10s; }
        .animate-3 { animation-delay: .15s; }
        .animate-4 { animation-delay: .20s; }

        /* ── Formulario DNI ── */
        .verify-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 36px 24px 28px;
            margin-top: 28px;
            box-shadow: var(--shadow-md);
            text-align: center;
        }
        .verify-card .lock-icon {
            width: 56px; height: 56px;
            background: var(--indigo-bg);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            margin: 0 auto 16px;
        }
        .verify-card h2 { font-size: 1.15rem; font-weight: 700; margin-bottom: 6px; }
        .verify-card p  { font-size: .85rem; color: var(--slate-500); margin-bottom: 22px; line-height: 1.5; }
        .input-group    { display: flex; gap: 8px; }
        .input-dni {
            flex: 1;
            padding: 12px 14px;
            border: 1.5px solid var(--slate-200);
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-family: inherit;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: var(--slate-50);
        }
        .input-dni:focus { border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(99,102,241,.12); background: #fff; }
        .btn-acceder {
            padding: 12px 20px;
            background: var(--indigo);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-size: .95rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
            transition: background .2s, transform .1s;
        }
        .btn-acceder:hover  { background: var(--indigo-d); }
        .btn-acceder:active { transform: scale(.97); }
        .error-msg {
            margin-top: 12px;
            padding: 10px 14px;
            background: var(--red-bg);
            border: 1px solid #fecaca;
            border-radius: var(--radius-md);
            color: var(--red);
            font-size: .84rem;
        }

        /* ── Saludo + chip de estado global ── */
        .greeting { margin: 22px 0 0; }
        .greeting h1 { font-size: 1.35rem; font-weight: 800; }
        .greeting-sub { font-size: .83rem; color: var(--slate-500); margin-top: 4px; }
        .status-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; margin-bottom: 4px; }
        .chip {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .75rem; font-weight: 600;
            padding: 4px 11px; border-radius: 99px;
        }
        .chip-ok    { background: var(--green-bg); color: var(--green); }
        .chip-late  { background: var(--red-bg);   color: var(--red); }
        .chip-dot   { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        /* ── T4: Banner MOROSO ── */
        .banner-moroso {
            display: flex; align-items: flex-start; gap: 10px;
            background: #fffbeb;
            border: 1.5px solid #fde68a;
            border-left: 4px solid #f59e0b;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-bottom: 12px;
            font-size: .83rem;
        }
        .banner-moroso .bm-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }
        .banner-moroso strong   { display: block; color: #92400e; font-weight: 700; margin-bottom: 2px; }
        .banner-moroso p        { color: #a16207; margin: 0; line-height: 1.4; }

        /* ── Card crédito ── */
        .credit-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-top: 14px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
        }
        .credit-card.moroso { border-top: 3px solid #f59e0b; }
        .card-head    { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
        .art-name     { font-size: 1rem; font-weight: 700; }
        .estado-chip  { font-size: .68rem; font-weight: 700; padding: 3px 9px; border-radius: 99px; white-space: nowrap; flex-shrink: 0; }
        .estado-en-curso { background: #dbeafe; color: #1d4ed8; }
        .estado-moroso   { background: #fef3c7; color: #92400e; }
        .credit-meta  { font-size: .77rem; color: var(--slate-500); margin-bottom: 16px; }

        /* ── T3: Resumen financiero (3 métricas) ── */
        .metrics-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .metric-box {
            background: var(--slate-50);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 10px 8px;
            text-align: center;
        }
        .metric-label { font-size: .65rem; color: var(--slate-500); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
        .metric-value { font-size: .88rem; font-weight: 700; color: var(--slate-900); }
        .metric-value.green { color: var(--green); }
        .metric-value.indigo { color: var(--indigo); }

        /* ── Progress bar ── */
        .progress-wrap { margin-bottom: 14px; }
        .progress-label {
            display: flex; justify-content: space-between;
            font-size: .76rem; color: var(--slate-500); margin-bottom: 5px;
        }
        .progress-label strong { color: var(--slate-700); }
        .progress-bar-bg   { background: var(--slate-200); border-radius: 99px; height: 7px; overflow: hidden; }
        .progress-bar-fill {
            height: 100%; border-radius: 99px;
            background: linear-gradient(90deg, var(--indigo), #8b5cf6);
            transition: width .6s cubic-bezier(.4,0,.2,1);
        }

        /* ── T5: Próximo vencimiento + countdown ── */
        .prox-venc {
            display: flex; align-items: center; justify-content: space-between;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-bottom: 14px;
            border: 1px solid var(--slate-200);
            background: var(--slate-50);
            gap: 8px;
        }
        .prox-venc .pv-left {}
        .prox-venc .pv-label    { font-size: .68rem; color: var(--slate-500); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
        .prox-venc .pv-fecha    { font-size: .92rem; font-weight: 700; color: var(--slate-900); }
        .prox-venc .pv-monto    { font-size: 1.1rem; font-weight: 800; color: var(--indigo); flex-shrink: 0; }

        .countdown {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .72rem; font-weight: 600;
            padding: 3px 9px; border-radius: 99px; margin-top: 4px;
        }
        .cd-late  { background: var(--red-bg);   color: var(--red); }
        .cd-today { background: #fef3c7;          color: #b45309; }
        .cd-soon  { background: #ffedd5;          color: #c2410c; }
        .cd-ok    { background: var(--green-bg);  color: var(--green); }

        /* ── T6: Toggle secciones ── */
        .section-toggle {
            display: flex; align-items: center; justify-content: space-between;
            cursor: pointer;
            padding: 9px 0;
            border-top: 1px solid var(--slate-100);
            font-size: .8rem; font-weight: 600; color: var(--slate-700);
            user-select: none;
            gap: 8px;
        }
        .section-toggle:hover { color: var(--indigo); }
        .section-toggle .tog-left  { display: flex; align-items: center; gap: 6px; }
        .section-toggle .tog-count { font-size: .7rem; background: var(--slate-100); color: var(--slate-500); padding: 1px 7px; border-radius: 99px; font-weight: 500; }
        .chevron { transition: transform .22s; font-style: normal; font-size: .7rem; color: var(--slate-400); }
        .section-content         { display: none; padding-top: 8px; }
        .section-content.open    { display: block; }

        /* ── Tabla cuotas ── */
        table.cuotas-tbl { width: 100%; border-collapse: collapse; font-size: .79rem; }
        table.cuotas-tbl th {
            text-align: left; padding: 6px 6px;
            color: var(--slate-400); font-weight: 600; font-size: .68rem;
            text-transform: uppercase; border-bottom: 1px solid var(--slate-100);
        }
        table.cuotas-tbl td { padding: 7px 6px; border-bottom: 1px solid var(--slate-50); vertical-align: middle; }
        table.cuotas-tbl tr:last-child td { border-bottom: none; }

        /* T6: Resaltar próxima cuota */
        table.cuotas-tbl tr.prox-row td { background: #eef2ff; }
        table.cuotas-tbl tr.prox-row td:first-child { border-radius: 6px 0 0 6px; }
        table.cuotas-tbl tr.prox-row td:last-child  { border-radius: 0 6px 6px 0; }

        /* T6: Tachado para pagadas */
        table.cuotas-tbl tr.pagada-row td:not(:last-child) { color: var(--slate-400); text-decoration: line-through; text-decoration-color: var(--slate-300); }

        .estado-badge {
            display: inline-flex; align-items: center;
            font-size: .69rem; font-weight: 600;
            padding: 2px 8px; border-radius: 99px;
            white-space: nowrap;
        }
        .text-right { text-align: right; }
        .fw-bold    { font-weight: 700; }

        /* ── Pagos ── */
        .pago-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--slate-50);
            font-size: .81rem;
        }
        .pago-row:last-child { border-bottom: none; }
        .pago-fecha { color: var(--slate-700); font-weight: 500; }
        .pago-cuota { color: var(--slate-400); font-size: .72rem; margin-top: 2px; }
        .pago-monto { font-weight: 700; color: var(--green); }

        /* ── T7: Contacto cobrador ── */
        .cobrador-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-top: 14px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--slate-200);
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .cobrador-info {}
        .cobrador-label { font-size: .68rem; color: var(--slate-400); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
        .cobrador-name  { font-size: .92rem; font-weight: 700; color: var(--slate-900); }
        .cobrador-dia   { font-size: .75rem; color: var(--slate-500); margin-top: 2px; }
        .btn-wa {
            display: inline-flex; align-items: center; gap: 6px;
            background: #25d366; color: #fff;
            padding: 9px 16px; border-radius: 99px;
            font-size: .82rem; font-weight: 700;
            text-decoration: none; white-space: nowrap;
            transition: background .2s, transform .1s;
            flex-shrink: 0;
        }
        .btn-wa:hover  { background: #128c7e; }
        .btn-wa:active { transform: scale(.97); }
        .btn-wa svg    { width: 16px; height: 16px; fill: #fff; }

        /* ── Sin créditos ── */
        .no-credits {
            text-align: center; padding: 48px 20px; color: var(--slate-400); font-size: .9rem;
        }
        .no-credits .icon { font-size: 2.8rem; margin-bottom: 12px; }

        /* ── Footer ── */
        footer {
            text-align: center; font-size: .73rem; color: var(--slate-400);
            margin-top: 32px; padding-bottom: 16px; line-height: 1.6;
        }

        /* ── Mobile ── */
        @media (max-width: 420px) {
            .metrics-row { gap: 6px; }
            .metric-box  { padding: 8px 4px; }
            .metric-label { font-size: .62rem; }
            .metric-value { font-size: .82rem; }
            .cobrador-card { flex-direction: column; align-items: flex-start; }
            .btn-wa { width: 100%; justify-content: center; }
            .prox-venc { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<!-- T2: Header con gradiente -->
<div class="header">
    <div class="header-inner">
        <div>
            <div class="brand">IMPERIO COMERCIAL</div>
            <div class="sub">Portal del Cliente</div>
        </div>
        <?php if ($verificado): ?>
        <a href="?token=<?= urlencode($token) ?>&salir=1" class="btn-salir">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Salir
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="container">

<?php if (!$verificado): ?>
    <!-- ── Formulario verificación DNI ── -->
    <div class="verify-card animate animate-1">
        <div class="lock-icon">🔒</div>
        <h2>Verificá tu identidad</h2>
        <p>Para ver el estado de tu crédito, ingresá tu número de DNI sin puntos ni espacios.</p>
        <form method="POST">
            <div class="input-group">
                <input type="tel" name="dni" class="input-dni"
                       placeholder="Ej: 35123456"
                       inputmode="numeric" pattern="[0-9]*"
                       required autofocus>
                <button type="submit" class="btn-acceder">Acceder</button>
            </div>
            <?php if ($error_dni): ?>
                <div class="error-msg">⚠ <?= e($error_dni) ?></div>
            <?php endif; ?>
        </form>
    </div>

<?php else: ?>
    <!-- ── Portal del cliente verificado ── -->

    <!-- T2: Saludo + chip de estado global -->
    <div class="greeting animate animate-1">
        <h1>Hola, <?= e($c['nombres']) ?> 👋</h1>
        <div class="greeting-sub">Acá podés ver el estado de tus créditos activos.</div>
        <?php if (!empty($creditos)): ?>
        <div class="status-row">
            <?php if ($tiene_moroso): ?>
                <span class="chip chip-late"><span class="chip-dot"></span> Tenés cuotas atrasadas</span>
            <?php else: ?>
                <span class="chip chip-ok"><span class="chip-dot"></span> Al día con tus pagos</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($creditos)): ?>
        <div class="no-credits animate animate-2">
            <div class="icon">📭</div>
            <p>No tenés créditos activos en este momento.</p>
        </div>
    <?php else: ?>

        <?php foreach ($creditos as $idx => $entry): ?>
            <?php
            $cr             = $entry['info'];
            $cuotas         = $entry['cuotas'];
            $prox_cuota_num = $entry['prox_cuota_num'];
            $pagos          = $entry['pagos'];
            $es_moroso      = $cr['estado'] === 'MOROSO';
            $pct            = $cr['total_cuotas'] > 0
                                ? round($cr['cuotas_pagadas'] * 100 / $cr['total_cuotas'])
                                : 0;
            $frec_label     = $nombres_frec[$cr['frecuencia']] ?? ucfirst($cr['frecuencia']);
            $abrir_cuotas   = count($cuotas) <= 12;
            $delay_class    = 'animate animate-' . min($idx + 2, 4);
            ?>

            <!-- T4: Banner MOROSO -->
            <?php if ($es_moroso): ?>
            <div class="banner-moroso <?= $delay_class ?>">
                <div class="bm-icon">⚠️</div>
                <div>
                    <strong>Este crédito tiene cuotas atrasadas</strong>
                    <p>Por favor, comunicáte con tu cobrador para regularizar la situación.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Card crédito -->
            <div class="credit-card <?= $es_moroso ? 'moroso' : '' ?> <?= $delay_class ?>">

                <!-- Cabecera: artículo + chip estado -->
                <div class="card-head">
                    <div class="art-name"><?= e($cr['articulo']) ?></div>
                    <span class="estado-chip <?= $es_moroso ? 'estado-moroso' : 'estado-en-curso' ?>">
                        <?= $es_moroso ? '⚠ Con atraso' : '✓ En curso' ?>
                    </span>
                </div>
                <div class="credit-meta">
                    <?= $frec_label ?> &middot;
                    <?= $cr['cant_cuotas'] ?> cuotas de <?= formato_pesos($cr['monto_cuota']) ?> &middot;
                    Desde <?= date('d/m/Y', strtotime($cr['fecha_alta'])) ?>
                </div>

                <!-- T3: Resumen financiero -->
                <div class="metrics-row">
                    <div class="metric-box">
                        <div class="metric-label">Crédito total</div>
                        <div class="metric-value"><?= formato_pesos($cr['monto_total']) ?></div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-label">Ya pagaste</div>
                        <div class="metric-value green"><?= formato_pesos((float)$cr['total_pagado']) ?></div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-label">Saldo pendiente</div>
                        <div class="metric-value indigo"><?= formato_pesos((float)$cr['saldo_pendiente']) ?></div>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="progress-wrap">
                    <div class="progress-label">
                        <span><?= $cr['cuotas_pagadas'] ?> de <?= $cr['total_cuotas'] ?> cuotas pagadas</span>
                        <strong><?= $pct ?>%</strong>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>

                <!-- T5: Próximo vencimiento + countdown -->
                <?php if ($cr['prox_venc']): ?>
                    <?php $cd = dias_venc($cr['prox_venc']); ?>
                    <div class="prox-venc">
                        <div class="pv-left">
                            <div class="pv-label">Próximo vencimiento</div>
                            <div class="pv-fecha"><?= date('d/m/Y', strtotime($cr['prox_venc'])) ?></div>
                            <span class="countdown <?= $cd['cls'] ?>">
                                <?= e($cd['texto']) ?>
                            </span>
                        </div>
                        <div class="pv-monto"><?= formato_pesos((float)$cr['prox_monto']) ?></div>
                    </div>
                <?php endif; ?>

                <!-- T6: Toggle cuotas -->
                <?php if (!empty($cuotas)): ?>
                <div class="section-toggle" onclick="toggleSection(this)">
                    <div class="tog-left">
                        <span>Detalle de cuotas</span>
                        <span class="tog-count"><?= count($cuotas) ?></span>
                    </div>
                    <i class="chevron"><?= $abrir_cuotas ? '▲' : '▼' ?></i>
                </div>
                <div class="section-content <?= $abrir_cuotas ? 'open' : '' ?>">
                    <table class="cuotas-tbl">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vencimiento</th>
                                <th class="text-right">Monto</th>
                                <th class="text-right">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cuotas as $cu): ?>
                                <?php
                                $est       = $estado_label[$cu['estado']] ?? ['texto' => $cu['estado'], 'color' => '#64748b', 'bg' => '#f1f5f9'];
                                $es_prox   = $cu['numero_cuota'] === $prox_cuota_num;
                                $es_pagada = $cu['estado'] === 'PAGADA';
                                $row_cls   = $es_prox ? 'prox-row' : ($es_pagada ? 'pagada-row' : '');
                                ?>
                                <tr class="<?= $row_cls ?>">
                                    <td class="fw-bold"><?= $cu['numero_cuota'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($cu['fecha_vencimiento'])) ?></td>
                                    <td class="text-right"><?= formato_pesos($cu['monto_cuota']) ?></td>
                                    <td class="text-right">
                                        <span class="estado-badge"
                                              style="background:<?= $est['bg'] ?>;color:<?= $est['color'] ?>">
                                            <?= e($est['texto']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Toggle pagos -->
                <?php if (!empty($pagos)): ?>
                <div class="section-toggle" onclick="toggleSection(this)" style="margin-top:2px">
                    <div class="tog-left">
                        <span>Historial de pagos</span>
                        <span class="tog-count"><?= count($pagos) ?></span>
                    </div>
                    <i class="chevron">▼</i>
                </div>
                <div class="section-content">
                    <?php foreach ($pagos as $pg): ?>
                        <div class="pago-row">
                            <div>
                                <div class="pago-fecha"><?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></div>
                                <div class="pago-cuota">Cuota #<?= $pg['numero_cuota'] ?></div>
                            </div>
                            <div class="pago-monto"><?= formato_pesos($pg['monto_total']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>

        <!-- T7: Contacto del cobrador -->
        <?php
        $cobrador_nombre = trim(($c['cobrador_nombre'] ?? '') . ' ' . ($c['cobrador_apellido'] ?? ''));
        $cobrador_tel    = $c['cobrador_telefono'] ?? '';
        $dia_cobro       = $dias_cobro_nombres[$c['dia_cobro'] ?? ''] ?? null;
        if ($cobrador_nombre):
        ?>
        <div class="cobrador-card animate animate-4">
            <div class="cobrador-info">
                <div class="cobrador-label">Tu cobrador</div>
                <div class="cobrador-name"><?= e($cobrador_nombre) ?></div>
                <?php if ($dia_cobro): ?>
                    <div class="cobrador-dia">📅 Día de cobro: <?= e($dia_cobro) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($cobrador_tel): ?>
            <a href="<?= whatsapp_url($cobrador_tel, 'Hola ' . e($c['nombres']) . ', consulto por mi crédito.') ?>"
               target="_blank" rel="noopener" class="btn-wa">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20.52 3.48A11.93 11.93 0 0 0 12 0C5.37 0 0 5.37 0 12c0 2.11.55 4.16 1.6 5.97L0 24l6.22-1.57A11.94 11.94 0 0 0 12 24c6.63 0 12-5.37 12-12 0-3.2-1.25-6.21-3.48-8.52zM12 22c-1.85 0-3.66-.5-5.24-1.44l-.37-.22-3.69.93.98-3.6-.24-.38A9.95 9.95 0 0 1 2 12C2 6.48 6.48 2 12 2s10 4.48 10 10-4.48 10-10 10zm5.47-7.38c-.3-.15-1.77-.87-2.04-.97-.28-.1-.48-.15-.68.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.08-.3-.15-1.26-.46-2.4-1.47-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.68-1.63-.93-2.23-.24-.58-.49-.5-.68-.51-.17 0-.37-.02-.57-.02s-.52.08-.8.37c-.27.3-1.05 1.02-1.05 2.49 0 1.47 1.07 2.89 1.22 3.09.15.2 2.1 3.2 5.09 4.49.71.31 1.27.49 1.7.63.72.23 1.37.2 1.89.12.58-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35z"/></svg>
                WhatsApp
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <footer>
        Para consultas sobre tus créditos, comunicáte con tu cobrador.<br>
        <span style="font-size:.68rem">Imperio Comercial &copy; <?= date('Y') ?></span>
    </footer>

<?php endif; ?>

</div>

<script>
function toggleSection(header) {
    const content = header.nextElementSibling;
    const chevron = header.querySelector('.chevron');
    const isOpen  = content.classList.contains('open');
    content.classList.toggle('open', !isOpen);
    chevron.textContent = isOpen ? '▼' : '▲';
}
</script>

</body>
</html>
