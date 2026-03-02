<?php
require 'config/conexion.php';
$pdo = obtener_conexion();
$stmt = $pdo->query("DESCRIBE ic_creditos");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
