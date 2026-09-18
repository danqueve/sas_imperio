<?php
// ============================================================
// admin/solicitudes_autorizacion.php — Autorización de super admin
// para acciones grandes (revertir pago confirmado, refinanciar crédito)
// pedidas por un admin regular.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

// No es un permiso del array $permisos (que es por rol) — es un nivel
// dentro del rol admin, así que se chequea aparte.
if (!es_super_admin()) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;text-align:center;padding:60px">
            <h2>⛔ Acceso denegado</h2>
            <p>Esta sección es solo para super admin.</p>
            <a href="' . BASE_URL . '">← Volver</a>
          </div>';
    exit;
}

$pdo = obtener_conexion();

$TIPO_LABELS = [
    'revertir_pago_confirmado' => 'Revertir pago confirmado',
    'refinanciar_credito'      => 'Refinanciar crédito',
    'anular_pago_temporal'     => 'Anular pago pendiente',
];

// ── POST: aprobar / rechazar ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion  = $_POST['accion'] ?? '';
    $sol_id  = (int) ($_POST['solicitud_id'] ?? 0);

    $s_stmt = $pdo->prepare("SELECT * FROM ic_solicitudes_autorizacion WHERE id = ?");
    $s_stmt->execute([$sol_id]);
    $sol = $s_stmt->fetch();

    if (!$sol || $sol['estado'] !== 'PENDIENTE') {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Solicitud no encontrada o ya resuelta.'];
        header('Location: solicitudes_autorizacion');
        exit;
    }

    if ($accion === 'aprobar') {
        $payload = json_decode($sol['payload'], true) ?: [];
        try {
            if ($sol['tipo_accion'] === 'revertir_pago_confirmado') {
                ejecutar_reversion_pago_confirmado($pdo, (int) $sol['entidad_id'], (int) $payload['credito_id'], $_SESSION['user_id']);
                $resultado_id = null;
            } elseif ($sol['tipo_accion'] === 'refinanciar_credito') {
                $resultado_id = ejecutar_refinanciacion($pdo, (int) $sol['entidad_id'], $payload, $_SESSION['user_id']);
            } elseif ($sol['tipo_accion'] === 'anular_pago_temporal') {
                ejecutar_anulacion_pago_temporal($pdo, (int) $sol['entidad_id'], $_SESSION['user_id']);
                $resultado_id = null;
            } else {
                throw new Exception('Tipo de acción desconocido: ' . $sol['tipo_accion']);
            }

            $pdo->prepare("
                UPDATE ic_solicitudes_autorizacion
                SET estado='APROBADA', aprobador_id=?, fecha_resolucion=NOW(), resultado_entidad_id=?
                WHERE id=?
            ")->execute([$_SESSION['user_id'], $resultado_id, $sol_id]);

            registrar_log($pdo, $_SESSION['user_id'], 'SOLICITUD_AUTORIZACION_APROBADA', $sol['entidad'], (int) $sol['entidad_id'],
                'Solicitud #' . $sol_id . ' (' . $sol['tipo_accion'] . ') aprobada'
                . ($resultado_id ? ' — Crédito nuevo #' . $resultado_id : ''));

            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Solicitud aprobada y ejecutada.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'No se pudo ejecutar: ' . $e->getMessage() . ' — la solicitud sigue pendiente.'];
        }
    } elseif ($accion === 'rechazar') {
        $motivo_rechazo = trim($_POST['motivo_rechazo'] ?? '');
        $pdo->prepare("
            UPDATE ic_solicitudes_autorizacion
            SET estado='RECHAZADA', aprobador_id=?, fecha_resolucion=NOW(), motivo_rechazo=?
            WHERE id=?
        ")->execute([$_SESSION['user_id'], $motivo_rechazo ?: null, $sol_id]);

        registrar_log($pdo, $_SESSION['user_id'], 'SOLICITUD_AUTORIZACION_RECHAZADA', $sol['entidad'], (int) $sol['entidad_id'],
            'Solicitud #' . $sol_id . ' (' . $sol['tipo_accion'] . ') rechazada');

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Solicitud rechazada.'];
    }

    header('Location: solicitudes_autorizacion');
    exit;
}

// ── Listado ────────────────────────────────────────────────────
function cargar_solicitudes(PDO $pdo, string $estadoSql): array
{
    $stmt = $pdo->prepare("
        SELECT sa.*, CONCAT(u.nombre, ' ', u.apellido) AS solicitante_nombre,
               CONCAT(ua.nombre, ' ', ua.apellido) AS aprobador_nombre
        FROM ic_solicitudes_autorizacion sa
        JOIN ic_usuarios u ON u.id = sa.solicitante_id
        LEFT JOIN ic_usuarios ua ON ua.id = sa.aprobador_id
        WHERE sa.estado $estadoSql
        ORDER BY sa.id DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

$pendientes = cargar_solicitudes($pdo, "= 'PENDIENTE'");
$resueltas  = array_slice(cargar_solicitudes($pdo, "IN ('APROBADA','RECHAZADA')"), 0, 20);

// Contexto legible de cada solicitud (crédito, cliente, monto, etc.).
// Desde que existe `detalle_contexto` (calculado por el llamador al crear
// la solicitud, cuando la entidad todavía existe seguro), se usa ese
// texto guardado — funciona igual para PENDIENTE y para ya resueltas,
// sin importar que aprobar una reversión/anulación borre la fila
// original. El fallback de re-consulta en vivo queda solo para
// solicitudes viejas creadas antes de que existiera esa columna.
function contexto_solicitud(PDO $pdo, array $sol): array
{
    $payload = json_decode($sol['payload'], true) ?: [];
    $credito_id = match ($sol['tipo_accion']) {
        'revertir_pago_confirmado', 'anular_pago_temporal' => (int) ($payload['credito_id'] ?? 0),
        'refinanciar_credito' => (int) $sol['entidad_id'],
        default => 0,
    };

    if (!empty($sol['detalle_contexto'])) {
        return ['credito_id' => $credito_id, 'detalle' => $sol['detalle_contexto']];
    }

    if ($sol['tipo_accion'] === 'revertir_pago_confirmado') {
        $stmt = $pdo->prepare("
            SELECT pc.monto_total, cu.numero_cuota, cl.apellidos, cl.nombres
            FROM ic_pagos_confirmados pc
            JOIN ic_cuotas cu ON cu.id = pc.cuota_id
            JOIN ic_creditos cr ON cr.id = cu.credito_id
            JOIN ic_clientes cl ON cl.id = cr.cliente_id
            WHERE pc.id = ?
        ");
        $stmt->execute([(int) $sol['entidad_id']]);
        $r = $stmt->fetch();
        if (!$r) return ['credito_id' => $credito_id, 'detalle' => 'El pago ya no existe (puede haber sido revertido por otra vía).'];
        return [
            'credito_id' => $credito_id,
            'detalle' => 'Cliente: ' . $r['apellidos'] . ', ' . $r['nombres']
                . ' — Cuota #' . $r['numero_cuota'] . ' — ' . formato_pesos($r['monto_total']),
        ];
    }
    if ($sol['tipo_accion'] === 'refinanciar_credito') {
        $stmt = $pdo->prepare("SELECT cl.apellidos, cl.nombres FROM ic_creditos cr JOIN ic_clientes cl ON cl.id=cr.cliente_id WHERE cr.id=?");
        $stmt->execute([$credito_id]);
        $r = $stmt->fetch();
        $cliente = $r ? ($r['apellidos'] . ', ' . $r['nombres']) : '—';
        return [
            'credito_id' => $credito_id,
            'detalle' => 'Cliente: ' . $cliente
                . ' — ' . (int) ($payload['nuevas_cuotas'] ?? 0) . ' cuotas ' . ($payload['frecuencia'] ?? '')
                . (!empty($payload['capitalizar_mora']) ? ' — capitaliza mora' : '')
                . ((float) ($payload['interes_adicional'] ?? 0) > 0 ? ' — +' . $payload['interes_adicional'] . '% interés adicional' : ''),
        ];
    }
    if ($sol['tipo_accion'] === 'anular_pago_temporal') {
        $stmt = $pdo->prepare("
            SELECT pt.monto_total, pt.estado, cu.numero_cuota, cr.id AS credito_id, cl.apellidos, cl.nombres
            FROM ic_pagos_temporales pt
            JOIN ic_cuotas cu ON cu.id = pt.cuota_id
            JOIN ic_creditos cr ON cr.id = cu.credito_id
            JOIN ic_clientes cl ON cl.id = cr.cliente_id
            WHERE pt.id = ?
        ");
        $stmt->execute([(int) $sol['entidad_id']]);
        $r = $stmt->fetch();
        if (!$r) return ['credito_id' => $credito_id, 'detalle' => 'El pago ya no existe (puede haber sido aprobado o anulado por otra vía).'];
        $detalle = 'Cliente: ' . $r['apellidos'] . ', ' . $r['nombres']
            . ' — Cuota #' . $r['numero_cuota'] . ' — ' . formato_pesos($r['monto_total']);
        if ($r['estado'] !== 'PENDIENTE') $detalle .= ' (el pago ya no está pendiente por otra vía: ' . $r['estado'] . ')';
        return ['credito_id' => (int) $r['credito_id'], 'detalle' => $detalle];
    }
    return ['credito_id' => 0, 'detalle' => '—'];
}

// Celda de detalle compartida por las 2 tablas — igual para ambas salvo
// que, en una solicitud de refinanciación ya aprobada, también linkea al
// crédito nuevo que se creó al ejecutarla.
function celda_detalle(array $ctx, array $s): string
{
    $html = e($ctx['detalle']);
    if ($ctx['credito_id']) {
        $html .= '<br><a href="../creditos/ver?id=' . $ctx['credito_id'] . '" target="_blank" style="font-size:.75rem">Ver crédito #' . $ctx['credito_id'] . ' →</a>';
    }
    if ($s['estado'] === 'APROBADA' && $s['tipo_accion'] === 'refinanciar_credito' && !empty($s['resultado_entidad_id'])) {
        $html .= '<br><a href="../creditos/ver?id=' . (int) $s['resultado_entidad_id'] . '" target="_blank" style="font-size:.75rem;color:var(--success)">→ Crédito nuevo #' . (int) $s['resultado_entidad_id'] . '</a>';
    }
    return $html;
}

$page_title   = 'Solicitudes de Autorización';
$page_current = 'solicitudes_autorizacion';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-shield-halved"></i> Pendientes de Autorización</span>
        <span class="text-muted" style="font-size:.8rem"><?= count($pendientes) ?> solicitud<?= count($pendientes) !== 1 ? 'es' : '' ?></span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Tipo</th>
                    <th>Detalle</th>
                    <th>Solicitante</th>
                    <th>Motivo</th>
                    <th>Fecha</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pendientes)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding:32px">No hay solicitudes pendientes.</td></tr>
            <?php else: ?>
            <?php foreach ($pendientes as $s):
                $ctx = contexto_solicitud($pdo, $s);
            ?>
                <tr>
                    <td class="text-muted">#<?= $s['id'] ?></td>
                    <td><span class="badge-ic badge-warning"><?= e($TIPO_LABELS[$s['tipo_accion']] ?? $s['tipo_accion']) ?></span></td>
                    <td style="font-size:.85rem"><?= celda_detalle($ctx, $s) ?></td>
                    <td><?= e($s['solicitante_nombre']) ?></td>
                    <td style="max-width:220px;font-size:.82rem"><?= e($s['motivo']) ?></td>
                    <td class="nowrap text-muted" style="font-size:.78rem"><?= date('d/m/Y H:i', strtotime($s['fecha_solicitud'])) ?></td>
                    <td class="nowrap">
                        <form method="POST" style="display:inline">
                            <?php csrf_input(); ?>
                            <input type="hidden" name="accion" value="aprobar">
                            <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn-ic btn-success btn-sm" onclick="return confirm('¿Aprobar y ejecutar esta solicitud?')">
                                <i class="fa fa-check"></i> Aprobar
                            </button>
                        </form>
                        <button type="button" class="btn-ic btn-danger btn-sm" onclick="abrirRechazar(<?= $s['id'] ?>)">
                            <i class="fa fa-xmark"></i> Rechazar
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-clock-rotate-left"></i> Resueltas recientes</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Tipo</th>
                    <th>Detalle</th>
                    <th>Solicitante</th>
                    <th>Motivo</th>
                    <th>Estado</th>
                    <th>Resuelto por</th>
                    <th>Fecha resolución</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($resueltas)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:24px">Sin solicitudes resueltas todavía.</td></tr>
            <?php else: ?>
            <?php foreach ($resueltas as $s):
                $ctx = contexto_solicitud($pdo, $s);
            ?>
                <tr>
                    <td class="text-muted">#<?= $s['id'] ?></td>
                    <td><?= e($TIPO_LABELS[$s['tipo_accion']] ?? $s['tipo_accion']) ?></td>
                    <td style="font-size:.85rem"><?= celda_detalle($ctx, $s) ?></td>
                    <td><?= e($s['solicitante_nombre']) ?></td>
                    <td style="max-width:220px;font-size:.82rem"><?= e($s['motivo']) ?></td>
                    <td>
                        <span class="badge-ic <?= $s['estado'] === 'APROBADA' ? 'badge-success' : 'badge-danger' ?>">
                            <?= e($s['estado']) ?>
                        </span>
                        <?php if ($s['estado'] === 'RECHAZADA' && !empty($s['motivo_rechazo'])): ?>
                            <div class="text-muted" style="font-size:.72rem">Motivo del rechazo: <?= e($s['motivo_rechazo']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($s['aprobador_nombre'] ?? '—') ?></td>
                    <td class="nowrap text-muted" style="font-size:.78rem"><?= $s['fecha_resolucion'] ? date('d/m/Y H:i', strtotime($s['fecha_resolucion'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Rechazar -->
<div class="modal-overlay" id="modal-rechazar-sol">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-xmark"></i> Rechazar Solicitud</div>
            <button class="modal-close" onclick="closeModal('modal-rechazar-sol')">✕</button>
        </div>
        <form method="POST" class="form-ic">
            <?php csrf_input(); ?>
            <input type="hidden" name="accion" value="rechazar">
            <input type="hidden" name="solicitud_id" id="rechazar_sol_id">
            <div class="form-group mb-4">
                <label>Motivo del rechazo (opcional)</label>
                <textarea name="motivo_rechazo" rows="3" placeholder="Ej: No corresponde, faltan datos..."></textarea>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-danger w-100" style="justify-content:center">
                    <i class="fa fa-xmark"></i> Confirmar Rechazo
                </button>
                <button type="button" onclick="closeModal('modal-rechazar-sol')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirRechazar(sol_id) {
    document.getElementById('rechazar_sol_id').value = sol_id;
    openModal('modal-rechazar-sol');
}
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
