<?php
// tickets/procesar_ticket.php — Handler POST para crear ticket
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$pdo    = obtener_conexion();
$uid    = (int) $_SESSION['user_id'];

$titulo      = trim($_POST['titulo']      ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$prioridad   = $_POST['prioridad']        ?? 'media';
$tipo_del    = $_POST['tipo_delegacion']  ?? 'ninguna';

if (!$titulo || !$descripcion) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'El título y la descripción son obligatorios.'];
    header('Location: nuevo');
    exit;
}

if (!in_array($prioridad, ['baja','media','alta'])) {
    $prioridad = 'media';
}

$delegado_a_usuario = null;
$delegado_a_rol     = null;

if ($tipo_del === 'rol') {
    $rol_val = $_POST['delegado_a_rol'] ?? '';
    if (in_array($rol_val, ['admin','supervisor','cobrador','vendedor'])) {
        $delegado_a_rol = $rol_val;
    }
} elseif ($tipo_del === 'usuario') {
    $u_val = (int) ($_POST['delegado_a_usuario'] ?? 0);
    if ($u_val > 0) {
        // verificar que exista
        $chk = $pdo->prepare("SELECT id FROM ic_usuarios WHERE id=? AND activo=1");
        $chk->execute([$u_val]);
        if ($chk->fetch()) {
            $delegado_a_usuario = $u_val;
        }
    }
}

$stmt = $pdo->prepare("
    INSERT INTO ic_tickets (titulo, descripcion, creado_por, delegado_a_usuario, delegado_a_rol, prioridad)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([$titulo, $descripcion, $uid, $delegado_a_usuario, $delegado_a_rol, $prioridad]);
$ticket_id = $pdo->lastInsertId();

registrar_log($pdo, $uid, 'TICKET_CREADO', 'ticket', $ticket_id, mb_strimwidth($titulo, 0, 100, '...'));

$_SESSION['flash'] = ['type' => 'success', 'msg' => 'Ticket #' . $ticket_id . ' creado correctamente.'];
header('Location: ver?id=' . $ticket_id);
exit;
