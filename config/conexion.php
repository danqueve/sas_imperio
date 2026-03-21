<?php
// ============================================================
// Sistema Imperio Comercial — Configuración y Conexión PDO
// ============================================================

define('BASE_URL', '/creditos/');
define('APP_URL',  'https://imperiocomercial.com.ar/creditos/');


define('DB_HOST', 'localhost');
define('DB_NAME', 'c2881399_credit');       // Nombre de la base de datos
define('DB_USER', 'c2881399_credit');                  // Usuario MySQL
define('DB_PASS', '69maninoNO');                      // Contraseña MySQL
define('DB_CHARSET', 'utf8mb4');

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
        } catch (PDOException $e) {
            error_log('PDO Connection Error: ' . $e->getMessage());
            http_response_code(500);
            die('Error de conexion a la base de datos. Contacte al administrador.');
        }
    }
    return $pdo;
}
