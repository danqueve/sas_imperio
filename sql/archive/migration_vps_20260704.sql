-- ============================================================
-- Migración VPS — 2026-07-04
-- Ejecutar UNA SOLA VEZ en producción vía phpMyAdmin
-- Orden importa: 1 → 2 → 3
-- ============================================================

-- ── 1. ic_cuotas: agregar estado CANCELADA al ENUM ──────────
--    Fix bug: finalizar crédito con "Retiro de Producto"
--    daba error porque CANCELADA no existía en el ENUM.

ALTER TABLE `ic_cuotas`
  MODIFY COLUMN `estado`
    ENUM('PENDIENTE','PAGADA','VENCIDA','PARCIAL','CAP_PAGADA','CANCELADA')
    DEFAULT 'PENDIENTE';

-- ── 2. Nueva tabla ic_credito_articulos (Crédito Combo) ─────
--    Permite asociar múltiples artículos a un solo crédito.
--    Los créditos simples existentes no se ven afectados.

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
  COMMENT='Detalle de artículos de créditos combo';

-- ── 3. ic_creditos: cambiar DEFAULT de interes_moratorio_pct ─
--    De 15% a 5%. Solo afecta créditos nuevos sin valor explícito.
--    Los créditos existentes conservan su tasa guardada.

ALTER TABLE `ic_creditos`
  ALTER COLUMN `interes_moratorio_pct` SET DEFAULT 5.00;

-- ============================================================
-- FIN — verificar con:
--   SHOW COLUMNS FROM ic_cuotas LIKE 'estado';
--   SHOW TABLES LIKE 'ic_credito_articulos';
--   SHOW COLUMNS FROM ic_creditos LIKE 'interes_moratorio_pct';
-- ============================================================
