-- migration_tickets_reclamos.sql
-- Repurpone ic_tickets (mesa de ayuda interna) en "Reclamos de crédito"
-- y "Posventa de artículos a reparar", siempre ligados a un cliente y
-- a uno de sus créditos.
--
-- Requiere confirmar antes de correr que ic_tickets está vacía (o que
-- se acepta perder delegado_a_usuario/delegado_a_rol de las filas que
-- tenga) — en el entorno local se corrió con la tabla en 0 filas.
--
-- Si el nombre de la FK de delegado_a_usuario difiere en tu entorno,
-- confirmalo antes con:
--   SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_NAME='ic_tickets' AND TABLE_SCHEMA=DATABASE()
--     AND COLUMN_NAME='delegado_a_usuario';
-- (en local es "ic_tickets_ibfk_2").

ALTER TABLE ic_tickets
  DROP FOREIGN KEY ic_tickets_ibfk_2,
  DROP COLUMN delegado_a_usuario,
  DROP COLUMN delegado_a_rol,
  ADD COLUMN tipo ENUM('reclamo','posventa') NOT NULL AFTER id,
  ADD COLUMN cliente_id INT NOT NULL AFTER tipo,
  ADD COLUMN credito_id INT NOT NULL AFTER cliente_id,
  ADD CONSTRAINT fk_tickets_cliente FOREIGN KEY (cliente_id) REFERENCES ic_clientes(id),
  ADD CONSTRAINT fk_tickets_credito FOREIGN KEY (credito_id) REFERENCES ic_creditos(id),
  ADD INDEX idx_tickets_cliente (cliente_id),
  ADD INDEX idx_tickets_credito (credito_id),
  ADD INDEX idx_tickets_estado_tipo (estado, tipo);
