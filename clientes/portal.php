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

$pdo   = obtener_conexion();
$stmt  = $pdo->prepare("SELECT id, nombres, apellidos, dni, telefono FROM ic_clientes WHERE token_acceso = ?");
$stmt->execute([$token]);
$c = $stmt->fetch();
if (!$c) { http_response_code(404); die('Página no encontrada.'); }

$session_key = 'portal_verified_' . $token;
$verificado  = !empty($_SESSION[$session_key]);
$error_dni   = '';

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

// Datos del portal (solo si verificado)
$creditos = [];
if ($verificado) {
    $cr_stmt = $pdo->prepare("
        SELECT cr.id, cr.fecha_alta, cr.monto_total, cr.monto_cuota, cr.cant_cuotas,
               cr.frecuencia, cr.estado,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
               (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas,
               (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id) AS total_cuotas,
               (SELECT MIN(fecha_vencimiento) FROM ic_cuotas
                  WHERE credito_id=cr.id AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA')) AS prox_venc,
               (SELECT monto_cuota FROM ic_cuotas
                  WHERE credito_id=cr.id AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA')
                  ORDER BY fecha_vencimiento ASC LIMIT 1) AS prox_monto
        FROM ic_creditos cr
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.cliente_id = ? AND cr.estado IN ('EN_CURSO','MOROSO')
        ORDER BY cr.fecha_alta DESC
    ");
    $cr_stmt->execute([$c['id']]);
    $creditos_raw = $cr_stmt->fetchAll();

    foreach ($creditos_raw as $cr) {
        // Cuotas
        $cu_stmt = $pdo->prepare("
            SELECT numero_cuota, fecha_vencimiento, monto_cuota, estado
            FROM ic_cuotas WHERE credito_id = ? ORDER BY numero_cuota ASC
        ");
        $cu_stmt->execute([$cr['id']]);

        // Últimos pagos
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
            'info'   => $cr,
            'cuotas' => $cu_stmt->fetchAll(),
            'pagos'  => $pg_stmt->fetchAll(),
        ];
    }
}

// Mapa de estados para mostrar al cliente
$estado_label = [
    'PAGADA'    => ['texto' => 'Pagada',          'color' => '#16a34a', 'icon' => '✅'],
    'PENDIENTE' => ['texto' => 'Pendiente',        'color' => '#ca8a04', 'icon' => '🟡'],
    'VENCIDA'   => ['texto' => 'Atrasada',         'color' => '#dc2626', 'icon' => '🔴'],
    'CAP_PAGADA'=> ['texto' => 'Capital pagado',   'color' => '#2563eb', 'icon' => '🔵'],
    'PARCIAL'   => ['texto' => 'Pago parcial',     'color' => '#ea580c', 'icon' => '🟠'],
];

$nombres_frec = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de tu Crédito — Imperio Comercial</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
        }
        .header {
            background: #1e293b;
            color: #fff;
            padding: 16px 20px;
            text-align: center;
        }
        .header .brand { font-size: 1.1rem; font-weight: 800; letter-spacing: .06em; }
        .header .sub   { font-size: .78rem; color: #94a3b8; margin-top: 2px; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px 16px 40px; }

        /* ── Formulario DNI ── */
        .verify-card {
            background: #fff;
            border-radius: 14px;
            padding: 32px 24px;
            margin-top: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            text-align: center;
        }
        .verify-card .lock-icon { font-size: 2.5rem; margin-bottom: 12px; }
        .verify-card h2 { font-size: 1.1rem; margin-bottom: 6px; }
        .verify-card p  { font-size: .85rem; color: #64748b; margin-bottom: 20px; }
        .input-group { display: flex; gap: 8px; }
        .input-dni {
            flex: 1;
            padding: 12px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 1rem;
            outline: none;
            transition: border-color .2s;
        }
        .input-dni:focus { border-color: #6366f1; }
        .btn-acceder {
            padding: 12px 20px;
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-acceder:hover { background: #4f46e5; }
        .error-msg {
            margin-top: 12px;
            padding: 10px 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            color: #dc2626;
            font-size: .85rem;
        }

        /* ── Saludo ── */
        .greeting { margin: 20px 0 4px; }
        .greeting h1 { font-size: 1.3rem; font-weight: 700; }
        .greeting p  { font-size: .85rem; color: #64748b; margin-top: 4px; }

        /* ── Card crédito ── */
        .credit-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px;
            margin-top: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
        }
        .credit-card .art-name {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .credit-card .credit-meta {
            font-size: .78rem;
            color: #64748b;
            margin-bottom: 14px;
        }

        /* Progress bar */
        .progress-wrap { margin-bottom: 14px; }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: .78rem;
            color: #64748b;
            margin-bottom: 5px;
        }
        .progress-bar-bg {
            background: #e2e8f0;
            border-radius: 99px;
            height: 8px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            transition: width .5s;
        }

        /* Próximo vencimiento */
        .prox-venc {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }
        .prox-venc .label { font-size: .75rem; color: #92400e; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .prox-venc .fecha { font-size: .95rem; font-weight: 700; color: #1e293b; }
        .prox-venc .monto { font-size: 1.05rem; font-weight: 800; color: #6366f1; }

        /* Tabla cuotas */
        .section-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            padding: 8px 0;
            border-top: 1px solid #f1f5f9;
            font-size: .82rem;
            font-weight: 600;
            color: #475569;
            user-select: none;
        }
        .section-toggle .chevron { transition: transform .2s; font-style: normal; }
        .section-content { display: none; padding-top: 8px; }
        .section-content.open { display: block; }
        table.cuotas-tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: .8rem;
        }
        table.cuotas-tbl th {
            text-align: left;
            padding: 6px 4px;
            color: #94a3b8;
            font-weight: 600;
            font-size: .72rem;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
        }
        table.cuotas-tbl td {
            padding: 7px 4px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle;
        }
        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: .72rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 99px;
            white-space: nowrap;
        }
        .text-right { text-align: right; }
        .fw-bold    { font-weight: 700; }

        /* Pagos */
        .pago-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: .82rem;
        }
        .pago-row:last-child { border-bottom: none; }
        .pago-fecha { color: #64748b; }
        .pago-cuota { color: #94a3b8; font-size: .75rem; }
        .pago-monto { font-weight: 700; color: #16a34a; }

        /* Sin créditos */
        .no-credits {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-size: .9rem;
        }
        .no-credits .icon { font-size: 2.5rem; margin-bottom: 10px; }

        /* Footer */
        footer {
            text-align: center;
            font-size: .75rem;
            color: #94a3b8;
            margin-top: 32px;
            padding-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="brand">IMPERIO COMERCIAL</div>
    <div class="sub">Portal del Cliente</div>
</div>

<div class="container">

<?php if (!$verificado): ?>
    <!-- ── Formulario verificación DNI ── -->
    <div class="verify-card">
        <div class="lock-icon">🔒</div>
        <h2>Verificá tu identidad</h2>
        <p>Para ver el estado de tu crédito ingresá tu número de DNI (solo números, sin puntos).</p>
        <form method="POST">
            <div class="input-group">
                <input type="tel" name="dni" class="input-dni"
                       placeholder="Ej: 35123456"
                       inputmode="numeric" pattern="[0-9]*"
                       required autofocus>
                <button type="submit" class="btn-acceder">Acceder</button>
            </div>
            <?php if ($error_dni): ?>
                <div class="error-msg"><?= e($error_dni) ?></div>
            <?php endif; ?>
        </form>
    </div>

<?php else: ?>
    <!-- ── Portal del cliente verificado ── -->
    <div class="greeting">
        <h1>Hola, <?= e($c['nombres']) ?> 👋</h1>
        <p>Acá podés ver el estado de tus créditos activos.</p>
    </div>

    <?php if (empty($creditos)): ?>
        <div class="no-credits">
            <div class="icon">📭</div>
            <p>No tenés créditos activos en este momento.</p>
        </div>
    <?php else: ?>
        <?php foreach ($creditos as $entry): ?>
            <?php
            $cr    = $entry['info'];
            $cuotas = $entry['cuotas'];
            $pagos  = $entry['pagos'];
            $pct = $cr['total_cuotas'] > 0 ? round($cr['cuotas_pagadas'] * 100 / $cr['total_cuotas']) : 0;
            $frec_label = $nombres_frec[$cr['frecuencia']] ?? ucfirst($cr['frecuencia']);
            ?>
            <div class="credit-card">
                <div class="art-name"><?= e($cr['articulo'] ?? 'Crédito') ?></div>
                <div class="credit-meta">
                    <?= $frec_label ?> · <?= $cr['cant_cuotas'] ?> cuotas de <?= formato_pesos($cr['monto_cuota']) ?> · Alta: <?= date('d/m/Y', strtotime($cr['fecha_alta'])) ?>
                </div>

                <!-- Progreso -->
                <div class="progress-wrap">
                    <div class="progress-label">
                        <span><?= $cr['cuotas_pagadas'] ?>/<?= $cr['total_cuotas'] ?> cuotas pagadas</span>
                        <span><?= $pct ?>%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>

                <!-- Próximo vencimiento -->
                <?php if ($cr['prox_venc']): ?>
                <div class="prox-venc">
                    <div>
                        <div class="label">Próximo vencimiento</div>
                        <div class="fecha"><?= date('d/m/Y', strtotime($cr['prox_venc'])) ?></div>
                    </div>
                    <div class="monto"><?= formato_pesos((float)$cr['prox_monto']) ?></div>
                </div>
                <?php endif; ?>

                <!-- Toggle: Cuotas -->
                <?php if (!empty($cuotas)): ?>
                <div class="section-toggle" onclick="toggleSection(this)">
                    <span>Ver detalle de cuotas (<?= count($cuotas) ?>)</span>
                    <i class="chevron">▼</i>
                </div>
                <div class="section-content">
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
                                <?php $est = $estado_label[$cu['estado']] ?? ['texto' => $cu['estado'], 'color' => '#64748b', 'icon' => '●']; ?>
                                <tr>
                                    <td class="fw-bold"><?= $cu['numero_cuota'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($cu['fecha_vencimiento'])) ?></td>
                                    <td class="text-right"><?= formato_pesos($cu['monto_cuota']) ?></td>
                                    <td class="text-right">
                                        <span class="estado-badge"
                                              style="background:<?= $est['color'] ?>22;color:<?= $est['color'] ?>">
                                            <?= $est['icon'] ?> <?= $est['texto'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Toggle: Pagos -->
                <?php if (!empty($pagos)): ?>
                <div class="section-toggle" onclick="toggleSection(this)" style="margin-top:4px">
                    <span>Últimos pagos (<?= count($pagos) ?>)</span>
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
    <?php endif; ?>

    <footer>
        Para consultas comunicate con tu cobrador.<br>
        <span style="font-size:.7rem">Imperio Comercial &copy; <?= date('Y') ?></span>
    </footer>

<?php endif; ?>

</div>

<script>
function toggleSection(header) {
    const content  = header.nextElementSibling;
    const chevron  = header.querySelector('.chevron');
    const isOpen   = content.classList.contains('open');
    content.classList.toggle('open', !isOpen);
    chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}
</script>

</body>
</html>
