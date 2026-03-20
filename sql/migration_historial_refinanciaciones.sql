-- ============================================================
-- Sistema Imperio Comercial — Migración: Historial Refinanciaciones
-- Ejecutar UNA SOLA VEZ en producción y desarrollo
-- ============================================================

CREATE TABLE IF NOT EXISTS `ic_historial_refinanciaciones` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `credito_id`           INT NOT NULL,
  `usuario_id`           INT NOT NULL,
  `fecha`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cuotas_anteriores`    INT NOT NULL COMMENT 'Cantidad de cuotas antes de refinanciar',
  `monto_cuota_anterior` DECIMAL(12,2) NOT NULL COMMENT 'Valor de cuota antes de refinanciar',
  `cuotas_nuevas`        INT NOT NULL COMMENT 'Nueva cantidad de cuotas',
  `monto_cuota_nueva`    DECIMAL(12,2) NOT NULL COMMENT 'Nuevo valor de cuota',
  `deuda_capital`        DECIMAL(12,2) NOT NULL COMMENT 'Capital refinanciado (sin mora)',
  `frecuencia_nueva`     ENUM('semanal','quincenal','mensual') NOT NULL,
  `observaciones`        TEXT NULL COMMENT 'Motivo u observaciones de la refinanciación',
  FOREIGN KEY (`credito_id`) REFERENCES `ic_creditos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `ic_usuarios`(`id`),
  INDEX `idx_histref_credito` (`credito_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
  COMMENT='Auditoría de cada refinanciación realizada sobre un crédito';
