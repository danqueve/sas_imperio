-- =============================================================
-- fix_parcial_pagada.sql
-- Corrige cuotas que quedaron en estado PARCIAL por error en el
-- cálculo de mora al aprobar (fallback usaba fecha actual en vez
-- de fecha_jornada del cobrador).
-- Caso: saldo_pagado >= monto_cuota + monto_mora → debe ser PAGADA.
--
-- Ejecutar UNA VEZ en la base de datos sas_imperio.
-- =============================================================

START TRANSACTION;

-- 1. Ver cuántas cuotas se van a corregir (preview)
SELECT
    cu.id,
    cu.numero_cuota,
    cu.monto_cuota,
    cu.monto_mora,
    cu.saldo_pagado,
    ROUND(cu.monto_cuota + COALESCE(cu.monto_mora, 0), 2) AS total_esperado,
    cr.id                                                  AS credito_id,
    cl.nombres, cl.apellidos
FROM ic_cuotas cu
JOIN ic_creditos cr ON cu.credito_id = cr.id
JOIN ic_clientes cl ON cr.cliente_id = cl.id
WHERE cu.estado = 'PARCIAL'
  AND cu.saldo_pagado >= ROUND(cu.monto_cuota + COALESCE(cu.monto_mora, 0), 2) - 0.005
ORDER BY cu.id;

-- 2. Corregir estado de las cuotas
UPDATE ic_cuotas
SET
    estado     = 'PAGADA',
    fecha_pago = COALESCE(fecha_pago,
                     (SELECT MAX(pc.fecha_pago)
                      FROM ic_pagos_confirmados pc
                      WHERE pc.cuota_id = ic_cuotas.id),
                     CURDATE())
WHERE estado = 'PARCIAL'
  AND saldo_pagado >= ROUND(monto_cuota + COALESCE(monto_mora, 0), 2) - 0.005;

-- 3. Recalcular estado de créditos afectados
--    → FINALIZADO si ya no quedan cuotas pendientes
UPDATE ic_creditos cr
SET cr.estado = 'FINALIZADO'
WHERE cr.estado != 'CANCELADO'
  AND NOT EXISTS (
      SELECT 1 FROM ic_cuotas cu
      WHERE cu.credito_id = cr.id
        AND cu.estado NOT IN ('PAGADA', 'CANCELADA')
  )
  AND EXISTS (
      SELECT 1 FROM ic_cuotas cu
      WHERE cu.credito_id = cr.id
  );

COMMIT;

-- 4. Verificación post-fix
SELECT
    'Cuotas corregidas' AS resumen,
    COUNT(*)            AS cantidad
FROM ic_cuotas
WHERE estado = 'PAGADA'
  AND fecha_pago >= CURDATE() - INTERVAL 1 MINUTE;
