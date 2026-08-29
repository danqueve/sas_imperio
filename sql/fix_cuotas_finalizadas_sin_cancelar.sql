-- ============================================================
-- fix_cuotas_finalizadas_sin_cancelar.sql
-- Arreglo de datos de una sola vez (no es migración de esquema).
--
-- Contexto: creditos/finalizar.php cancela las cuotas sin cobrar de un
-- crédito cuando se lo finaliza por RETIRO_PRODUCTO / INCOBRABILIDAD /
-- FINALIZADO_CREDITO (y, desde este mismo cambio, también
-- ACUERDO_EXTRAJUDICIAL). Se encontraron créditos ya FINALIZADO, de
-- antes de que esa lógica existiera, cuyas cuotas sin cobrar quedaron
-- "colgadas" sin cancelar nunca — invisibles para todos los reportes.
--
-- Verificado contra la base real antes de escribir este archivo:
--   - RETIRO_PRODUCTO:  272 cuotas, $18.128.799,98, 20 créditos, con
--     saldo_pagado = 0 en el 100% de los casos (nunca se cobraron).
--   - INCOBRABILIDAD:   169 cuotas, $8.721.060,25,  22 créditos, mismo
--     patrón (saldo_pagado = 0 en todas).
--   - ACUERDO_EXTRAJUDICIAL: 44 cuotas, $2.371.057,60, 3 créditos,
--     quedaron VENCIDA para siempre (este motivo nunca canceló nada,
--     bug aparte ya corregido en creditos/finalizar.php).
--
-- Excluye a propósito el crédito id=200 (motivo PAGO_COMPLETO, 16
-- cuotas / $592.000 con estado vacío): ese caso NO es "cuota sin
-- cancelar", es un crédito que parece mal cerrado (marcado "Pago
-- completo" pero solo se cobraron 4 de sus 20 cuotas) — queda para que
-- el usuario lo revise puntualmente, no se toca acá.
--
-- Correr primero el SELECT de verificación, confirmar los conteos,
-- recién ahí correr los 2 UPDATE. Repetir el SELECT después: debe dar
-- 0 filas en los grupos 1 y 2, y 16 filas restantes (las del crédito
-- 200, intactas).
-- ============================================================

-- Verificación ANTES
SELECT cr.motivo_finalizacion, cu.estado, COUNT(*) AS cant_cuotas,
       SUM(cu.monto_cuota - cu.saldo_pagado) AS saldo_restante
FROM ic_cuotas cu
JOIN ic_creditos cr ON cr.id = cu.credito_id
WHERE cr.estado = 'FINALIZADO'
  AND (
        (cr.motivo_finalizacion IN ('RETIRO_PRODUCTO', 'INCOBRABILIDAD') AND cu.estado = '')
     OR (cr.motivo_finalizacion = 'ACUERDO_EXTRAJUDICIAL' AND cu.estado IN ('VENCIDA', 'PENDIENTE', 'PARCIAL', 'CAP_PAGADA'))
  )
GROUP BY cr.motivo_finalizacion, cu.estado;

-- Grupo 1 y 2: RETIRO_PRODUCTO / INCOBRABILIDAD con estado vacío,
-- saldo_pagado=0 en el 100% de los casos (verificado) — nunca se cobraron.
UPDATE ic_cuotas cu
JOIN ic_creditos cr ON cr.id = cu.credito_id
SET cu.estado = 'CANCELADA'
WHERE cr.estado = 'FINALIZADO'
  AND cr.motivo_finalizacion IN ('RETIRO_PRODUCTO', 'INCOBRABILIDAD')
  AND cu.estado = ''
  AND cr.id <> 200;

-- Grupo 3: ACUERDO_EXTRAJUDICIAL, cuotas nunca canceladas al finalizar
UPDATE ic_cuotas cu
JOIN ic_creditos cr ON cr.id = cu.credito_id
SET cu.estado = 'CANCELADA'
WHERE cr.estado = 'FINALIZADO'
  AND cr.motivo_finalizacion = 'ACUERDO_EXTRAJUDICIAL'
  AND cu.estado IN ('VENCIDA', 'PENDIENTE', 'PARCIAL', 'CAP_PAGADA');

-- Verificación DESPUÉS — debe devolver solo la fila del crédito 200
-- (PAGO_COMPLETO, estado vacío, 16 cuotas) sin tocar.
SELECT cr.id AS credito_id, cr.motivo_finalizacion, cu.estado, COUNT(*) AS cant_cuotas,
       SUM(cu.monto_cuota - cu.saldo_pagado) AS saldo_restante
FROM ic_cuotas cu
JOIN ic_creditos cr ON cr.id = cu.credito_id
WHERE cr.estado = 'FINALIZADO' AND cu.estado = ''
GROUP BY cr.id, cr.motivo_finalizacion, cu.estado;
