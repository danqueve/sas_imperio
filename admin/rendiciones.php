<?php
// ============================================================
// admin/rendiciones.php — Aprobación de rendiciones (multi-jornada)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo         = obtener_conexion();
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion      = $_POST['accion'] ?? '';
    $cobrador_id = (int) ($_POST['cobrador_id'] ?? 0);

    if ($accion === 'aprobar_todo') {
        $fecha = $_POST['fecha'] ?? '';
        if (!$fecha) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Fecha no especificada.'];
        } else {
            $res = aprobar_rendicion($cobrador_id, $fecha, $_SESSION['user_id'], $pdo);
            if ($res['aprobados'] > 0) {
                registrar_log($pdo, $_SESSION['user_id'], 'RENDICION_APROBADA', 'cobrador', $cobrador_id,
                    'Aprobados: ' . $res['aprobados'] . ' pagos | Fecha: ' . $fecha);
            }
            $_SESSION['flash'] = [
                'type' => $res['errores'] === 0 ? 'success' : 'warning',
                'msg'  => "Jornada {$fecha} — Aprobados: {$res['aprobados']} — Errores: {$res['errores']}"
            ];
        }
    } elseif ($accion === 'aprobar_todas_jornadas') {
        $res = aprobar_todas_jornadas($cobrador_id, $_SESSION['user_id'], $pdo);
        if ($res['aprobados'] > 0) {
            registrar_log($pdo, $_SESSION['user_id'], 'RENDICION_APROBADA_TOTAL', 'cobrador', $cobrador_id,
                'Aprobados: ' . $res['aprobados'] . ' pagos | Jornadas: ' . implode(', ', $res['fechas']));
        }
        $_SESSION['flash'] = [
            'type' => $res['errores'] === 0 ? 'success' : 'warning',
            'msg'  => "{$res['jornadas_procesadas']} jornada(s) procesadas — Aprobados: {$res['aprobados']} — Errores: {$res['errores']}"
        ];
    } elseif ($accion === 'rechazar' && !empty($_POST['pago_id'])) {
        if (!es_admin()) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Solo los administradores pueden rechazar pagos.'];
        } else {
            $pago_id = (int) $_POST['pago_id'];
            $pdo->prepare("UPDATE ic_pagos_temporales SET estado='RECHAZADO' WHERE id=? AND cobrador_id=?")
                ->execute([$pago_id, $cobrador_id]);
            registrar_log($pdo, $_SESSION['user_id'], 'PAGO_RECHAZADO', 'pago_temporal', $pago_id);
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Pago rechazado.'];
        }
    } elseif ($accion === 'editar_pago' && !empty($_POST['pago_id'])) {
        $pago_id = (int) $_POST['pago_id'];
        $ef      = (float) ($_POST['monto_efectivo'] ?? 0);
        $tr      = (float) ($_POST['monto_transferencia'] ?? 0);
        $total   = $ef + $tr;
        if ($ef < 0 || $tr < 0 || $total <= 0) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Montos inválidos.'];
        } else {
            $pdo->prepare("UPDATE ic_pagos_temporales SET monto_efectivo=?, monto_transferencia=?, monto_total=? WHERE id=? AND cobrador_id=? AND estado='PENDIENTE'")
                ->execute([$ef, $tr, $total, $pago_id, $cobrador_id]);
            registrar_log($pdo, $_SESSION['user_id'], 'PAGO_EDITADO', 'pago_temporal', $pago_id,
                'Ef: ' . formato_pesos($ef) . ' | Tr: ' . formato_pesos($tr));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pago actualizado correctamente.'];
        }
    } elseif ($accion === 'solicitar_baja_temporal' && !empty($_POST['pago_id'])) {
        $pago_id = (int) $_POST['pago_id'];
        $motivo  = trim($_POST['motivo'] ?? '');
        if ($motivo) {
            $pdo->prepare("UPDATE ic_pagos_temporales SET solicitud_baja=1, motivo_baja=? WHERE id=?")
                ->execute([$motivo, $pago_id]);
            registrar_log($pdo, $_SESSION['user_id'], 'SOLICITUD_BAJA_TEMP', 'pago_temporal', $pago_id, $motivo);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Solicitud enviada al administrador.'];
        } else {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Ingresá el motivo de la solicitud.'];
        }
    }
    header('Location: rendiciones?cobrador_id=' . $cobrador_id);
    exit;
}

// ── Todos los cobradores con pagos PENDIENTES (cualquier fecha) ──
$cobradores_con_pend = $pdo->query("
    SELECT u.id, u.nombre, u.apellido,
           COUNT(pt.id) AS cant_pagos_total,
           SUM(pt.monto_total) AS total_general,
           COUNT(DISTINCT pt.fecha_jornada) AS cant_jornadas,
           GROUP_CONCAT(DISTINCT pt.fecha_jornada ORDER BY pt.fecha_jornada SEPARATOR ',') AS fechas_jornadas,
           SUM(CASE WHEN pt.solicitud_baja = 1 THEN 1 ELSE 0 END) AS cant_baja,
           SUM(pt.monto_mora_cobrada) AS total_mora
    FROM ic_pagos_temporales pt
    JOIN ic_usuarios u ON pt.cobrador_id = u.id
    WHERE pt.estado = 'PENDIENTE'
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY u.apellido ASC
");
$cobradores_pend = $cobradores_con_pend->fetchAll();

// ── Detalle del cobrador seleccionado (todas sus jornadas pendientes) ──
$detalle_pagos    = [];
$pagos_por_jornada = [];
$totales_por_jornada = [];
$total_efectivo_global      = 0.0;
$total_transferencia_global = 0.0;
$total_mora_global          = 0.0;
$total_general_global       = 0.0;
$nombre_cobrador = '';

if ($cobrador_id) {
    $dstmt = $pdo->prepare("
        SELECT pt.*, pt.solicitud_baja, pt.motivo_baja, pt.fecha_jornada,
               cl.nombres, cl.apellidos,
               cu.numero_cuota, cu.monto_cuota, cu.fecha_vencimiento,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu    ON pt.cuota_id   = cu.id
        JOIN ic_creditos cr  ON cu.credito_id = cr.id
        JOIN ic_clientes cl  ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE pt.cobrador_id = ? AND pt.estado = 'PENDIENTE'
        ORDER BY pt.fecha_jornada ASC, pt.solicitud_baja DESC, pt.fecha_registro ASC
    ");
    $dstmt->execute([$cobrador_id]);
    $detalle_pagos = $dstmt->fetchAll();

    // Agrupar por fecha_jornada y calcular totales
    foreach ($detalle_pagos as $p) {
        $pagos_por_jornada[$p['fecha_jornada']][] = $p;
    }
    foreach ($pagos_por_jornada as $fecha => $pagos) {
        $ef  = array_sum(array_column($pagos, 'monto_efectivo'));
        $tr  = array_sum(array_column($pagos, 'monto_transferencia'));
        $mo  = array_sum(array_column($pagos, 'monto_mora_cobrada'));
        $tot = array_sum(array_column($pagos, 'monto_total'));
        $totales_por_jornada[$fecha] = ['efectivo' => $ef, 'transferencia' => $tr, 'mora' => $mo, 'total' => $tot, 'cant' => count($pagos)];
        $total_efectivo_global      += $ef;
        $total_transferencia_global += $tr;
        $total_mora_global          += $mo;
        $total_general_global       += $tot;
    }

    // Nombre del cobrador
    foreach ($cobradores_pend as $c) {
        if ((int) $c['id'] === $cobrador_id) {
            $nombre_cobrador = $c['nombre'] . ' ' . $c['apellido'];
            break;
        }
    }
}

// ── Badge: cuotas CAP_PAGADA/PARCIAL del cobrador ──
$cant_condonar = 0;
if ($cobrador_id > 0) {
    $sc = $pdo->prepare("
        SELECT COUNT(*) FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cu.estado IN ('CAP_PAGADA','PARCIAL')
          AND cr.cobrador_id = ?
          AND cr.estado IN ('EN_CURSO','MOROSO')
    ");
    $sc->execute([$cobrador_id]);
    $cant_condonar = (int) $sc->fetchColumn();
}

$page_title     = 'Rendiciones';
$page_current   = 'rendiciones';
$topbar_actions = '<a href="historial_rendiciones" class="btn-ic btn-ghost btn-sm"><i class="fa fa-history"></i> Historial de Rendiciones</a>';
if ($cant_condonar > 0) {
    $topbar_actions .= '<a href="pendientes_condonar?cobrador_id=' . $cobrador_id . '" class="btn-ic btn-sm" style="background:#f59e0b;color:#fff;border:none">'
        . '<i class="fa fa-triangle-exclamation"></i> Pendientes (' . $cant_condonar . ')'
        . '</a>';
}

require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div style="margin-bottom:16px;color:var(--text-muted);font-size:.82rem">
    <i class="fa fa-info-circle"></i>
    Mostrando todos los pagos pendientes de aprobación. Seleccioná un cobrador para ver el detalle por jornada.
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start">

    <!-- LISTA DE COBRADORES -->
    <div class="card-ic">
        <div class="card-title mb-3"><i class="fa fa-users"></i> Cobradores con Pendientes</div>
        <?php if (empty($cobradores_pend)): ?>
            <p class="text-muted text-center" style="padding:20px">Sin rendiciones pendientes.</p>
        <?php else: ?>
            <?php foreach ($cobradores_pend as $cob):
                $fechas_arr = $cob['fechas_jornadas'] ? explode(',', $cob['fechas_jornadas']) : [];
            ?>
            <a href="?cobrador_id=<?= $cob['id'] ?>"
               style="display:block;padding:12px;border-radius:8px;margin-bottom:6px;transition:.2s;text-decoration:none;
               <?= $cobrador_id === (int) $cob['id'] ? 'background:rgba(79,70,229,.2);border-left:3px solid var(--primary)' : 'background:rgba(0,0,0,.2)' ?>">
                <div class="fw-bold" style="display:flex;align-items:center;justify-content:space-between">
                    <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                    <?php if ($cob['cant_baja'] > 0): ?>
                        <span style="font-size:.7rem;background:rgba(245,158,11,.25);color:var(--warning);padding:2px 6px;border-radius:10px">
                            <i class="fa fa-flag"></i> <?= (int) $cob['cant_baja'] ?>
                        </span>
                    <?php endif; ?>
                </div>
                <!-- Badges de jornada -->
                <?php if (!empty($fechas_arr)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">
                    <?php foreach ($fechas_arr as $f): ?>
                        <span style="font-size:.7rem;background:rgba(79,70,229,.18);color:var(--primary-light);padding:2px 7px;border-radius:10px;white-space:nowrap">
                            <?= label_jornada($f) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="text-muted" style="font-size:.78rem;margin-top:4px">
                    <?= (int) $cob['cant_pagos_total'] ?> pago<?= (int) $cob['cant_pagos_total'] !== 1 ? 's' : '' ?> —
                    <strong><?= formato_pesos($cob['total_general']) ?></strong>
                    <?php if ((float)$cob['total_mora'] > 0): ?>
                        <br><span style="color:var(--warning)"><i class="fa fa-exclamation-triangle"></i> Mora: <?= formato_pesos($cob['total_mora']) ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- DETALLE MULTI-JORNADA -->
    <div>
    <?php if ($cobrador_id && !empty($pagos_por_jornada)): ?>

        <div class="card-ic">
            <div class="card-ic-header">
                <div>
                    <span class="card-title"><i class="fa fa-list"></i> Detalle de Pagos</span>
                    <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                        <?= e($nombre_cobrador) ?> —
                        <?= count($pagos_por_jornada) ?> jornada<?= count($pagos_por_jornada) !== 1 ? 's' : '' ?> pendiente<?= count($pagos_por_jornada) !== 1 ? 's' : '' ?>
                    </div>
                </div>
                <div style="font-size:.82rem;color:var(--text-muted)">
                    Total global: <strong style="color:var(--accent)"><?= formato_pesos($total_general_global) ?></strong>
                </div>
            </div>

            <?php foreach ($pagos_por_jornada as $fecha_jornada => $pagos_jornada):
                $tot       = $totales_por_jornada[$fecha_jornada];
                $lbl_jornada = label_jornada($fecha_jornada);
                $es_domingo  = ((int) date('N', strtotime($fecha_jornada)) === 7);
            ?>

            <!-- Sub-header de jornada -->
            <div style="padding:10px 16px;background:<?= $es_domingo ? 'rgba(245,158,11,.10)' : 'rgba(79,70,229,.08)' ?>;border-left:3px solid <?= $es_domingo ? 'var(--warning)' : 'var(--primary)' ?>;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                <div>
                    <strong style="font-size:.92rem">
                        <i class="fa fa-calendar-day"></i>
                        <?= e($lbl_jornada) ?>
                        <?php if ($es_domingo): ?>
                            <span style="font-size:.72rem;background:rgba(245,158,11,.2);color:var(--warning);padding:2px 7px;border-radius:8px;margin-left:6px;font-weight:500">
                                cobros del sábado
                            </span>
                        <?php endif; ?>
                    </strong>
                    <span class="text-muted" style="font-size:.78rem;margin-left:10px">
                        <?= $tot['cant'] ?> pago<?= $tot['cant'] !== 1 ? 's' : '' ?> — <?= formato_pesos($tot['total']) ?>
                    </span>
                </div>
                <div style="display:flex;gap:8px;align-items:center" class="no-print">
                    <a href="rendicion_pdf.php?fecha=<?= urlencode($fecha_jornada) ?>&cobrador_id=<?= $cobrador_id ?>"
                       class="btn-ic btn-ghost" target="_blank" title="Exportar PDF detallado de esta jornada"
                       style="display:inline-flex;align-items:center;gap:7px;padding:8px 14px;font-size:.88rem;border:1.5px solid rgba(239,68,68,.4);color:#ef4444">
                        <i class="fa fa-file-pdf" style="font-size:1.1rem"></i>
                        <span style="font-weight:600">Exportar PDF</span>
                        <span style="font-size:.75rem;opacity:.75;border-left:1px solid rgba(239,68,68,.3);padding-left:7px">
                            <?= $tot['cant'] ?> pago<?= $tot['cant'] !== 1 ? 's' : '' ?> · <?= formato_pesos($tot['total']) ?>
                        </span>
                    </a>
                    <button type="button" class="btn-ic btn-success btn-sm"
                        onclick="abrirAprobarJornada('<?= e($fecha_jornada) ?>', <?= $tot['cant'] ?>, '<?= e($lbl_jornada) ?>', '<?= formato_pesos($tot['total']) ?>')">
                        <i class="fa fa-check"></i> Aprobar jornada
                    </button>
                </div>
            </div>

            <!-- Tabla de pagos de esta jornada -->
            <div style="overflow-x:auto">
                <table class="table-ic" style="margin-bottom:0">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Cuota</th>
                            <th>Artículo</th>
                            <th>Efectivo</th>
                            <th>Transf.</th>
                            <th>Mora</th>
                            <th>Total</th>
                            <th class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagos_jornada as $p): ?>
                            <tr <?= $p['solicitud_baja'] ? 'style="background:rgba(245,158,11,.08);border-left:3px solid var(--warning)"' : '' ?>>
                                <td class="fw-bold">
                                    <?= e($p['apellidos'] . ', ' . $p['nombres']) ?>
                                    <?php if ($p['es_cuota_pura']): ?>
                                        <span style="font-size:.68rem;background:rgba(245,158,11,.15);color:var(--warning);padding:1px 5px;border-radius:4px;margin-left:4px">solo capital</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['observaciones'])): ?>
                                        <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">
                                            <i class="fa fa-comment"></i> <?= e($p['observaciones']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($p['solicitud_baja']): ?>
                                        <div style="font-size:.72rem;color:var(--warning);margin-top:2px">
                                            <i class="fa fa-flag"></i> Solicitud: <?= e($p['motivo_baja']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    #<?= $p['numero_cuota'] ?> — <?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?>
                                    <div style="font-size:.7rem;color:var(--text-muted)">Reg: <?= date('d/m H:i', strtotime($p['fecha_registro'])) ?></div>
                                </td>
                                <td><?= e($p['articulo']) ?></td>
                                <td class="nowrap"><?= formato_pesos($p['monto_efectivo']) ?></td>
                                <td class="nowrap"><?= formato_pesos($p['monto_transferencia']) ?></td>
                                <td class="nowrap <?= $p['monto_mora_cobrada'] > 0 ? 'text-warning' : '' ?>">
                                    <?= formato_pesos($p['monto_mora_cobrada']) ?>
                                </td>
                                <td class="nowrap fw-bold"><?= formato_pesos($p['monto_total']) ?></td>
                                <td class="no-print" style="display:flex;gap:4px;align-items:center">
                                    <button onclick="abrirEditarPago(<?= $p['id'] ?>, <?= (float)$p['monto_efectivo'] ?>, <?= (float)$p['monto_transferencia'] ?>)"
                                        class="btn-ic btn-ghost btn-sm" title="Editar montos">
                                        <i class="fa fa-pencil"></i>
                                    </button>
                                    <?php if (es_admin()): ?>
                                        <button type="button"
                                            class="btn-ic btn-sm <?= $p['solicitud_baja'] ? 'btn-warning' : 'btn-danger' ?>"
                                            title="Rechazar pago"
                                            onclick="abrirRechazarPago(<?= $p['id'] ?>, '<?= e(addslashes($p['apellidos'] . ', ' . $p['nombres'])) ?>', '<?= formato_pesos($p['monto_total']) ?>')">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    <?php elseif (es_supervisor()): ?>
                                        <?php if (!$p['solicitud_baja']): ?>
                                            <button onclick="abrirSolBajaTemp(<?= $p['id'] ?>)"
                                                class="btn-ic btn-warning btn-sm" title="Solicitar baja">
                                                <i class="fa fa-flag"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-warning" style="font-size:.75rem" title="Solicitud enviada">
                                                <i class="fa fa-clock"></i>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:rgba(79,70,229,.10);font-weight:700">
                            <td colspan="3" style="text-align:right;padding-right:12px;font-size:.82rem">SUBTOTAL</td>
                            <td class="nowrap" style="color:var(--success)"><?= formato_pesos($tot['efectivo']) ?></td>
                            <td class="nowrap" style="color:var(--primary-light)"><?= formato_pesos($tot['transferencia']) ?></td>
                            <td class="nowrap" style="color:var(--warning)"><?= formato_pesos($tot['mora']) ?></td>
                            <td class="nowrap" style="color:var(--accent)"><?= formato_pesos($tot['total']) ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div style="height:1px;background:rgba(255,255,255,.06);margin:0 16px"></div>

            <?php endforeach; // end foreach jornada ?>

            <!-- Total global + Aprobar Todas -->
            <hr class="divider no-print">
            <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px" class="no-print">
                <div style="font-size:.88rem;color:var(--text-muted)">
                    Total global:
                    <strong style="color:var(--accent);font-size:1.05rem"><?= formato_pesos($total_general_global) ?></strong>
                    <span style="font-size:.78rem;margin-left:8px">
                        (Ef: <?= formato_pesos($total_efectivo_global) ?> | Tr: <?= formato_pesos($total_transferencia_global) ?> | Mora: <?= formato_pesos($total_mora_global) ?>)
                    </span>
                </div>
                <?php if (count($pagos_por_jornada) > 1): ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <a href="rendicion_pdf.php?cobrador_id=<?= $cobrador_id ?>"
                       class="btn-ic btn-ghost" target="_blank"
                       style="display:inline-flex;align-items:center;gap:7px;padding:8px 14px;font-size:.88rem;border:1.5px solid rgba(239,68,68,.4);color:#ef4444">
                        <i class="fa fa-file-pdf" style="font-size:1.1rem"></i>
                        <span style="font-weight:600">PDF Completo</span>
                        <span style="font-size:.75rem;opacity:.75;border-left:1px solid rgba(239,68,68,.3);padding-left:7px">
                            <?= count($pagos_por_jornada) ?> jornadas · <?= formato_pesos($total_general_global) ?>
                        </span>
                    </a>
                    <form method="POST">
                        <input type="hidden" name="accion" value="aprobar_todas_jornadas">
                        <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
                        <button type="submit" class="btn-ic btn-success"
                            data-confirm="¿Aprobar TODOS los <?= count($detalle_pagos) ?> pagos de <?= count($pagos_por_jornada) ?> jornadas?">
                            <i class="fa fa-check-double"></i>
                            Aprobar Todas (<?= count($pagos_por_jornada) ?> jornadas — <?= count($detalle_pagos) ?> pagos)
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($cobrador_id): ?>
        <div class="card-ic">
            <p class="text-muted text-center" style="padding:30px">Sin pagos pendientes para este cobrador.</p>
        </div>
    <?php else: ?>
        <div class="card-ic">
            <p class="text-muted text-center" style="padding:30px">
                <i class="fa fa-arrow-left"></i> Seleccioná un cobrador para ver el detalle.
            </p>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php if (es_supervisor() && !es_admin()): ?>
<!-- MODAL SOLICITAR BAJA DE PAGO TEMPORAL (supervisores) -->
<div class="modal-overlay" id="modal-sol-baja-temp">
    <div class="modal-box" style="max-width:440px;background:#fff;color:#1e293b">
        <div class="modal-header" style="background:#fffbeb;border-bottom:1px solid #fde68a">
            <div class="modal-title" style="color:#d97706"><i class="fa fa-flag"></i> Solicitar Baja de Pago</div>
            <button class="modal-close" style="color:#64748b" onclick="closeModal('modal-sol-baja-temp')">✕</button>
        </div>
        <p style="font-size:.875rem;color:#64748b;margin-bottom:14px;padding-top:4px">
            La solicitud será revisada por el administrador, quien decidirá si rechaza el pago.
        </p>
        <form method="POST" class="form-ic">
            <input type="hidden" name="accion" value="solicitar_baja_temporal">
            <input type="hidden" name="pago_id" id="sol_temp_id">
            <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
            <div class="form-group mb-4">
                <label style="color:#374151">Motivo de la solicitud *</label>
                <textarea name="motivo" rows="3" required
                    placeholder="Ej: Pago duplicado, error de importe, cliente equivocado..."
                    style="resize:vertical;background:#f8fafc;color:#1e293b;border-color:#cbd5e1"></textarea>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-warning w-100" style="justify-content:center">
                    <i class="fa fa-paper-plane"></i> Enviar Solicitud
                </button>
                <button type="button" onclick="closeModal('modal-sol-baja-temp')" class="btn-ic btn-ghost" style="color:#64748b;border-color:#cbd5e1">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL APROBAR JORNADA — con countdown -->
<div class="modal-overlay" id="modal-aprobar-jornada">
    <div class="modal-box" style="max-width:430px;background:#fff;color:#1e293b">
        <div class="modal-header" style="background:#f0fdf4;border-bottom:1px solid #bbf7d0">
            <div class="modal-title" style="color:#16a34a"><i class="fa fa-check-circle"></i> Aprobar Jornada</div>
            <button class="modal-close" style="color:#64748b" onclick="cancelarAprobarJornada()">✕</button>
        </div>
        <div style="text-align:center;padding:22px 16px 10px">
            <div style="font-size:2.8rem;color:#16a34a;margin-bottom:12px">
                <i class="fa fa-calendar-check"></i>
            </div>
            <p style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:4px" id="aprobar-jornada-label"></p>
            <p style="font-size:.88rem;color:#64748b;margin-bottom:4px" id="aprobar-jornada-cant"></p>
            <p style="font-size:.88rem;color:#64748b;margin-bottom:18px" id="aprobar-jornada-total"></p>
            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px;margin-bottom:20px">
                <p style="font-size:.84rem;color:#374151;margin-bottom:8px">
                    Se aprobarán <strong style="color:#16a34a">todos los pagos pendientes</strong> de esta jornada.<br>
                    Esta acción no se puede deshacer.
                </p>
                <div id="aprobar-jornada-countdown-wrap" style="font-size:.82rem;color:#d97706;margin-top:6px;font-weight:600">
                    <i class="fa fa-clock"></i> Podés confirmar en <span id="aprobar-jornada-countdown" style="font-weight:800;font-size:1rem">3</span> segundos...
                </div>
            </div>
        </div>
        <form method="POST" id="form-aprobar-jornada">
            <input type="hidden" name="accion" value="aprobar_todo">
            <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
            <input type="hidden" name="fecha" id="aprobar-jornada-fecha">
            <div class="d-flex gap-3">
                <button type="submit" id="aprobar-jornada-btn" class="btn-ic btn-success w-100"
                    style="justify-content:center;opacity:.45;pointer-events:none" disabled>
                    <i class="fa fa-check"></i> Confirmar Aprobación
                </button>
                <button type="button" onclick="cancelarAprobarJornada()" class="btn-ic btn-ghost" style="color:#64748b;border-color:#cbd5e1">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RECHAZAR PAGO (admin) — con countdown -->
<div class="modal-overlay" id="modal-rechazar-pago">
    <div class="modal-box" style="max-width:420px;background:#fff;color:#1e293b">
        <div class="modal-header" style="background:#fef2f2;border-bottom:1px solid #fecaca">
            <div class="modal-title" style="color:#dc2626"><i class="fa fa-exclamation-triangle"></i> Rechazar Pago</div>
            <button class="modal-close" style="color:#64748b" onclick="cancelarRechazarPago()">✕</button>
        </div>
        <div style="text-align:center;padding:20px 10px 10px">
            <div style="font-size:3rem;color:#ef4444;margin-bottom:12px">
                <i class="fa fa-ban"></i>
            </div>
            <p style="font-size:.95rem;font-weight:700;color:#1e293b;margin-bottom:6px" id="rechazar-cliente-label"></p>
            <p style="font-size:.88rem;color:#64748b;margin-bottom:18px" id="rechazar-monto-label"></p>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;margin-bottom:20px">
                <p style="font-size:.84rem;color:#374151;margin-bottom:8px">
                    Esta acción marcará el pago como <strong style="color:#dc2626">RECHAZADO</strong>.<br>
                    El cobrador deberá volver a registrarlo si corresponde.
                </p>
                <div id="rechazar-countdown-wrap" style="font-size:.82rem;color:#d97706;margin-top:6px;font-weight:600">
                    <i class="fa fa-clock"></i> Podés confirmar en <span id="rechazar-countdown" style="font-weight:800;font-size:1rem">3</span> segundos...
                </div>
            </div>
        </div>
        <form method="POST" id="form-rechazar-pago">
            <input type="hidden" name="accion" value="rechazar">
            <input type="hidden" name="pago_id" id="rechazar-pago-id">
            <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
            <div class="d-flex gap-3">
                <button type="submit" id="rechazar-confirmar-btn" class="btn-ic btn-danger w-100"
                    style="justify-content:center;opacity:.45;pointer-events:none" disabled>
                    <i class="fa fa-times"></i> Rechazar Pago
                </button>
                <button type="button" onclick="cancelarRechazarPago()" class="btn-ic btn-ghost" style="color:#64748b;border-color:#cbd5e1">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR PAGO (admin + supervisor) -->
<div class="modal-overlay" id="modal-editar-pago">
    <div class="modal-box" style="max-width:420px;background:#fff;color:#1e293b">
        <div class="modal-header" style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
            <div class="modal-title" style="color:#4f46e5"><i class="fa fa-pencil"></i> Editar Pago</div>
            <button class="modal-close" style="color:#64748b" onclick="closeModal('modal-editar-pago')">✕</button>
        </div>
        <p style="font-size:.875rem;color:#64748b;margin-bottom:14px;padding-top:4px">
            Corregí la distribución entre efectivo y transferencia. El total se recalcula automáticamente.
        </p>
        <form method="POST" class="form-ic">
            <input type="hidden" name="accion" value="editar_pago">
            <input type="hidden" name="pago_id" id="edit_pago_id">
            <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label style="color:#374151">Efectivo $</label>
                    <input type="number" name="monto_efectivo" id="edit_efectivo"
                        step="0.01" min="0" value="0" oninput="actualizarEditTotal()"
                        style="background:#f8fafc;color:#1e293b;border-color:#cbd5e1">
                </div>
                <div class="form-group">
                    <label style="color:#374151">Transferencia $</label>
                    <input type="number" name="monto_transferencia" id="edit_transfer"
                        step="0.01" min="0" value="0" oninput="actualizarEditTotal()"
                        style="background:#f8fafc;color:#1e293b;border-color:#cbd5e1">
                </div>
            </div>
            <div style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <span style="color:#64748b;font-size:.88rem">Total:</span>
                <span id="edit_total_display" style="font-size:1.15rem;font-weight:800;color:#16a34a">$ 0,00</span>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-primary w-100" style="justify-content:center">
                    <i class="fa fa-save"></i> Guardar Cambios
                </button>
                <button type="button" onclick="closeModal('modal-editar-pago')" class="btn-ic btn-ghost" style="color:#64748b;border-color:#cbd5e1">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<?php
$page_scripts = '<script>
var _rechazarTimer = null;
var _aprobarTimer  = null;

function _iniciarCountdown(cdId, wrapId, btnId, segundos, onListo) {
    var btn  = document.getElementById(btnId);
    var cd   = document.getElementById(cdId);
    var wrap = document.getElementById(wrapId);
    btn.disabled = true;
    btn.style.opacity = ".45";
    btn.style.pointerEvents = "none";
    cd.textContent = segundos;
    var secs = segundos;
    return setInterval(function() {
        secs--;
        cd.textContent = secs;
        if (secs <= 0) {
            btn.disabled = false;
            btn.style.opacity = "1";
            btn.style.pointerEvents = "auto";
            wrap.innerHTML = \'<i class="fa fa-check-circle" style="color:#16a34a"></i> <span style="color:#16a34a">Podés confirmar ahora</span>\';
            if (onListo) onListo();
        }
    }, 1000);
}

function abrirAprobarJornada(fecha, cant, diaLabel, total) {
    document.getElementById("aprobar-jornada-fecha").value = fecha;
    document.getElementById("aprobar-jornada-label").textContent = diaLabel;
    document.getElementById("aprobar-jornada-cant").textContent = cant + " pago" + (cant !== 1 ? "s" : "") + " pendiente" + (cant !== 1 ? "s" : "");
    document.getElementById("aprobar-jornada-total").textContent = "Total: " + total;
    document.getElementById("aprobar-jornada-countdown-wrap").innerHTML = \'<i class="fa fa-clock"></i> Podés confirmar en <span id="aprobar-jornada-countdown" style="font-weight:800;font-size:1rem">3</span> segundos...\';
    openModal("modal-aprobar-jornada");
    _aprobarTimer = _iniciarCountdown("aprobar-jornada-countdown", "aprobar-jornada-countdown-wrap", "aprobar-jornada-btn", 3, null);
}

function cancelarAprobarJornada() {
    if (_aprobarTimer) { clearInterval(_aprobarTimer); _aprobarTimer = null; }
    closeModal("modal-aprobar-jornada");
}

function abrirRechazarPago(pago_id, cliente, monto) {
    document.getElementById("rechazar-pago-id").value = pago_id;
    document.getElementById("rechazar-cliente-label").textContent = cliente;
    document.getElementById("rechazar-monto-label").textContent = "Total: " + monto;
    document.getElementById("rechazar-countdown-wrap").innerHTML = \'<i class="fa fa-clock"></i> Podés confirmar en <span id="rechazar-countdown" style="font-weight:800;font-size:1rem">3</span> segundos...\';
    openModal("modal-rechazar-pago");
    _rechazarTimer = _iniciarCountdown("rechazar-countdown", "rechazar-countdown-wrap", "rechazar-confirmar-btn", 3, null);
}

function cancelarRechazarPago() {
    if (_rechazarTimer) { clearInterval(_rechazarTimer); _rechazarTimer = null; }
    closeModal("modal-rechazar-pago");
}

function abrirEditarPago(pago_id, ef, tr) {
    document.getElementById("edit_pago_id").value = pago_id;
    document.getElementById("edit_efectivo").value = ef.toFixed(2);
    document.getElementById("edit_transfer").value = tr.toFixed(2);
    actualizarEditTotal();
    openModal("modal-editar-pago");
}
function actualizarEditTotal() {
    const ef = parseFloat(document.getElementById("edit_efectivo").value) || 0;
    const tr = parseFloat(document.getElementById("edit_transfer").value) || 0;
    document.getElementById("edit_total_display").textContent = formatPesos(ef + tr);
}
</script>';
if (es_supervisor() && !es_admin()) {
    $page_scripts .= '<script>
function abrirSolBajaTemp(pago_id) {
    document.getElementById("sol_temp_id").value = pago_id;
    openModal("modal-sol-baja-temp");
}
</script>';
}
?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>