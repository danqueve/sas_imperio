-- ============================================================
-- Migración: agrega Localidad y Barrio al cliente
-- Mismo tipo/collation que ic_garantes.localidad (precedente ya
-- existente). Sin índice, igual que zona.
-- ============================================================

ALTER TABLE ic_clientes
  ADD COLUMN localidad VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL AFTER direccion,
  ADD COLUMN barrio    VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci DEFAULT NULL AFTER localidad;
