-- ============================================================
-- Migración: Sistema de Tickets (Mesa de Ayuda Interna)
-- Versión: 1.0 — Marzo 2026
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Tabla ic_tickets
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_tickets` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `titulo`               VARCHAR(200) NOT NULL,
  `descripcion`          TEXT NOT NULL,
  `creado_por`           INT NOT NULL,
  `delegado_a_usuario`   INT NULL,
  `delegado_a_rol`       ENUM('admin','supervisor','cobrador','vendedor') NULL,
  `estado`               ENUM('abierto','en_progreso','resuelto') NOT NULL DEFAULT 'abierto',
  `prioridad`            ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  `created_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`creado_por`)         REFERENCES `ic_usuarios`(`id`),
  FOREIGN KEY (`delegado_a_usuario`) REFERENCES `ic_usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ------------------------------------------------------------
-- Tabla ic_ticket_respuestas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ic_ticket_respuestas` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id`  INT NOT NULL,
  `usuario_id` INT NOT NULL,
  `mensaje`    TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`)  REFERENCES `ic_tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `ic_usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

SET FOREIGN_KEY_CHECKS = 1;
