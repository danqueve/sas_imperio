<?php
// views/403.php — Página de acceso denegado
if (!defined('BASE_URL')) define('BASE_URL', '/creditos/');
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Denegado — Imperio Comercial</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
        <div style="text-align:center;max-width:420px">
            <div style="font-size:4rem;margin-bottom:16px">🔒</div>
            <h1 style="font-size:1.6rem;font-weight:800;margin-bottom:8px">Acceso Denegado</h1>
            <p style="color:var(--text-muted);margin-bottom:28px">
                No tenés permiso para acceder a esta sección.
            </p>
            <a href="<?= BASE_URL ?>" class="btn-ic btn-primary">
                ← Volver al inicio
            </a>
        </div>
    </div>
</body>
</html>
