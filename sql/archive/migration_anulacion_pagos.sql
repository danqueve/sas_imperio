-- ============================================================
-- Migración: Gestión de anulación / solicitud de baja de pagos
-- Fecha: 2026-03-03
-- ============================================================

ALTER TABLE ic_pagos_temporales
  ADD COLUMN solicitud_baja TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = supervisor solicitó la baja' AFTER observaciones,
  ADD COLUMN motivo_baja VARCHAR(255) DEFAULT NULL
      AFTER solicitud_baja;

ALTER TABLE ic_pagos_confirmados
  ADD COLUMN solicitud_baja TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = supervisor solicitó reversa' AFTER monto_mora_cobrada,
  ADD COLUMN motivo_baja VARCHAR(255) DEFAULT NULL
      AFTER solicitud_baja;
