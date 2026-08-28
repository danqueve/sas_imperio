-- ============================================================
-- migration_objetivo_vendedores.sql
-- Agrega el objetivo de venta mensual por vendedor (no existía
-- ningún campo de meta para vendedores, a diferencia de
-- ic_usuarios.meta_semanal para cobradores).
-- NULL = sin objetivo cargado (se excluye de los cálculos, no se
-- asume un valor por defecto).
-- Aplicar una sola vez por entorno (local y luego VPS).
-- ============================================================

ALTER TABLE ic_vendedores
  ADD COLUMN objetivo_mensual DECIMAL(12,2) DEFAULT NULL AFTER activo;
