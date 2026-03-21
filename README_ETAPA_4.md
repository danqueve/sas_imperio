# Imperio Comercial - Cambios Etapa 4

Este documento resume las actualizaciones, correcciones de seguridad y mejoras realizadas en la cuarta etapa del desarrollo del sistema de gestión de créditos.

## 📝 Resumen de Cambios

### 1. Agenda PDF del Cobrador — Corrección de clientes faltantes
- **Bug corregido:** La agenda PDF no mostraba clientes que estaban "al día" (sin cuotas vencidas). El filtro interno excluía por error a estos clientes.
- **Solución:** Se corrigió la lógica de filtrado con LEFT JOIN invertido, tanto para créditos semanales como quincenales/mensuales.
- **Archivo:** `cobrador/agenda_pdf.php`

### 2. Historial de Rendiciones — Separación por origen
- **Nueva funcionalidad:** El historial de rendiciones ahora distingue entre pagos registrados por el **cobrador** en campo y pagos cargados de forma **manual** por admin/supervisor.
- **Filtro por origen:** Se agregó un selector para filtrar por tipo (Todos / Cobrador / Manual) con badges de color (azul = Cobrador, naranja = Manual).
- **Detalle y PDF:** Tanto la vista de detalle como la exportación PDF respetan el filtro de origen seleccionado.
- **Archivos:** `admin/historial_rendiciones.php`, `admin/historial_rendiciones_ver.php`, `admin/historial_rendiciones_pdf.php`

### 3. Rendición PDF — Mora NO cobrada
- **Corrección de etiqueta:** En los PDFs de rendición (pendiente e histórica), la línea de resumen ahora dice **"Mora NO cobrada"** en lugar de "Mora cobrada", ya que refleja el monto de mora que no se cobró al cliente. Este valor es informativo y no suma al total general.
- **Archivos:** `admin/rendicion_pdf.php`, `admin/historial_rendiciones_pdf.php`

### 4. Clientes Zona PDF — Agrupación por día de cobro
- **Mejora:** El PDF de clientes por zona ahora agrupa los clientes por día de cobro dentro de cada zona, facilitando la organización de rutas.
- **Archivo:** `cobrador/clientes_zona_pdf.php`

### 5. Agenda PDF — Columna último pago
- **Mejora:** Se agregó la columna "Ult. Pago" en la sección de clientes atrasados del PDF de agenda, para que el cobrador sepa cuándo fue el último pago de cada cliente moroso.
- **Archivo:** `cobrador/agenda_pdf.php`

---

## 🔒 Correcciones de Seguridad

### 6. Ocultamiento de errores PDO
- **Antes:** Si la conexión a la base de datos fallaba, el mensaje de error exponía detalles técnicos (host, usuario, driver).
- **Ahora:** El error se registra en `error_log` y al usuario se le muestra un mensaje genérico.
- **Archivo:** `config/conexion.php`

### 7. Cookies de sesión seguras
- **Mejora:** Se configuraron los parámetros de cookie de sesión con `httponly` (previene acceso JavaScript), `samesite: Lax` (protección CSRF) y `secure` (solo HTTPS en producción).
- **Archivo:** `config/sesion.php`

### 8. Validación de propiedad del cobrador
- **Bug corregido:** Un cobrador podía, manipulando la URL, registrar pagos en cuotas de créditos asignados a otro cobrador.
- **Solución:** Se agregó validación `AND cr.cobrador_id = ?` en la consulta de registro de pago.
- **Archivo:** `cobrador/registrar_pago.php`

### 9. SQL Injection en edición de créditos
- **Bug corregido:** Las consultas de clientes y vendedores en la pantalla de editar crédito usaban interpolación directa de variables en SQL.
- **Solución:** Se migraron a prepared statements con parámetros vinculados.
- **Archivo:** `creditos/editar.php`

### 10. Validación de fecha en estadísticas
- **Mejora:** Se agregó validación de formato `YYYY-MM-DD` con `preg_match` antes de procesar el parámetro `semana` en las estadísticas de cobranza y su exportación PDF.
- **Archivos:** `admin/estadisticas_cobranza.php`, `admin/estadisticas_pdf.php`

---

## 🐛 Correcciones de Bugs

### 11. Precisión float en pago de cuotas
- **Bug:** Al pagar una cuota con el monto exacto, errores de redondeo float (ej: 0.000000001 de diferencia) podían dejar la cuota como PARCIAL en vez de PAGADA.
- **Solución:** Se aplica `round(..., 2)` al calcular el nuevo saldo y se usa un margen de 0.01 para determinar si la cuota está pagada.
- **Archivo:** `creditos/pagar_cuota.php`

### 12. Dashboard — KPIs de cartera más precisos
- **Bug:** El KPI de "cartera vencida" solo contaba cuotas con estado VENCIDA, omitiendo cuotas PENDIENTE/PARCIAL cuya fecha de vencimiento ya pasó, y cuotas CAP_PAGADA. Tampoco incluía créditos en estado MOROSO.
- **Solución:** Se amplió la consulta para incluir todos los estados relevantes y créditos MOROSO.
- **Archivo:** `admin/dashboard.php`

### 13. Validación de garante en alta de cliente
- **Bug:** Se podía guardar un cliente con `tiene_garante=1` pero sin completar nombres/apellidos del garante, dejando datos inconsistentes.
- **Solución:** Se agregó validación que exige Nombres y Apellidos del garante cuando el checkbox está marcado.
- **Archivo:** `clientes/nuevo.php`

---

## 🗄 Cambios en Schema SQL

### 14. Nuevos índices de rendimiento
Se agregaron índices compuestos para optimizar las consultas más frecuentes del sistema:

| Tabla | Índice | Consultas que beneficia |
|---|---|---|
| `ic_cuotas` | `(credito_id, estado)` | Dashboard, agenda, pagar cuota |
| `ic_creditos` | `(cliente_id, estado)` | Dashboard, vista de cliente |
| `ic_clientes` | `(cobrador_id)` | Agenda, listados por cobrador |
| `ic_pagos_temporales` | `(cobrador_id, fecha_jornada, estado)` | Rendiciones |

### 15. Documentación de columnas existentes
Se agregaron al schema las columnas `fecha_jornada` y `origen` de `ic_pagos_temporales` que ya existían en la base de datos pero no estaban documentadas en el archivo SQL de referencia.

**Para aplicar los índices en una base de datos existente:**
```sql
ALTER TABLE ic_cuotas ADD INDEX idx_cuotas_credito_estado (credito_id, estado);
ALTER TABLE ic_creditos ADD INDEX idx_creditos_cliente_estado (cliente_id, estado);
ALTER TABLE ic_clientes ADD INDEX idx_clientes_cobrador (cobrador_id);
ALTER TABLE ic_pagos_temporales ADD INDEX idx_pt_cobrador_jornada (cobrador_id, fecha_jornada, estado);
```

---

## 🚀 Impacto General

Esta etapa se enfocó en tres ejes:
1. **Seguridad** — Se cerraron vulnerabilidades de SQL injection, exposición de errores, sesiones inseguras y falta de control de acceso entre cobradores.
2. **Integridad de datos** — Se corrigieron bugs de redondeo float, KPIs imprecisos y validaciones faltantes que podían generar datos inconsistentes.
3. **Funcionalidad** — Se separó el historial de rendiciones por origen (cobrador vs manual), se mejoró la agenda PDF y se optimizó el rendimiento con índices.
