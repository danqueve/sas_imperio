-- ============================================================
-- Sistema Imperio Comercial — Schema MySQL
-- Versión: 1.1 — Marzo 2026
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Tabla ic_usuarios
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `apellido` VARCHAR(100) NOT NULL,
  `usuario` VARCHAR(60) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `rol` ENUM('admin','supervisor','cobrador','vendedor') NOT NULL,
  `telefono` VARCHAR(20),
  `zona` VARCHAR(60),
  `activo` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Usuario administrador por defecto: usuario=admin / contraseña=password  ← CAMBIAR EN PRODUCCIÓN
INSERT IGNORE INTO `ic_usuarios` (`nombre`, `apellido`, `usuario`, `password_hash`, `rol`)
VALUES ('Administrador', 'Sistema', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- ------------------------------------------------------------
-- Tabla ic_clientes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_clientes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombres` VARCHAR(100) NOT NULL,
  `apellidos` VARCHAR(100) NOT NULL,
  `dni` VARCHAR(20),
  `cuil` VARCHAR(20),
  `telefono` VARCHAR(20) NOT NULL,
  `telefono_alt` VARCHAR(20),
  `fecha_nacimiento` DATE,
  `direccion` TEXT,
  `direccion_laboral` TEXT,
  `coordenadas` VARCHAR(60),
  `cobrador_id` INT,
  `dia_cobro` TINYINT COMMENT '1=Lun,2=Mar,3=Mie,4=Jue,5=Vie,6=Sab',
  `zona` VARCHAR(60),
  `estado` ENUM('ACTIVO','INACTIVO','MOROSO') DEFAULT 'ACTIVO',
  `token_acceso` VARCHAR(64) UNIQUE,
  `tiene_garante` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`cobrador_id`) REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL,
  INDEX `idx_clientes_cobrador` (`cobrador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_garantes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_garantes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cliente_id` INT NOT NULL,
  `nombres` VARCHAR(100) NOT NULL,
  `apellidos` VARCHAR(100) NOT NULL,
  `dni` VARCHAR(20),
  `telefono` VARCHAR(20),
  `direccion` TEXT,
  `coordenadas` VARCHAR(60),
  FOREIGN KEY (`cliente_id`) REFERENCES `ic_clientes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_vendedores
-- usuario_id: FK nullable al usuario con rol=vendedor (auto-creado al crear usuario)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_vendedores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NULL COMMENT 'FK a ic_usuarios (rol=vendedor). NULL = vendedor sin login.',
  `nombre` VARCHAR(100) NOT NULL,
  `apellido` VARCHAR(100) NOT NULL,
  `telefono` VARCHAR(20),
  `activo` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`usuario_id`) REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_articulos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_articulos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(60) NULL COMMENT 'Código interno único. Se usa en QR.',
  `descripcion` VARCHAR(200) NOT NULL,
  `precio_costo` DECIMAL(12,2),
  `precio_venta` DECIMAL(12,2) COMMENT 'Precio base (crédito)',
  `precio_contado` DECIMAL(12,2) COMMENT 'Precio venta contado',
  `precio_tarjeta` DECIMAL(12,2) COMMENT 'Precio venta tarjeta',
  `stock` INT DEFAULT 0,
  `categoria` VARCHAR(80),
  `activo` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_creditos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_creditos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cliente_id` INT NOT NULL,
  `articulo_id` INT NULL COMMENT 'NULL en créditos anteriores a v1.1',
  `cobrador_id` INT NOT NULL,
  `vendedor_id` INT NULL,
  `fecha_alta` DATE NOT NULL,
  `precio_articulo` DECIMAL(12,2) NOT NULL,
  `monto_total` DECIMAL(12,2) NOT NULL,
  `interes_pct` DECIMAL(5,2) NOT NULL,
  `interes_moratorio_pct` DECIMAL(5,2) DEFAULT 15.00,
  `frecuencia` ENUM('semanal','quincenal','mensual') NOT NULL,
  `cant_cuotas` INT NOT NULL,
  `monto_cuota` DECIMAL(12,2) NOT NULL,
  `dia_cobro` TINYINT COMMENT 'Solo para frecuencia semanal: 1=Lun…6=Sab',
  `primer_vencimiento` DATE NOT NULL,
  `estado` ENUM('EN_CURSO','FINALIZADO','MOROSO','CANCELADO') DEFAULT 'EN_CURSO',
  `motivo_finalizacion` ENUM('PAGO_COMPLETO','PAGO_COMPLETO_CON_MORA','RETIRO_PRODUCTO','INCOBRABILIDAD','ACUERDO_EXTRAJUDICIAL') DEFAULT NULL,
  `fecha_finalizacion` DATE NULL DEFAULT NULL COMMENT 'Fecha en que se finalizó el crédito',
  `articulo_desc` VARCHAR(255) COMMENT 'Snapshot descripción al momento del crédito',
  `observaciones` TEXT,
  `veces_refinanciado` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `fecha_ultima_refinanciacion` DATE NULL DEFAULT NULL,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`cliente_id`) REFERENCES `ic_clientes`(`id`),
  FOREIGN KEY (`articulo_id`) REFERENCES `ic_articulos`(`id`),
  FOREIGN KEY (`cobrador_id`) REFERENCES `ic_usuarios`(`id`),
  FOREIGN KEY (`vendedor_id`) REFERENCES `ic_vendedores`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL,
  INDEX `idx_creditos_cliente_estado` (`cliente_id`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_cuotas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_cuotas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `credito_id` INT NOT NULL,
  `numero_cuota` INT NOT NULL,
  `fecha_vencimiento` DATE NOT NULL,
  `monto_cuota` DECIMAL(12,2) NOT NULL,
  `monto_mora` DECIMAL(12,2) DEFAULT 0.00,
  `dias_atraso` INT DEFAULT 0 COMMENT 'Campo legacy — no se actualiza automáticamente. Calcular con DATEDIFF(CURDATE(), fecha_vencimiento)',
  `saldo_pagado` DECIMAL(12,2) DEFAULT 0.00,
  `estado` ENUM('PENDIENTE','PAGADA','VENCIDA','PARCIAL','CAP_PAGADA') DEFAULT 'PENDIENTE',
  `fecha_pago` DATE DEFAULT NULL,
  FOREIGN KEY (`credito_id`) REFERENCES `ic_creditos`(`id`) ON DELETE CASCADE,
  INDEX `idx_cuotas_credito_estado` (`credito_id`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_pagos_temporales
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_pagos_temporales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cuota_id` INT NOT NULL,
  `cobrador_id` INT NOT NULL,
  `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `monto_efectivo` DECIMAL(12,2) DEFAULT 0.00,
  `monto_transferencia` DECIMAL(12,2) DEFAULT 0.00,
  `monto_total` DECIMAL(12,2) NOT NULL,
  `monto_mora_cobrada` DECIMAL(12,2) DEFAULT 0.00,
  `mora_congelada` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Mora calculada al momento del registro (se usa en aprobación)',
  `es_cuota_pura` TINYINT(1) DEFAULT 0,
  `observaciones` TEXT,
  `estado` ENUM('PENDIENTE','APROBADO','RECHAZADO') DEFAULT 'PENDIENTE',
  `solicitud_baja` TINYINT(1) DEFAULT 0,
  `motivo_baja` TEXT NULL,
  `fecha_jornada` DATE NULL COMMENT 'Fecha de la jornada de cobranza',
  `origen` ENUM('cobrador','manual') DEFAULT 'cobrador' COMMENT 'Origen del pago: cobrador en campo o manual por admin',
  FOREIGN KEY (`cuota_id`) REFERENCES `ic_cuotas`(`id`),
  FOREIGN KEY (`cobrador_id`) REFERENCES `ic_usuarios`(`id`),
  INDEX `idx_pt_cobrador_jornada` (`cobrador_id`, `fecha_jornada`, `estado`),
  INDEX `idx_pt_cuota_estado` (`cuota_id`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_pagos_confirmados
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_pagos_confirmados` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pago_temp_id` INT NOT NULL,
  `cuota_id` INT NOT NULL,
  `cobrador_id` INT NOT NULL,
  `aprobador_id` INT NOT NULL,
  `fecha_pago` DATE NOT NULL,
  `monto_efectivo` DECIMAL(12,2) DEFAULT 0.00,
  `monto_transferencia` DECIMAL(12,2) DEFAULT 0.00,
  `monto_total` DECIMAL(12,2) NOT NULL,
  `monto_mora_cobrada` DECIMAL(12,2) DEFAULT 0.00,
  `fecha_aprobacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `solicitud_baja` TINYINT(1) DEFAULT 0,
  `motivo_baja` TEXT NULL,
  FOREIGN KEY (`pago_temp_id`) REFERENCES `ic_pagos_temporales`(`id`),
  FOREIGN KEY (`cuota_id`) REFERENCES `ic_cuotas`(`id`),
  FOREIGN KEY (`cobrador_id`) REFERENCES `ic_usuarios`(`id`),
  FOREIGN KEY (`aprobador_id`) REFERENCES `ic_usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_log_actividades
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_log_actividades` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NOT NULL,
  `accion`     VARCHAR(50) NOT NULL,
  `entidad`    VARCHAR(30) NOT NULL,
  `entidad_id` INT NULL,
  `detalle`    VARCHAR(255) NULL,
  `ip`         VARCHAR(45) NULL,
  `fecha`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`usuario_id`) REFERENCES `ic_usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_ventas — Ventas de local (contado / tarjeta)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_ventas` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `articulo_id`   INT NOT NULL,
  `articulo_desc` VARCHAR(255) NOT NULL COMMENT 'Snapshot descripción al momento de la venta',
  `vendedor_id`   INT NOT NULL COMMENT 'FK a ic_vendedores',
  `cantidad`      INT NOT NULL DEFAULT 1,
  `precio_venta`  DECIMAL(12,2) NOT NULL COMMENT 'Precio unitario cobrado',
  `forma_pago`    ENUM('efectivo','tarjeta') NOT NULL DEFAULT 'efectivo',
  `observaciones` TEXT NULL,
  `created_by`    INT NULL COMMENT 'ic_usuarios.id del que registró',
  `fecha_venta`   DATE NOT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`articulo_id`) REFERENCES `ic_articulos`(`id`),
  FOREIGN KEY (`vendedor_id`) REFERENCES `ic_vendedores`(`id`),
  FOREIGN KEY (`created_by`)  REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_historial_refinanciaciones (v1.2)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_historial_refinanciaciones` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `credito_id`           INT NOT NULL,
  `usuario_id`           INT NOT NULL,
  `fecha`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cuotas_anteriores`    INT NOT NULL,
  `monto_cuota_anterior` DECIMAL(12,2) NOT NULL,
  `cuotas_nuevas`        INT NOT NULL,
  `monto_cuota_nueva`    DECIMAL(12,2) NOT NULL,
  `deuda_capital`        DECIMAL(12,2) NOT NULL,
  `frecuencia_nueva`     ENUM('semanal','quincenal','mensual') NOT NULL,
  `observaciones`        TEXT NULL,
  FOREIGN KEY (`credito_id`) REFERENCES `ic_creditos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `ic_usuarios`(`id`),
  INDEX `idx_histref_credito` (`credito_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
  COMMENT='Auditoría de cada refinanciación realizada sobre un crédito';

SET FOREIGN_KEY_CHECKS = 1;
