-- migration_cliente_solicitudes_autorizacion.sql
-- Guarda el cliente (id + nombre) de una solicitud de autorizacion EN EL
-- MOMENTO EN QUE SE CREA — mismo motivo que detalle_contexto: aprobar
-- una reversion/anulacion borra la fila original, asi que no se puede
-- confiar en volver a consultarla despues para saber de que cliente era.
ALTER TABLE ic_solicitudes_autorizacion
  ADD COLUMN cliente_id INT NULL AFTER detalle_contexto,
  ADD COLUMN cliente_nombre VARCHAR(150) NULL AFTER cliente_id;
