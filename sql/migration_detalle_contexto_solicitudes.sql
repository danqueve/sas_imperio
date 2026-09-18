-- migration_detalle_contexto_solicitudes.sql
-- Agrega una columna para guardar el detalle legible (cliente/cuota/monto)
-- de una solicitud de autorización EN EL MOMENTO EN QUE SE CREA.
--
-- Por qué: admin/solicitudes_autorizacion.php reconsultaba la entidad
-- (ic_pagos_confirmados / ic_pagos_temporales) en vivo para armar ese
-- detalle — pero aprobar una reversión o una anulación BORRA esa misma
-- fila, así que después de aprobada la pantalla mostraba "el pago ya no
-- existe" en vez de qué se hizo. Guardar el detalle una sola vez, al
-- crear la solicitud (cuando la entidad todavía existe seguro), lo deja
-- correcto para siempre sin importar qué pase después.
ALTER TABLE ic_solicitudes_autorizacion
  ADD COLUMN detalle_contexto VARCHAR(500) NULL AFTER motivo;
