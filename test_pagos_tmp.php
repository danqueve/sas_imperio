<?php
require_once __DIR__ . '/config/conexion.php';
$pdo = obtener_conexion();
$res = $pdo->query("SHOW COLUMNS FROM ic_pagos_temporales")->fetchAll();
print_r(array_column($res, 'Field'));
