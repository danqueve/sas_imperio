-- ============================================================
-- migration_semana_lunes.sql
-- Semana de cobro real: agrega semana_lunes a ic_pagos_confirmados
-- y actualiza ic_liquidaciones para cubrir Lunes → Domingo.
-- ============================================================

-- 1. Agregar columna semana_lunes a ic_pagos_confirmados
ALTER TABLE `ic_pagos_confirmados`
  ADD COLUMN `semana_lunes` DATE NULL
    COMMENT 'Lunes que inicia la semana de cobro de este pago (Dom→lunes anterior, Lun-Sab→lunes de esa semana)'
    AFTER `fecha_jornada`;

-- 2. Backfill: calcular semana_lunes para todos los registros existentes
--    Regla: si fecha_jornada es domingo (DAYOFWEEK=1) → lunes anterior
--           si es lunes-sábado (DAYOFWEEK 2-7) → lunes de esa semana
UPDATE `ic_pagos_confirmados`
SET `semana_lunes` = CASE
    WHEN DAYOFWEEK(`fecha_jornada`) = 1
        -- Domingo → retroceder 6 días al lunes anterior
        THEN DATE_SUB(`fecha_jornada`, INTERVAL 6 DAY)
    ELSE
        -- Lunes-Sábado → restar (DAYOFWEEK-2) días para llegar al lunes
        DATE_SUB(`fecha_jornada`, INTERVAL (DAYOFWEEK(`fecha_jornada`) - 2) DAY)
    END
WHERE `fecha_jornada` IS NOT NULL;

-- Para registros sin fecha_jornada: usar fecha_pago como fallback
UPDATE `ic_pagos_confirmados`
SET `semana_lunes` = CASE
    WHEN DAYOFWEEK(`fecha_pago`) = 1
        THEN DATE_SUB(`fecha_pago`, INTERVAL 6 DAY)
    ELSE
        DATE_SUB(`fecha_pago`, INTERVAL (DAYOFWEEK(`fecha_pago`) - 2) DAY)
    END
WHERE `fecha_jornada` IS NULL AND `semana_lunes` IS NULL;

-- 3. Agregar índice para queries de liquidación
ALTER TABLE `ic_pagos_confirmados`
  ADD INDEX `idx_semana_cobrador` (`semana_lunes`, `cobrador_id`);

-- 4. Actualizar ic_liquidaciones existentes: extender fecha_hasta a domingo
--    (de sábado +1 día → domingo, sin tocar liquidaciones que ya son Lun-Dom)
UPDATE `ic_liquidaciones`
SET `fecha_hasta` = DATE_ADD(`fecha_hasta`, INTERVAL 1 DAY)
WHERE DAYOFWEEK(`fecha_hasta`) = 7   -- fecha_hasta era sábado
  AND DAYOFWEEK(`fecha_desde`)  = 2; -- fecha_desde era lunes (sanity check)

-- Verificación post-migración:
-- SELECT semana_lunes, COUNT(*) AS pagos
-- FROM ic_pagos_confirmados
-- GROUP BY semana_lunes
-- ORDER BY semana_lunes DESC
-- LIMIT 20;
