# Plan: Dashboard "Mis Clientes" para Vendedores (solo lectura)

## Contexto

Los vendedores actualmente solo acceden al módulo `ventas/` (sus propias ventas). La tabla `ic_creditos` ya tiene `vendedor_id`, lo que permite construir una cartera de clientes por vendedor. El objetivo es darles un panel donde puedan ver el estado de sus clientes y seguir cómo van los pagos de los créditos que vendieron — **sin capacidad de registrar nada**.

**Relación clave:** `ic_vendedores.usuario_id` → `ic_creditos.vendedor_id` → `ic_clientes.id`

---

## Archivos a crear / modificar

### Nuevos archivos
- `ventas/mis_clientes.php` — Dashboard cartera del vendedor (KPIs + tabla de clientes)
- `ventas/ver_credito.php` — Vista de crédito de solo lectura (cronograma + historial pagos)

### Archivos modificados
- `config/sesion.php` — Agregar permiso `ver_clientes_vendedor`
- `views/layout.php` — Link "Mis Clientes" en sidebar del vendedor

---

## Paso 1: `config/sesion.php`

Agregar en el array `$permisos` después de `'ver_ventas'`:
```php
'ver_clientes_vendedor' => ['admin', 'supervisor', 'vendedor'],
```

---

## Paso 2: `views/layout.php`

En el bloque sidebar del vendedor (actualmente solo "Nueva Venta" y "Mis Ventas"), agregar primero:
```php
<a class="nav-item <?= ($page_current ?? '') === 'mis_clientes' ? 'active' : '' ?>"
   href="<?= BASE_URL ?>ventas/mis_clientes">
    <i class="fa fa-users"></i>
    <span class="nav-text">Mis Clientes</span>
</a>
```

Sin cambios en el sidebar de admin.

---

## Paso 3: `ventas/mis_clientes.php` — Dashboard de cartera

**Auth:** `verificar_permiso('ver_clientes_vendedor')`.

**Resolver `$mi_vendedor_id`** igual que `ventas/index.php`:
```php
$stmt = $pdo->prepare("SELECT id FROM ic_vendedores WHERE usuario_id=? AND activo=1 LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$mi_vendedor_id = $stmt->fetchColumn() ?: null;
```
Si no existe → flash + redirect a `ventas/index`.

**Query KPIs (4 valores en una sola query):**
```sql
SELECT
    COUNT(DISTINCT cr.cliente_id)                          AS total_clientes,
    SUM(cr.estado IN ('EN_CURSO','MOROSO'))                AS creditos_activos,
    SUM(cr.estado = 'MOROSO')                              AS clientes_mora,
    SUM(
        (SELECT COUNT(*) FROM ic_cuotas cu
         WHERE cu.credito_id = cr.id
           AND cu.estado NOT IN ('PAGADA','CANCELADA')) BETWEEN 1 AND 3
    )                                                      AS por_cerrar
FROM ic_creditos cr
WHERE cr.vendedor_id = :vid
  AND cr.estado IN ('EN_CURSO','MOROSO')
```

**Query tabla de clientes (un registro por crédito activo):**
```sql
SELECT
    cl.id AS cliente_id,
    CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente_nombre,
    cl.telefono,
    cr.id AS credito_id,
    cr.estado AS credito_estado,
    cr.cant_cuotas,
    cr.fecha_alta,
    COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
    SUM(cu.estado IN ('PAGADA','CAP_PAGADA'))          AS cuotas_pagadas,
    SUM(cu.estado NOT IN ('PAGADA','CANCELADA'))       AS cuotas_pendientes
FROM ic_creditos cr
JOIN ic_clientes cl  ON cr.cliente_id = cl.id
JOIN ic_cuotas cu    ON cu.credito_id = cr.id
LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
WHERE cr.vendedor_id = :vid AND cr.estado IN ('EN_CURSO','MOROSO')
GROUP BY cr.id, cl.id
ORDER BY cl.apellidos, cl.nombres
```

**UI — KPI cards (Bootstrap 5, 4 columnas, dark mode):**

| Card | Ícono | Color |
|------|-------|-------|
| Total Clientes | `fa-users` | `--primary-light` |
| Créditos Activos | `fa-file-invoice-dollar` | `--success` |
| En Mora | `fa-triangle-exclamation` | `--danger` |
| Por Cerrar (≤3 cuotas) | `fa-hourglass-end` | `--warning` |

**UI — Tabla (`.table-ic`):**

Columnas: **Cliente** | **Artículo** | **Estado** | **Progreso** | **Ver**

- Estado: `badge_estado_credito($row['credito_estado'])`
- Progreso: barra Bootstrap 5 `<div class="progress" style="height:6px">` con `width: (cuotas_pagadas/cant_cuotas * 100)%` + texto `"X/Y cuotas"` al lado
- Ver: `<a href="ver_credito?id={credito_id}" class="btn-ic btn-ghost btn-sm"><i class="fa fa-eye"></i> Ver</a>`
- Sin botón de propuesta ni acción de escritura

**Estado vacío:** si no hay registros, `.card-ic` con mensaje centrado "Aún no tenés clientes con créditos activos."

---

## Paso 4: `ventas/ver_credito.php` — Vista de crédito (solo lectura)

**Auth:** `verificar_permiso('ver_clientes_vendedor')`.

**Security gate (vendedor):** verificar que `cr.vendedor_id = $mi_vendedor_id`; si no → 403.

**Query crédito** (mismo JOIN de `creditos/ver.php`, solo leer):
```sql
SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono, cl.dni, cl.id AS cid,
       COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
       u.nombre AS cobrador_n, u.apellido AS cobrador_a
FROM ic_creditos cr
JOIN ic_clientes cl ON cr.cliente_id = cl.id
LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
JOIN ic_usuarios u ON cr.cobrador_id = u.id
WHERE cr.id = ?
```

**Query cronograma:**
```sql
SELECT * FROM ic_cuotas WHERE credito_id = ? ORDER BY numero_cuota
```

**Query historial de pagos** (reusar query de `creditos/ver.php`, líneas ~70-95):
```sql
SELECT pc.*, pt.fecha_jornada, pt.monto_efectivo, pt.monto_transferencia,
       pt.monto_mora_cobrada, cu.numero_cuota,
       u.nombre AS cobrador_n, u.apellido AS cobrador_a
FROM ic_pagos_confirmados pc
JOIN ic_pagos_temporales pt ON pc.pago_temp_id = pt.id
JOIN ic_cuotas cu ON pt.cuota_id = cu.id
JOIN ic_usuarios u ON pt.cobrador_id = u.id
WHERE cu.credito_id = ?
  AND pt.estado = 'APROBADO'
ORDER BY pt.fecha_jornada DESC, pc.id DESC
```

**Secciones de la vista:**

1. **Header:** título "Crédito #{id} — Apellido, Nombre", breadcrumb "Mis Clientes > Ver crédito"

2. **Sección Info** (`.card-ic`, grid 2 columnas):
   - Cliente: Nombre, DNI, Teléfono
   - Crédito: Artículo, Fecha alta, Monto total, Cuota (`formato_pesos()`), Frecuencia, Estado (`badge_estado_credito()`), Cobrador

3. **Sección Cronograma** (`.card-ic`, `.table-ic`):
   - Columnas: N° | Vencimiento | Monto | Mora | Estado | Pagado
   - Estado por cuota con badge de color inline (PAGADA=success, PENDIENTE=secondary, VENCIDA=danger, PARCIAL=warning)
   - Sin botones de acción por fila

4. **Sección Historial de Pagos** (`.card-ic`, `.table-ic`):
   - Columnas: Cuota N° | Fecha jornada | Efectivo | Transfer. | Mora | Total | Cobrador

**Lo que NO incluir vs `creditos/ver.php`:**
- Sin botones: Revertir, Condonar, Pago directo, Eliminar, Cambiar cobrador
- Sin notas internas
- Sin historial de refinanciaciones
- Sin modales de admin
- Sin simulador de mora
- Sin timeline de actividad

Fijar `$page_current = 'mis_clientes'` para que el sidebar mantenga el ítem activo.

---

## Orden de ejecución

| # | Archivo | Depende de |
|---|---------|-----------|
| 1 | `config/sesion.php` | — |
| 2 | `views/layout.php` | Paso 1 |
| 3 | `ventas/mis_clientes.php` | Pasos 1, 2 |
| 4 | `ventas/ver_credito.php` | Paso 1 |

Pasos 3 y 4 se pueden desarrollar en paralelo.

---

## Verificación

1. Loguearse con usuario vendedor que tenga `ic_vendedores.usuario_id` asignado → sidebar muestra "Mis Clientes"
2. Navegar a "Mis Clientes" → aparecen KPIs y tabla con los clientes de sus créditos activos
3. Hacer clic en "Ver" de un crédito → se carga cronograma e historial de pagos correctamente
4. Probar con URL `ventas/ver_credito?id={credito_de_otro_vendedor}` → responde 403
5. Vendedor sin `usuario_id` asignado → no puede acceder al módulo (flash + redirect)
