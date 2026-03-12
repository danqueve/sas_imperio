<?php
// creditos/condonar_mora.php — Condonar mora congelada de una cuota CAP_PAGADA
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones'); // admin o supervisor

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$pdo        = obtener_conexion();
$cuota_id   = (int) ($_POST['cuota_id']   ?? 0);
$credito_id = (int) ($_POST['credito_id'] ?? 0);

if (!$cuota_id || !$credito_id) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Datos inválidos.'];
    header('Location: ver?id=' . $credito_id);
    exit;
}

// Verificar que la cuota existe, pertenece al crédito y está en CAP_PAGADA
$stmt = $pdo->prepare("SELECT * FROM ic_cuotas WHERE id = ? AND credito_id = ?");
$stmt->execute([$cuota_id, $credito_id]);
$cuota = $stmt->fetch();

if (!$cuota || $cuota['estado'] !== 'CAP_PAGADA') {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'La cuota no está en estado CAP_PAGADA.'];
    header('Location: ver?id=' . $credito_id);
    exit;
}

$mora_condonada = (float) $cuota['monto_mora'];

// Condonar: quitar mora congelada → cuota pasa a PAGADA
$pdo->prepare("
    UPDATE ic_cuotas
    SET monto_mora = 0,
        estado     = 'PAGADA',
        fecha_pago = CURDATE()
    WHERE id = ?
")->execute([$cuota_id]);

registrar_log($pdo, $_SESSION['user_id'], 'MORA_CONDONADA', 'cuota', $cuota_id,
    'Cuota #' . $cuota['numero_cuota'] . ' — Mora condonada: ' . formato_pesos($mora_condonada));

// Si ya no quedan cuotas pendientes → finalizar crédito
$pendientes_stmt = $pdo->prepare("SELECT COUNT(*) FROM ic_cuotas WHERE credito_id = ? AND estado != 'PAGADA'");
$pendientes_stmt->execute([$credito_id]);
if ((int) $pendientes_stmt->fetchColumn() === 0) {
    $pdo->prepare("
        UPDATE ic_creditos SET estado = 'FINALIZADO', motivo_finalizacion = 'PAGO_COMPLETO' WHERE id = ?
    ")->execute([$credito_id]);
}

$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Mora de la cuota #' . $cuota['numero_cuota'] . ' condonada. La cuota queda registrada como pagada.'];
header('Location: ver?id=' . $credito_id);
exit;
