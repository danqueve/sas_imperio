-- ============================================================
-- Migración: Soporte para reversa lógica de rendiciones aprobadas
-- Fecha: 2026-05-10
-- ============================================================

ALTER TABLE ic_pagos_confirmados
  ADD COLUMN revertido     TINYINT(1)   NOT NULL DEFAULT 0   AFTER origen,
  ADD COLUMN fecha_reversa DATETIME     NULL                  AFTER revertido,
  ADD COLUMN reverso_por   INT          NULL                  AFTER fecha_reversa,
  ADD COLUMN motivo_reversa VARCHAR(255) NULL                 AFTER reverso_por,
  ADD INDEX  idx_revertido (revertido, fecha_aprobacion);

-- Ampliar ENUM para permitir estado REVERTIDO en temporales
ALTER TABLE ic_pagos_temporales
  MODIFY estado ENUM('PENDIENTE','APROBADO','RECHAZADO','REVERTIDO') NOT NULL DEFAULT 'PENDIENTE';
