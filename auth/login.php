<?php
// ============================================================
// auth/login.php — Sistema Imperio Comercial
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';

// Si ya tiene sesión activa, redirigir según rol
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . (es_admin() || es_supervisor() ? 'admin/dashboard.php' : 'cobrador/agenda.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($usuario === '' || $password === '') {
        $error = 'Completá usuario y contraseña.';
    } else {
        $pdo = obtener_conexion();
        $stmt = $pdo->prepare("SELECT * FROM ic_usuarios WHERE usuario = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nombre'] = $user['nombre'];
            $_SESSION['apellido'] = $user['apellido'];
            $_SESSION['rol'] = $user['rol'];

            $destino = BASE_URL . ($user['rol'] === 'cobrador' ? 'cobrador/agenda.php' : 'admin/dashboard.php');
            header("Location: $destino");
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — Imperio Comercial</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>
    <div class="login-page">
        <div class="login-card">
            <div class="login-logo">
                <div class="login-logo-icon">💼</div>
                <h1>Imperio Comercial</h1>
                <p>Sistema de Gestión de Créditos</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-ic alert-danger">
                    <i class="fa fa-exclamation-circle"></i>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="form-ic" autocomplete="on">
                <div class="form-group mb-3">
                    <label for="usuario"><i class="fa fa-user"></i> Usuario</label>
                    <input type="text" id="usuario" name="usuario" value="<?= e($_POST['usuario'] ?? '') ?>"
                        placeholder="Nombre de usuario" autofocus required>
                </div>
                <div class="form-group mb-4">
                    <label for="password"><i class="fa fa-lock"></i> Contraseña</label>
                    <div style="position:relative">
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                        <button type="button" onclick="togglePass()" class="btn-ic btn-ghost btn-icon"
                            style="position:absolute;right:6px;top:50%;transform:translateY(-50%);padding:4px 8px">
                            <i class="fa fa-eye" id="eye-icon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-ic btn-primary w-100" style="justify-content:center;padding:12px">
                    <i class="fa fa-sign-in-alt"></i> Ingresar
                </button>
            </form>
        </div>
    </div>

    <script>
        function togglePass() {
            const inp = document.getElementById('password');
            const ico = document.getElementById('eye-icon');
            if (inp.type === 'password') {
                inp.type = 'text';
                ico.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                inp.type = 'password';
                ico.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>

</html>