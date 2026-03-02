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
    header('Location: index.php');
    exit;
}

// Obtener el crédito y validar estado
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, a.descripcion AS articulo
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();

if (!$cr || !in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'El crédito no existe o no se puede finalizar en su estado actual.'];
    header("Location: ver.php?id=$id");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motivo = $_POST['motivo_finalizacion'] ?? '';
    
    if (!in_array($motivo, ['PAGO_COMPLETO', 'RETIRO_PRODUCTO'])) {
        $error = 'Debe seleccionar un motivo válido para finalizar el crédito.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Actualizar estado del crédito
            $upd = $pdo->prepare("UPDATE ic_creditos SET estado = 'FINALIZADO', motivo_finalizacion = ? WHERE id = ?");
            $upd->execute([$motivo, $id]);
            
            // Si el motivo es retiro de producto, lo ideal es cancelar las cuotas pendientes o marcarlas.
            // Opcional: Puedes descomentar la siguiente línea si quieres dar de baja las cuotas pendientes y morosas.
            // $pdo->prepare("UPDATE ic_cuotas SET estado = 'CANCELADA' WHERE credito_id = ? AND estado IN ('PENDIENTE', 'VENCIDA')")->execute([$id]);
            
            // Log de actividad
            $desc_motivo = ($motivo === 'PAGO_COMPLETO') ? 'Pago completo' : 'Retiro de producto';
            registrar_log($pdo, $_SESSION['user_id'], 'CREDITO_FINALIZADO', 'credito', $id, "Finalizado por: $desc_motivo");
            
            $pdo->commit();
            
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Crédito finalizado correctamente (' . strtolower($desc_motivo) . ').'];
            header("Location: ver.php?id=$id");
            exit;
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
                <div>
                    <strong class="text-muted">Estado Actual:</strong> <?= badge_estado_credito($cr['estado']) ?>
                </div>
            </div>

            <div class="form-group mb-4">
                <label>Seleccione el motivo de finalización *</label>
                <select name="motivo_finalizacion" required class="form-control mb-2" style="padding: 10px; font-size: 1rem;">
                    <option value="">— Seleccionar motivo —</option>
                    <option value="PAGO_COMPLETO">Finalizado por Pago Completo</option>
                    <option value="RETIRO_PRODUCTO">Finalizado por Retiro del Producto</option>
                </select>
                <small class="text-muted"><i class="fa fa-info-circle"></i> Esta acción no se puede deshacer. Indique la razón por la que el crédito se está cerrando.</small>
            </div>

            <div class="d-flex gap-3 mt-4">
                <button type="submit" class="btn-ic btn-danger">
                    <i class="fa fa-check"></i> Confirmar Finalización
                </button>
                <a href="ver.php?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
