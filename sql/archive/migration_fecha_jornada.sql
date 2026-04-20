-- ============================================================
-- Migration: fecha_jornada en ic_pagos_temporales
-- Corte de jornada: 10:00 AM
-- Si el pago se registra antes de las 10:00 AM, pertenece
-- a la jornada del día anterior (el cobrador estaba trabajando
-- en la madrugada del día previo).
-- ============================================================

-- 1. Agregar la columna
ALTER TABLE ic_pagos_temporales
  ADD COLUMN fecha_jornada DATE NOT NULL DEFAULT '2000-01-01' AFTER fecha_registro;

-- 2. Backfill: calcular fecha_jornada para los registros existentes
--    Corte: HOUR < 10  → jornada = día anterior
--           HOUR >= 10 → jornada = mismo día
UPDATE ic_pagos_temporales
SET fecha_jornada = CASE
    WHEN HOUR(fecha_registro) < 10
        THEN DATE(DATE_SUB(fecha_registro, INTERVAL 1 DAY))
    ELSE DATE(fecha_registro)
END;

-- 3. Índice para performance en las queries de rendiciones
CREATE INDEX idx_jornada_cobrador_estado
  ON ic_pagos_temporales (fecha_jornada, cobrador_id, estado);
