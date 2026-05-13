-- Migración: snapshot de nombre/apellido del cliente en ic_pagos_confirmados
-- Ejecutar una sola vez sobre la base c2881399_credit

ALTER TABLE ic_pagos_confirmados
  ADD COLUMN cliente_nombres_snap   VARCHAR(150) NULL AFTER articulo_snap,
  ADD COLUMN cliente_apellidos_snap VARCHAR(150) NULL AFTER cliente_nombres_snap;

-- Backfill best-effort para registros históricos existentes
UPDATE ic_pagos_confirmados pc
JOIN ic_cuotas   cu ON cu.id         = pc.cuota_id
JOIN ic_creditos cr ON cr.id         = cu.credito_id
JOIN ic_clientes cl ON cl.id         = cr.cliente_id
SET pc.cliente_nombres_snap   = cl.nombres,
    pc.cliente_apellidos_snap = cl.apellidos
WHERE pc.cliente_nombres_snap IS NULL;
