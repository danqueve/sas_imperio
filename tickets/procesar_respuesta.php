<?php
// tickets/procesar_respuesta.php — Handler POST para respuestas y cambios de estado
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$pdo       = obtener_conexion();
$uid       = (int) $_SESSION['user_id'];
$ticket_id = (int) ($_POST['ticket_id'] ?? 0);
$back      = 'ver?id=' . $ticket_id;

if (!$ticket_id) {
    header('Location: index'); exit;
}

// Cargar ticket
$stmt = $pdo->prepare("SELECT * FROM ic_tickets WHERE id = ?");
$stmt->execute([$ticket_id]);
$tk = $stmt->fetch();

if (!$tk) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Ticket no encontrado.'];
    header('Location: index'); exit;
}

$es_creador = ((int)$tk['creado_por'] === $uid);
$es_admin   = es_admin();
$puede_cerrar = $es_creador || $es_admin;

// ── Solo cambio de estado (sin mensaje) ──────────────────────
if (isset($_POST['solo_estado'])) {
    $nuevo_estado = $_POST['solo_estado'];

    if (!in_array($nuevo_estado, ['abierto','en_progreso','resuelto'])) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Estado inválido.'];
        header('Location: ' . $back); exit;
    }

    if ($nuevo_estado === 'resuelto' && !$puede_cerrar) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Solo el creador o un administrador puede cerrar el ticket.'];
        header('Location: ' . $back); exit;
    }

    if ($nuevo_estado === 'abierto' && !$puede_cerrar) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Sin permisos para reabrir el ticket.'];
        header('Location: ' . $back); exit;
    }

    $pdo->prepare("UPDATE ic_tickets SET estado=? WHERE id=?")->execute([$nuevo_estado, $ticket_id]);
    registrar_log($pdo, $uid, 'TICKET_ESTADO', 'ticket', $ticket_id, 'Estado → ' . $nuevo_estado);

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Estado actualizado: ' . ucfirst(str_replace('_',' ',$nuevo_estado)) . '.'];
    header('Location: ' . $back); exit;
}

// ── Respuesta con mensaje ────────────────────────────────────
if ($tk['estado'] === 'resuelto') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'El ticket está resuelto. Reabrilo antes de responder.'];
    header('Location: ' . $back); exit;
}

$mensaje = trim($_POST['mensaje'] ?? '');
if (!$mensaje) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'El mensaje no puede estar vacío.'];
    header('Location: ' . $back); exit;
}

try {
    $pdo->beginTransaction();

    // Insertar respuesta
    $pdo->prepare("INSERT INTO ic_ticket_respuestas (ticket_id, usuario_id, mensaje) VALUES (?,?,?)")
        ->execute([$ticket_id, $uid, $mensaje]);

    // Auto-progresar abierto → en_progreso en primera respuesta de otro usuario
    if ($tk['estado'] === 'abierto' && !$es_creador) {
        $pdo->prepare("UPDATE ic_tickets SET estado='en_progreso' WHERE id=?")->execute([$ticket_id]);
    } else {
        // Actualizar updated_at para reflejar actividad
        $pdo->prepare("UPDATE ic_tickets SET updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
    }

    $pdo->commit();
    registrar_log($pdo, $uid, 'TICKET_RESPUESTA', 'ticket', $ticket_id, mb_strimwidth($mensaje, 0, 80, '...'));
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Respuesta enviada.'];

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al enviar la respuesta.'];
}

header('Location: ' . $back);
exit;
