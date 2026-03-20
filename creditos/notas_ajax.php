<?php
// ============================================================
// creditos/notas_ajax.php — AJAX: listar y crear notas internas
// Requiere: rol admin o supervisor
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

header('Content-Type: application/json; charset=utf-8');
$pdo       = obtener_conexion();
$metodo    = $_SERVER['REQUEST_METHOD'];
$credito_id = (int)($_GET['credito_id'] ?? $_POST['credito_id'] ?? 0);
$user      = usuario_actual();
$es_admin  = in_array($user['rol'], ['admin', 'supervisor']);

if (!$credito_id) {
    http_response_code(400);
    echo json_encode(['error' => 'credito_id requerido']);
    exit;
}

if ($metodo === 'GET') {
    // Listar notas del crédito
    $stmt = $pdo->prepare("
        SELECT n.id, n.nota, n.created_at,
               CONCAT(u.nombre,' ',u.apellido) AS autor,
               u.rol AS autor_rol
        FROM ic_notas_credito n
        JOIN ic_usuarios u ON n.usuario_id = u.id
        WHERE n.credito_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$credito_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($metodo === 'POST') {
    if (!$es_admin) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    $nota = trim($_POST['nota'] ?? '');
    if (strlen($nota) < 3) {
        http_response_code(422);
        echo json_encode(['error' => 'La nota debe tener al menos 3 caracteres']);
        exit;
    }
    $ins = $pdo->prepare("INSERT INTO ic_notas_credito (credito_id, usuario_id, nota) VALUES (?,?,?)");
    $ins->execute([$credito_id, $user['id'], $nota]);
    registrar_log($pdo, $user['id'], 'NOTA_AGREGADA', 'credito', $credito_id, mb_substr($nota, 0, 80));
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($metodo === 'DELETE') {
    if (!$es_admin) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    $nota_id = (int)($_GET['nota_id'] ?? 0);
    $pdo->prepare("DELETE FROM ic_notas_credito WHERE id=? AND credito_id=?")->execute([$nota_id, $credito_id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
