-- ============================================================
-- Migración: historial de cambios de vencimiento de cuotas
-- Ya aplicada en local; queda versionada para aplicar en otros
-- entornos (ej. producción/VPS).
-- ============================================================

CREATE TABLE ic_cuota_vencimiento_historial (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credito_id INT NOT NULL,
  cuota_id INT NOT NULL,
  numero_cuota INT NOT NULL,
  fecha_anterior DATE NOT NULL,
  fecha_nueva DATE NOT NULL,
  dias_shift INT NOT NULL,
  cuotas_desplazadas INT NOT NULL DEFAULT 0,
  motivo TEXT NOT NULL,
  usuario_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_credito (credito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci
  COMMENT='Historial de reprogramaciones de vencimiento por cuota, con motivo y usuario responsable';
