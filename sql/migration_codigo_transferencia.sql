-- ============================================================
-- Migración: código de operación obligatorio para transferencias
-- del cobrador (ic_pagos_temporales / ic_pagos_confirmados)
--
-- NOTA: esta migración ya fue aplicada manualmente en la base local
-- (c2881399_credit) antes de que se escribiera este archivo. Se deja
-- versionada acá para poder aplicarla en otros entornos (ej. producción).
-- No se ejecuta automáticamente por ningún script del proyecto.
-- ============================================================

ALTER TABLE ic_pagos_temporales
  ADD COLUMN codigo_transferencia VARCHAR(5) NULL DEFAULT NULL
    COMMENT 'Últimos 5 caracteres del nro de operación (obligatorio para cobradores con transferencia)'
    AFTER monto_transferencia;

ALTER TABLE ic_pagos_temporales
  ADD COLUMN codigo_transf_activo VARCHAR(5)
    GENERATED ALWAYS AS (
      CASE WHEN estado IN ('PENDIENTE','APROBADO')
           THEN codigo_transferencia
           ELSE NULL END
    ) STORED,
  ADD UNIQUE KEY uq_codigo_transf_activo (codigo_transf_activo);

ALTER TABLE ic_pagos_confirmados
  ADD COLUMN codigo_transferencia VARCHAR(5) NULL DEFAULT NULL
    AFTER monto_transferencia;
