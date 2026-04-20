-- ============================================================
-- Migración: Columnas para seguimiento de refinanciación
-- Ejecutar una sola vez sobre la base de datos existente.
-- MySQL 8.0+ soporta IF NOT EXISTS en ALTER TABLE.
-- ============================================================

-- Verificar antes de ejecutar que las columnas no existan:
-- SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ic_creditos'
-- AND COLUMN_NAME IN ('veces_refinanciado','fecha_ultima_refinanciacion');

ALTER TABLE `ic_creditos`
    ADD COLUMN `veces_refinanciado`        TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Cantidad de veces que el crédito fue refinanciado',
    ADD COLUMN `fecha_ultima_refinanciacion` DATE NULL DEFAULT NULL
        COMMENT 'Fecha de la última refinanciación aplicada';
