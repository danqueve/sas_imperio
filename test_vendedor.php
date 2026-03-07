<?php
require_once __DIR__ . '/config/conexion.php';
$pdo = obtener_conexion();
$res = $pdo->query("SELECT * FROM ic_usuarios WHERE id=17")->fetch(PDO::FETCH_ASSOC);
print_r($res);
