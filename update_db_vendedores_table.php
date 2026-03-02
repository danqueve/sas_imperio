<?php
require 'config/conexion.php';
$pdo = obtener_conexion();
try {
    // 1. Crear tabla ic_vendedores
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ic_vendedores` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `nombre` VARCHAR(100) NOT NULL,
      `apellido` VARCHAR(100) NOT NULL,
      `telefono` VARCHAR(20),
      `activo` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;");
    echo "Tabla ic_vendedores creada.\n";

    // 2. Modificar foreign key en ic_creditos
    // Primero, eliminamos la restricción actual si existe
    $pdo->exec("ALTER TABLE ic_creditos DROP FOREIGN KEY fk_creditos_vendedor;");
    
    // Ahora, agregamos la nueva restricción apuntando a ic_vendedores
    $pdo->exec("ALTER TABLE ic_creditos ADD CONSTRAINT `fk_creditos_vendedor` FOREIGN KEY (`vendedor_id`) REFERENCES `ic_vendedores`(`id`) ON DELETE SET NULL;");
    echo "Foreign key en ic_creditos actualizada.\n";

    // 3. Revertir rol 'vendedor' de ic_usuarios
    $pdo->exec("ALTER TABLE ic_usuarios MODIFY COLUMN `rol` ENUM('admin','supervisor','cobrador') NOT NULL;");
    echo "Rol 'vendedor' removido de ic_usuarios.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
