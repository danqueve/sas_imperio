-- ============================================================
-- Migración: desglose Efectivo/Transferencia en ic_historial_metas
-- Ya aplicada en local; queda versionada para aplicar en otros
-- entornos (ej. producción/VPS).
-- ============================================================

ALTER TABLE ic_historial_metas
  ADD COLUMN cobrado_efectivo DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cobrado_semanal_puro,
  ADD COLUMN cobrado_transferencia DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cobrado_efectivo;
