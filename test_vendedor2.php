<?php
require_once __DIR__ . '/config/conexion.php';
$pdo = obtener_conexion();
$res = $pdo->query("SHOW TABLES LIKE 'ic_vendedores'")->fetchAll();
print_r($res);
