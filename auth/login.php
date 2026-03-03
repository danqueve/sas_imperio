<?php
// ============================================================
// auth/login.php — Sistema Imperio Comercial
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . (es_admin() || es_supervisor() ? 'admin/dashboard' : 'cobrador/agenda'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($usuario === '' || $password === '') {
        $error = 'Completá usuario y contraseña.';
    } else {
        $pdo  = obtener_conexion();
        $stmt = $pdo->prepare("SELECT * FROM ic_usuarios WHERE usuario = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['nombre']   = $user['nombre'];
            $_SESSION['apellido'] = $user['apellido'];
            $_SESSION['rol']      = $user['rol'];

            $destino = BASE_URL . ($user['rol'] === 'cobrador' ? 'cobrador/agenda' : 'admin/dashboard');
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
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=4">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: radial-gradient(ellipse at 50% 0%, rgba(60,80,224,.18) 0%, transparent 60%),
                        var(--dark);
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--dark-card);
            border: 1px solid var(--dark-border);
            border-radius: 16px;
            padding: 40px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,.5);
        }
        .login-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
            gap: 16px;
        }
        .login-logo-img {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(60,80,224,.4);
            box-shadow: 0 0 0 6px rgba(60,80,224,.1), 0 8px 24px rgba(0,0,0,.4);
        }
        .login-logo h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -.3px;
        }
        .login-form-group {
            margin-bottom: 18px;
        }
        .login-form-group label {
            display: block;
            font-size: .75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 7px;
        }
        .login-input-wrap {
            position: relative;
        }
        .login-input-wrap .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: .85rem;
            pointer-events: none;
        }
        .login-input-wrap input {
            width: 100%;
            background: var(--dark-input);
            border: 1px solid var(--dark-border);
            border-radius: var(--radius-sm);
            color: var(--text-main);
            padding: 11px 14px 11px 38px;
            font-family: inherit;
            font-size: .9rem;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        .login-input-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(60,80,224,.18);
        }
        .login-input-wrap input::placeholder { color: var(--text-muted); }
        .toggle-pass {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px 6px;
            font-size: .85rem;
            line-height: 1;
            transition: color .2s;
        }
        .toggle-pass:hover { color: var(--text-main); }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(60,80,224,.4);
            transition: background .2s, transform .15s, box-shadow .2s;
        }
        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(60,80,224,.45);
        }
        .btn-login:active { transform: translateY(0); }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card">

        <div class="login-logo">
            <img src="../assets/img/logo.png" alt="Imperio Comercial" class="login-logo-img">
            <h1>Bienvenido</h1>
        </div>

        <?php if ($error): ?>
            <div style="background:rgba(211,64,83,.1);border:1px solid rgba(211,64,83,.3);border-radius:6px;
                        padding:11px 14px;font-size:.85rem;color:#fb7185;margin-bottom:18px;display:flex;
                        align-items:center;gap:8px">
                <i class="fa fa-exclamation-circle"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <div class="login-form-group">
                <label for="usuario">Usuario</label>
                <div class="login-input-wrap">
                    <i class="fa fa-user input-icon"></i>
                    <input type="text" id="usuario" name="usuario"
                           value="<?= e($_POST['usuario'] ?? '') ?>"
                           placeholder="Nombre de usuario" autofocus required>
                </div>
            </div>

            <div class="login-form-group">
                <label for="password">Contraseña</label>
                <div class="login-input-wrap">
                    <i class="fa fa-lock input-icon"></i>
                    <input type="password" id="password" name="password"
                           placeholder="••••••••" required style="padding-right:40px">
                    <button type="button" class="toggle-pass" onclick="togglePass()">
                        <i class="fa fa-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login">
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
