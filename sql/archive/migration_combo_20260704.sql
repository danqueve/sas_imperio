-- Migración: Crédito Combo — tabla ic_credito_articulos — 2026-07-04
-- Ejecutar UNA SOLA VEZ en local y producción

CREATE TABLE IF NOT EXISTS `ic_credito_articulos` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `credito_id`      INT NOT NULL,
  `articulo_id`     INT NULL,
  `descripcion`     VARCHAR(255) NOT NULL,
  `cantidad`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `precio_unitario` DECIMAL(12,2) NOT NULL,
  `subtotal`        DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`credito_id`)  REFERENCES `ic_creditos`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`articulo_id`) REFERENCES `ic_articulos`(`id`) ON DELETE SET NULL,
  INDEX `idx_ca_credito` (`credito_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
  COMMENT='Detalle de artículos de créditos combo (uno o varios ítems por crédito)';
