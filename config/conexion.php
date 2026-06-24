<?php
// ============================================================
// Sistema Imperio Comercial — Configuración y Conexión PDO
// ============================================================
// Las credenciales se leen de (en orden):
//   1. Variables de entorno (DB_HOST, DB_NAME, DB_USER, DB_PASS)
//   2. config/conexion.local.php (gitignored)
// ============================================================

define('BASE_URL', '/creditos/');
define('APP_URL',  'https://imperiocomercial.com.ar/creditos/');

(function () {
    $env = [
        'host'    => getenv('DB_HOST') ?: null,
        'name'    => getenv('DB_NAME') ?: null,
        'user'    => getenv('DB_USER') ?: null,
        'pass'    => getenv('DB_PASS') ?: null,
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ];

    $local_file = __DIR__ . '/conexion.local.php';
    $local = file_exists($local_file) ? require $local_file : [];

    $cfg = [
        'host'    => $env['host']    ?? ($local['host']    ?? null),
        'name'    => $env['name']    ?? ($local['name']    ?? null),
        'user'    => $env['user']    ?? ($local['user']    ?? null),
        'pass'    => $env['pass']    ?? ($local['pass']    ?? null),
        'charset' => $env['charset'] ?? ($local['charset'] ?? 'utf8mb4'),
    ];

    if (!$cfg['host'] || !$cfg['name'] || !$cfg['user']) {
        http_response_code(500);
        error_log('Faltan credenciales de BD. Definir env vars o crear config/conexion.local.php (ver conexion.example.php).');
        die('Error de configuración: credenciales de BD ausentes. Contacte al administrador.');
    }

    define('DB_HOST',    $cfg['host']);
    define('DB_NAME',    $cfg['name']);
    define('DB_USER',    $cfg['user']);
    define('DB_PASS',    $cfg['pass']);
    define('DB_CHARSET', $cfg['charset']);
})();

function obtener_conexion(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $opciones = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
            // Forzar modo estricto: rechaza ENUM inválidos en lugar de insertar ''
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_TRANS_TABLES')");
        } catch (PDOException $e) {
            error_log('PDO Connection Error: ' . $e->getMessage());
            http_response_code(500);
            die('Error de conexion a la base de datos. Contacte al administrador.');
        }
    }
    return $pdo;
}
