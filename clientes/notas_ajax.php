<?php
// clientes/notas_ajax.php — AJAX endpoint para notas internas de clientes
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

header('Content-Type: application/json');
$pdo        = obtener_conexion();
$method     = $_SERVER['REQUEST_METHOD'];
$cliente_id = (int)($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
$rol        = usuario_actual()['rol'];
$puede_escribir = in_array($rol, ['admin', 'supervisor']);

if (!$cliente_id) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit; }

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT n.id, n.nota, n.created_at,
               CONCAT(u.nombre, ' ', u.apellido) AS autor,
               u.rol AS autor_rol
        FROM ic_notas_cliente n
        JOIN ic_usuarios u ON u.id = n.usuario_id
        WHERE n.cliente_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$cliente_id]);
    echo json_encode(['ok' => true, 'notas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method === 'POST') {
    if (!$puede_escribir) { echo json_encode(['ok' => false, 'error' => 'Sin permiso']); exit; }
    $nota = trim($_POST['nota'] ?? '');
    if (strlen($nota) < 3) { echo json_encode(['ok' => false, 'error' => 'Nota muy corta']); exit; }
    $stmt = $pdo->prepare("INSERT INTO ic_notas_cliente (cliente_id, usuario_id, nota) VALUES (?,?,?)");
    $stmt->execute([$cliente_id, $_SESSION['user_id'], $nota]);
    registrar_log($pdo, $_SESSION['user_id'], 'NOTA_CLIENTE', 'cliente', $cliente_id, substr($nota, 0, 80));
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    if (!$puede_escribir) { echo json_encode(['ok' => false, 'error' => 'Sin permiso']); exit; }
    parse_str(file_get_contents('php://input'), $params);
    $nota_id = (int)($params['nota_id'] ?? 0);
    $pdo->prepare("DELETE FROM ic_notas_cliente WHERE id=? AND cliente_id=?")->execute([$nota_id, $cliente_id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Método no soportado']);
