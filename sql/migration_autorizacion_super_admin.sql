-- migration_autorizacion_super_admin.sql
-- Nivel "super admin" dentro del rol admin + tabla de solicitudes de
-- autorizacion para acciones grandes (revertir pago confirmado,
-- refinanciar credito). Ver plan: "Autorizacion de super admin para
-- revertir pagos y refinanciar creditos".
--
-- super_admin NO se expone en ningun formulario de la app (admin/usuarios.php
-- no se toca) - se setea unicamente corriendo este UPDATE a mano, para que
-- un admin regular no pueda auto-otorgarse el nivel.

ALTER TABLE ic_usuarios
  ADD COLUMN super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER rol;

UPDATE ic_usuarios SET super_admin = 1 WHERE usuario IN ('danqueve', 'Mohamed');

CREATE TABLE ic_solicitudes_autorizacion (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_accion VARCHAR(40) NOT NULL,        -- 'revertir_pago_confirmado' | 'refinanciar_credito'
  entidad VARCHAR(30) NOT NULL,            -- 'pago_confirmado' | 'credito'
  entidad_id INT NOT NULL,
  payload TEXT NOT NULL,                   -- JSON con los parametros para ejecutar la accion
  motivo TEXT NOT NULL,
  solicitante_id INT NOT NULL,
  estado ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  aprobador_id INT NULL,
  fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_resolucion TIMESTAMP NULL,
  motivo_rechazo TEXT NULL,
  resultado_entidad_id INT NULL,           -- ej: nuevo credito_id creado al aprobar refinanciacion
  KEY idx_estado (estado),
  KEY idx_entidad (entidad, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
