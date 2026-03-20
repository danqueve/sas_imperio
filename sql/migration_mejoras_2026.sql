DELIMITER //

DROP PROCEDURE IF EXISTS AddColumnIfNotExists //

CREATE PROCEDURE AddColumnIfNotExists(
    IN p_tableName VARCHAR(255),
    IN p_columnName VARCHAR(255),
    IN p_columnDefinition TEXT,
    IN p_columnComment TEXT
)
BEGIN
    -- Check if the column already exists
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_tableName
          AND COLUMN_NAME = p_columnName
    ) THEN
        -- Construct the ALTER TABLE statement
        SET @sql = CONCAT(
            'ALTER TABLE `', p_tableName, '` ADD COLUMN `', p_columnName, '` ',
            p_columnDefinition
        );
        
        -- Add comment if provided
        IF p_columnComment IS NOT NULL AND p_columnComment != '' THEN
            SET @sql = CONCAT(@sql, ' COMMENT ''', REPLACE(p_columnComment, '''', ''''''), '''');
        END IF;

        -- Prepare and execute the statement
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

-- ============================================================
-- Sistema Imperio Comercial — Migración de Mejoras 2026
-- Ejecutar UNA SOLA VEZ en producción y desarrollo
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── A1. Campo fecha_finalizacion en ic_creditos ───────────────
CALL AddColumnIfNotExists(
  'ic_creditos',
  'fecha_finalizacion',
  'DATE NULL DEFAULT NULL',
  'Fecha en que se finalizó el crédito (manual o automática)'
);

-- ── A3. Ampliar ENUM motivo_finalizacion ──────────────────────
ALTER TABLE `ic_creditos`
  MODIFY COLUMN `motivo_finalizacion` 
    ENUM(
      'PAGO_COMPLETO',
      'PAGO_COMPLETO_CON_MORA',
      'RETIRO_PRODUCTO',
      'INCOBRABILIDAD',
      'ACUERDO_EXTRAJUDICIAL'
    ) DEFAULT NULL;

-- ── A2. Campos de puntaje en ic_clientes ─────────────────────
CALL AddColumnIfNotExists(
  'ic_clientes',
  'puntaje_pago',
  'TINYINT UNSIGNED NULL DEFAULT NULL',
  '1=Excelente, 2=Bueno, 3=Regular, 4=Malo. Calculado automáticamente.'
);
CALL AddColumnIfNotExists(
  'ic_clientes',
  'total_creditos_finalizados',
  'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
  'Cantidad total de créditos finalizados (PAGO_COMPLETO o PAGO_COMPLETO_CON_MORA)'
);
CALL AddColumnIfNotExists(
  'ic_clientes',
  'creditos_sin_mora',
  'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
  'Cantidad de créditos finalizados sin mora en ninguna cuota'
);

-- ── A4. Tabla ic_notas_credito ────────────────────────────────
CREATE TABLE IF NOT EXISTS `ic_notas_credito` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `credito_id`  INT NOT NULL,
  `usuario_id`  INT NOT NULL,
  `nota`        TEXT NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`credito_id`) REFERENCES `ic_creditos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `ic_usuarios`(`id`) ON DELETE CASCADE,
  INDEX `idx_notas_credito` (`credito_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
  COMMENT='Notas internas por crédito, visibles solo para admin y supervisores';

SET FOREIGN_KEY_CHECKS = 1;

-- Clean up the procedure after use
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;
