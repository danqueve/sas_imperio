<?php
// tickets/procesar_ticket.php — Handler POST para crear un Reclamo o Posventa
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

$pdo = obtener_conexion();
$uid = (int) $_SESSION['user_id'];

$tipo        = $_POST['tipo']        ?? '';
$cliente_id  = (int) ($_POST['cliente_id']  ?? 0);
$credito_id  = (int) ($_POST['credito_id']  ?? 0);
$titulo      = trim($_POST['titulo']      ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$prioridad   = $_POST['prioridad']        ?? 'media';

if (!in_array($tipo, ['reclamo', 'posventa'], true)) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Elegí el tipo de caso (Reclamo o Posventa).'];
    header('Location: nuevo');
    exit;
}

if (!$cliente_id || !$credito_id || !$titulo || !$descripcion) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Completá cliente, crédito, título y descripción.'];
    header('Location: nuevo');
    exit;
}

if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
    $prioridad = 'media';
}

// Scoping de cartera: un cobrador solo puede crear casos de sus propios
// clientes, sin importar qué haya mandado el <select> en el POST.
$cli_stmt = $pdo->prepare("SELECT cobrador_id FROM ic_clientes WHERE id = ? AND estado = 'ACTIVO'");
$cli_stmt->execute([$cliente_id]);
$cliente = $cli_stmt->fetch();

if (!$cliente) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Cliente no encontrado.'];
    header('Location: nuevo');
    exit;
}

if (es_cobrador() && (int) $cliente['cobrador_id'] !== $uid) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Ese cliente no pertenece a tu cartera.'];
    header('Location: nuevo');
    exit;
}

// El crédito debe pertenecer efectivamente a ese cliente.
$cr_stmt = $pdo->prepare("SELECT id FROM ic_creditos WHERE id = ? AND cliente_id = ?");
$cr_stmt->execute([$credito_id, $cliente_id]);
if (!$cr_stmt->fetch()) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'El crédito elegido no corresponde a ese cliente.'];
    header('Location: nuevo');
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO ic_tickets (tipo, cliente_id, credito_id, titulo, descripcion, creado_por, prioridad)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$tipo, $cliente_id, $credito_id, $titulo, $descripcion, $uid, $prioridad]);
$ticket_id = $pdo->lastInsertId();

registrar_log($pdo, $uid, 'TICKET_CREADO', 'ticket', $ticket_id, ucfirst($tipo) . ' — ' . mb_strimwidth($titulo, 0, 100, '...'));

$_SESSION['flash'] = ['type' => 'success', 'msg' => ucfirst($tipo) . ' #' . $ticket_id . ' creado correctamente.'];
header('Location: ver?id=' . $ticket_id);
exit;
