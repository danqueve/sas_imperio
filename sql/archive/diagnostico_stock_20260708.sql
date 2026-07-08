-- ============================================================
-- diagnostico_stock_20260708.sql
-- Ejecutar en phpMyAdmin para ver el estado real del stock vs
-- créditos activos por artículo.
-- ============================================================

-- 1. RESUMEN: stock actual vs créditos activos (EN_CURSO o MOROSO)
SELECT
    a.id,
    a.descripcion,
    a.stock                               AS stock_actual,
    COALESCE(cr.activos, 0)              AS creditos_activos,
    a.stock + COALESCE(cr.activos, 0)    AS stock_estimado_inicial
FROM ic_articulos a
LEFT JOIN (
    SELECT articulo_id, SUM(activos) AS activos
    FROM (
        SELECT articulo_id, COUNT(*) AS activos
        FROM ic_creditos
        WHERE articulo_id IS NOT NULL AND estado IN ('EN_CURSO','MOROSO')
        GROUP BY articulo_id
        UNION ALL
        SELECT ca.articulo_id, COUNT(DISTINCT ca.credito_id) AS activos
        FROM ic_credito_articulos ca
        JOIN ic_creditos c ON c.id = ca.credito_id
        WHERE ca.articulo_id IS NOT NULL AND c.estado IN ('EN_CURSO','MOROSO')
        GROUP BY ca.articulo_id
    ) cr_raw
    GROUP BY articulo_id
) cr ON cr.articulo_id = a.id
WHERE a.activo = 1
ORDER BY creditos_activos DESC, a.descripcion;

-- ============================================================
-- 2. DETALLE: créditos activos por artículo (qué clientes tienen cada artículo)
SELECT
    a.descripcion                              AS articulo,
    cl.apellidos, cl.nombres, cl.dni,
    cr.estado                                  AS estado_credito,
    cr.fecha_alta,
    cr.monto_total
FROM ic_creditos cr
JOIN ic_articulos a  ON a.id  = cr.articulo_id
JOIN ic_clientes  cl ON cl.id = cr.cliente_id
WHERE cr.articulo_id IS NOT NULL
  AND cr.estado IN ('EN_CURSO','MOROSO')
ORDER BY a.descripcion, cr.fecha_alta DESC;

-- ============================================================
-- 3. NOTA: créditos SIN link a catálogo (no afectaron stock)
--    Estos son créditos legacy cargados antes de que existiera el catálogo.
--    No se pueden reconciliar automáticamente sin saber el stock inicial.
SELECT COUNT(*) AS creditos_sin_articulo_id
FROM ic_creditos
WHERE articulo_id IS NULL;
