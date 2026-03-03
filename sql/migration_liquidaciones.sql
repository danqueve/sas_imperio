-- ============================================================
-- Migración: Módulo de Liquidaciones de Cobradores
-- Versión: 2.0 — Marzo 2026
-- ============================================================

-- Tabla principal de liquidaciones semanales
CREATE TABLE IF NOT EXISTS `ic_liquidaciones` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `cobrador_id`      INT NOT NULL,
  `fecha_desde`      DATE NOT NULL COMMENT 'Lunes del período',
  `fecha_hasta`      DATE NOT NULL COMMENT 'Sábado del período',
  `total_cobrado`    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma de ic_pagos_confirmados en el período',
  `comision_pct`     DECIMAL(5,2)  NOT NULL DEFAULT 5.00  COMMENT '% de comisión sobre lo cobrado',
  `comision_monto`   DECIMAL(12,2) NOT NULL DEFAULT 0.00  COMMENT 'comision_pct % de total_cobrado',
  `total_extras`     DECIMAL(12,2) NOT NULL DEFAULT 0.00  COMMENT 'Suma ítems positivos (ventas/bonus)',
  `total_descuentos` DECIMAL(12,2) NOT NULL DEFAULT 0.00  COMMENT 'Suma ítems negativos (gastos/descuentos)',
  `total_neto`       DECIMAL(12,2) NOT NULL DEFAULT 0.00  COMMENT 'comision_monto + extras - descuentos',
  `estado`           ENUM('BORRADOR','APROBADA','PAGADA') NOT NULL DEFAULT 'BORRADOR',
  `observaciones`    TEXT,
  `created_by`       INT NOT NULL,
  `aprobado_by`      INT NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `aprobado_at`      TIMESTAMP NULL,
  `pagado_at`        TIMESTAMP NULL,
  FOREIGN KEY (`cobrador_id`) REFERENCES `ic_usuarios`(`id`),
  FOREIGN KEY (`created_by`)  REFERENCES `ic_usuarios`(`id`),
  FOREIGN KEY (`aprobado_by`) REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Ítems adicionales de cada liquidación
CREATE TABLE IF NOT EXISTS `ic_liquidacion_items` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `liquidacion_id` INT NOT NULL,
  `tipo`           ENUM('venta','bonus','gasto','descuento','otro') NOT NULL,
  `descripcion`    VARCHAR(200) NOT NULL,
  `monto`          DECIMAL(12,2) NOT NULL COMMENT 'Positivo = ingreso; negativo = deducción',
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`liquidacion_id`) REFERENCES `ic_liquidaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
