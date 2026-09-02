-- ============================================================
-- migration_cupones_pago.sql
-- Cupón de pago generado al registrar un cobro (cobrador/registrar_pago.php)
-- Indexa cada cupón emitido (PDF guardado en cupones_pago/AAAA/MM/DD/)
-- para poder buscarlo después desde cobrador/cupones.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS ic_cupones_pago (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credito_id INT NOT NULL,
  cliente_id INT NOT NULL,
  cobrador_id INT NOT NULL,
  fecha_jornada DATE NOT NULL,
  pago_temp_ids VARCHAR(255) NOT NULL,
  monto_total DECIMAL(12,2) NOT NULL,
  cant_cuotas TINYINT UNSIGNED NOT NULL,
  ruta_archivo VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cobrador_fecha (cobrador_id, fecha_jornada),
  KEY idx_cliente (cliente_id),
  KEY idx_credito (credito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
