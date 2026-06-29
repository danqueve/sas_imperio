-- Migración: Fix error al finalizar crédito — 2026-06-29
-- Agrega estado CANCELADA al ENUM de ic_cuotas.estado
-- Segura para ejecutar en local y producción

ALTER TABLE `ic_cuotas`
  MODIFY COLUMN `estado`
    ENUM('PENDIENTE','PAGADA','VENCIDA','PARCIAL','CAP_PAGADA','CANCELADA')
    DEFAULT 'PENDIENTE';
