-- ============================================================
-- migration_meta_objetivo_snapshot.sql
-- Agrega a ic_historial_metas el "objetivo efectivo" de cada snapshot:
-- el override manual del cobrador (ic_usuarios.meta_semanal) si tenía
-- uno cargado al momento del snapshot, o la meta_automatica si no.
--
-- meta_objetivo NULL = snapshot tomado ANTES de esta migración (no se
-- recalcula retroactivamente el pasado); las pantallas de historial
-- deben mostrar meta_automatica como objetivo con la etiqueta
-- "criterio anterior" para esas filas viejas, no inferir un override
-- que no se sabe si existió.
--
-- Aplicar una sola vez por entorno (local y luego VPS).
-- ============================================================

ALTER TABLE ic_historial_metas
  ADD COLUMN meta_objetivo DECIMAL(12,2) NULL AFTER meta_automatica,
  ADD COLUMN origen_meta ENUM('AUTOMATICA','MANUAL') NOT NULL DEFAULT 'AUTOMATICA' AFTER meta_objetivo;
