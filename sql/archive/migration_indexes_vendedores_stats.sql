-- ============================================================
-- Migración: Índices para estadísticas de vendedores
-- Fecha: 2026-05-11
-- Mejora el rendimiento de las queries en vendedores/estadisticas.php
-- ============================================================

-- Índice compuesto para filtrar por vendedor + fecha_alta + estado
ALTER TABLE ic_creditos
  ADD INDEX idx_creditos_vendedor_fecha (vendedor_id, fecha_alta, estado);

-- Índice en cuotas para la subquery de aging (fecha_vencimiento + estado)
-- Nota: idx_cuotas_credito_estado ya existe en schema.sql
-- Este índice apoya los filtros de vencimiento en la subquery de aging
ALTER TABLE ic_cuotas
  ADD INDEX idx_cuotas_vencimiento_estado (fecha_vencimiento, estado);
