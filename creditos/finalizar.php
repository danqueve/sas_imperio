<?php
// ============================================================
// creditos/finalizar.php — Finalizar crédito manualmente
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index');
    exit;
}

// Obtener el crédito y datos del cliente
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();

if (!$cr || !in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'El crédito no existe o no se puede finalizar en su estado actual.'];
    header("Location: ver?id=$id");
    exit;
}

// Calcular cuotas con mora para sugerir el motivo correcto
$mora_stmt = $pdo->prepare("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id = ? AND monto_mora > 0");
$mora_stmt->execute([$id]);
$cuotas_con_mora = (int)$mora_stmt->fetchColumn();
$motivo_sugerido = $cuotas_con_mora > 0 ? 'PAGO_COMPLETO_CON_MORA' : 'PAGO_COMPLETO';

$error = '';
$finalizado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motivo = $_POST['motivo_finalizacion'] ?? '';

    $motivos_validos = ['PAGO_COMPLETO', 'PAGO_COMPLETO_CON_MORA', 'RETIRO_PRODUCTO', 'INCOBRABILIDAD', 'ACUERDO_EXTRAJUDICIAL'];
    if (!in_array($motivo, $motivos_validos)) {
        $error = 'Debe seleccionar un motivo válido para finalizar el crédito.';
    } else {
        try {
            $pdo->beginTransaction();

            // Actualizar estado del crédito con fecha y motivo
            $upd = $pdo->prepare("
                UPDATE ic_creditos
                SET estado = 'FINALIZADO',
                    motivo_finalizacion = ?,
                    fecha_finalizacion = CURDATE()
                WHERE id = ?
            ");
            $upd->execute([$motivo, $id]);

            // Si es retiro de producto, cancelar cuotas pendientes
            if ($motivo === 'RETIRO_PRODUCTO' || $motivo === 'INCOBRABILIDAD') {
                $pdo->prepare("UPDATE ic_cuotas SET estado = 'CANCELADA' WHERE credito_id = ? AND estado IN ('PENDIENTE', 'VENCIDA')")
                    ->execute([$id]);
            }

            // Log de actividad
            $desc_motivo_map = [
                'PAGO_COMPLETO'           => 'Pago completo',
                'PAGO_COMPLETO_CON_MORA'  => 'Pago completo con mora',
                'RETIRO_PRODUCTO'         => 'Retiro de producto',
                'INCOBRABILIDAD'          => 'Declarado incobrable',
                'ACUERDO_EXTRAJUDICIAL'   => 'Acuerdo extrajudicial',
            ];
            $desc_motivo = $desc_motivo_map[$motivo] ?? $motivo;
            registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_FINALIZADO', 'credito', $id, "Finalizado por: $desc_motivo");

            $pdo->commit();

            // Recalcular puntaje del cliente si fue un pago (no retiro ni incobrable)
            if (in_array($motivo, ['PAGO_COMPLETO', 'PAGO_COMPLETO_CON_MORA'])) {
                actualizar_puntaje_cliente((int)$cr['cliente_id'], $pdo);
            }

            $finalizado = true;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Ocurrió un error al intentar finalizar el crédito: ' . $e->getMessage();
        }
    }
}

$page_title = 'Finalizar Crédito #' . $id;
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if ($finalizado): ?>
<!-- ── Pantalla de éxito con acciones ───────────────────────── -->
<div style="max-width:600px; margin: 0 auto;">
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title text-success"><i class="fa fa-check-circle"></i> Crédito Finalizado</span>
        </div>
        <div style="padding: 24px; text-align:center;">
            <div style="font-size: 3rem; margin-bottom: 12px;">🎉</div>
            <h3 style="margin-bottom: 6px;"><?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?></h3>
            <p class="text-muted" style="margin-bottom: 20px;">El crédito <strong>#<?= $id ?></strong> fue finalizado correctamente.</p>

            <div style="display:flex; flex-direction:column; gap:12px; align-items:center; margin-bottom:24px;">

                <?php
                $motivo_post = $_POST['motivo_finalizacion'] ?? '';
                if (in_array($motivo_post, ['PAGO_COMPLETO', 'PAGO_COMPLETO_CON_MORA']) && $cr['telefono']):
                    $wa_url = whatsapp_finalizacion_url($cr['telefono'], $cr['nombres'], $cr['articulo']);
                ?>
                <a href="<?= e($wa_url) ?>" target="_blank"
                   class="btn-ic btn-success"
                   style="display:inline-flex;align-items:center;gap:8px;min-width:240px;justify-content:center">
                    <i class="fa-brands fa-whatsapp"></i>
                    Enviar felicitación por WhatsApp
                </a>
                <?php endif; ?>

                <a href="../creditos/nuevo?cliente_id=<?= (int)$cr['cliente_id'] ?>"
                   class="btn-ic btn-primary"
                   style="display:inline-flex;align-items:center;gap:8px;min-width:240px;justify-content:center">
                    <i class="fa fa-plus"></i>
                    Generar nuevo crédito para este cliente
                </a>

                <a href="ver?id=<?= $id ?>" class="btn-ic btn-ghost"
                   style="min-width:240px;text-align:center">
                    <i class="fa fa-arrow-left"></i>
                    Volver al crédito
                </a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── Formulario de finalización ───────────────────────────── -->
<div style="max-width:600px; margin: 0 auto;">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title text-danger"><i class="fa fa-power-off"></i> Finalizar Crédito</span>
            </div>

            <div class="mb-4" style="background:var(--dark-bg); padding:15px; border-radius:8px; border:1px solid var(--dark-border);">
                <div style="margin-bottom:8px;">
                    <strong class="text-muted">Cliente:</strong> <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                </div>
                <div style="margin-bottom:8px;">
                    <strong class="text-muted">Artículo:</strong> <?= e($cr['articulo']) ?>
                </div>
                <div style="margin-bottom:8px;">
                    <strong class="text-muted">Estado Actual:</strong> <?= badge_estado_credito($cr['estado']) ?>
                </div>
                <?php if ($cuotas_con_mora > 0): ?>
                <div>
                    <strong class="text-muted">Cuotas con mora:</strong>
                    <span class="badge-ic badge-warning"><?= $cuotas_con_mora ?> cuota<?= $cuotas_con_mora > 1 ? 's' : '' ?> con mora</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="form-group mb-4">
                <label>Seleccione el motivo de finalización *</label>
                <select name="motivo_finalizacion" required class="form-control mb-2" style="padding: 10px; font-size: 1rem;">
                    <option value="">— Seleccionar motivo —</option>
                    <option value="PAGO_COMPLETO" <?= $motivo_sugerido === 'PAGO_COMPLETO' ? 'selected' : '' ?>>
                        ✅ Finalizado por Pago Completo
                    </option>
                    <option value="PAGO_COMPLETO_CON_MORA" <?= $motivo_sugerido === 'PAGO_COMPLETO_CON_MORA' ? 'selected' : '' ?>>
                        ⚠️ Finalizado por Pago Completo (con mora acumulada)
                    </option>
                    <option value="RETIRO_PRODUCTO">
                        📦 Finalizado por Retiro del Producto
                    </option>
                    <option value="INCOBRABILIDAD">
                        ❌ Declarado Incobrable
                    </option>
                    <option value="ACUERDO_EXTRAJUDICIAL">
                        🤝 Acuerdo Extrajudicial
                    </option>
                </select>
                <?php if ($motivo_sugerido === 'PAGO_COMPLETO_CON_MORA'): ?>
                <small class="text-warning"><i class="fa fa-triangle-exclamation"></i>
                    Se detectaron <?= $cuotas_con_mora ?> cuota<?= $cuotas_con_mora > 1 ? 's' : '' ?> con mora. Se sugiere "Pago Completo con mora".
                </small>
                <?php else: ?>
                <small class="text-muted"><i class="fa fa-info-circle"></i> Esta acción no se puede deshacer.</small>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-3 mt-4">
                <button type="submit" class="btn-ic btn-danger">
                    <i class="fa fa-check"></i> Confirmar Finalización
                </button>
                <a href="ver?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
