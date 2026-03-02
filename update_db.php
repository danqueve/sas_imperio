<?php
require 'config/conexion.php';
$pdo = obtener_conexion();
try {
    $pdo->exec("ALTER TABLE ic_creditos ADD COLUMN motivo_finalizacion ENUM('PAGO_COMPLETO', 'RETIRO_PRODUCTO') DEFAULT NULL AFTER estado;");
    echo "Columna agregada exitosamente.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
