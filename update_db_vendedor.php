<?php
require 'config/conexion.php';
$pdo = obtener_conexion();
try {
    // 1. Modificar ENUM de rol
    $pdo->exec("ALTER TABLE ic_usuarios MODIFY COLUMN `rol` ENUM('admin','supervisor','cobrador','vendedor') NOT NULL;");
    echo "Rol actualizado.\n";
    // 2. Agregar columna vendedor_id a ic_creditos
    $pdo->exec("ALTER TABLE ic_creditos ADD COLUMN `vendedor_id` INT NULL AFTER `cobrador_id`;");
    $pdo->exec("ALTER TABLE ic_creditos ADD CONSTRAINT `fk_creditos_vendedor` FOREIGN KEY (`vendedor_id`) REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL;");
    echo "Columna vendedor_id y relación agregada.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
