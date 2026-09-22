<?php
// tickets/procesar_respuesta.php — Handler POST para respuestas y cambios de estado
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_reclamos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}
verificar_csrf();

$pdo       = obtener_conexion();
$uid       = (int) $_SESSION['user_id'];
$ticket_id = (int) ($_POST['ticket_id'] ?? 0);
$back      = 'ver?id=' . $ticket_id;

if (!$ticket_id) {
    header('Location: index'); exit;
}

// Cargar ticket + cobrador del cliente (para el scoping de cartera)
$stmt = $pdo->prepare("
    SELECT tk.*, cl.cobrador_id
    FROM ic_tickets tk
    JOIN ic_clientes cl ON cl.id = tk.cliente_id
    WHERE tk.id = ?
");
$stmt->execute([$ticket_id]);
$tk = $stmt->fetch();

if (!$tk) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Caso no encontrado.'];
    header('Location: index'); exit;
}

if (es_cobrador() && (int) $tk['cobrador_id'] !== $uid) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'No tenés acceso a este caso.'];
    header('Location: index'); exit;
}

// Un envío puede traer mensaje, cambio de estado, o ambos a la vez (ej. el
// usuario escribe una novedad y aprieta "Resolver" en el mismo formulario) —
// se procesan los dos juntos, nunca se descarta el mensaje en silencio.
$mensaje      = trim($_POST['mensaje'] ?? '');
$nuevo_estado = $_POST['solo_estado'] ?? null;

if ($nuevo_estado !== null && !in_array($nuevo_estado, ['abierto', 'en_progreso', 'resuelto'], true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Estado inválido.'];
    header('Location: ' . $back); exit;
}

if ($mensaje === '' && $nuevo_estado === null) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'El mensaje no puede estar vacío.'];
    header('Location: ' . $back); exit;
}

// Un caso resuelto no admite mensajes ni queda en el mismo estado — la única
// acción válida sobre él es reabrirlo (nuevo_estado = 'abierto').
if ($tk['estado'] === 'resuelto' && $nuevo_estado !== 'abierto') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'El caso está resuelto. Reabrilo antes de responder o cambiar el estado.'];
    header('Location: ' . $back); exit;
}

try {
    $pdo->beginTransaction();

    if ($mensaje !== '') {
        $pdo->prepare("INSERT INTO ic_ticket_respuestas (ticket_id, usuario_id, mensaje) VALUES (?,?,?)")
            ->execute([$ticket_id, $uid, $mensaje]);
        registrar_log($pdo, $uid, 'TICKET_RESPUESTA', 'ticket', $ticket_id, mb_strimwidth($mensaje, 0, 80, '...'));
    }

    if ($nuevo_estado !== null) {
        $pdo->prepare("UPDATE ic_tickets SET estado=? WHERE id=?")->execute([$nuevo_estado, $ticket_id]);
        registrar_log($pdo, $uid, 'TICKET_ESTADO', 'ticket', $ticket_id, 'Estado → ' . $nuevo_estado);
    } elseif ($mensaje !== '') {
        // Sin cambio de estado explícito: solo progresar abierto → en_progreso
        // en la primera respuesta de alguien distinto del creador.
        $es_creador = ((int) $tk['creado_por'] === $uid);
        if ($tk['estado'] === 'abierto' && !$es_creador) {
            $pdo->prepare("UPDATE ic_tickets SET estado='en_progreso' WHERE id=?")->execute([$ticket_id]);
        } else {
            $pdo->prepare("UPDATE ic_tickets SET updated_at=NOW() WHERE id=?")->execute([$ticket_id]);
        }
    }

    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Caso actualizado.'];

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error al actualizar el caso.'];
}

header('Location: ' . $back);
exit;
