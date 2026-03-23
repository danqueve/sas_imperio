# Imperio Comercial - Cambios Etapa 5

Este documento resume las nuevas funcionalidades, reportes y mejoras implementadas en la quinta etapa del sistema.

## Nuevas Funcionalidades

### 1. Scoring Predictivo + Alertas de Riesgo (Feature 3)
- **Funciones de riesgo:** `calcular_riesgo_credito_activo()` y `badge_riesgo()` en `config/funciones.php`
- **Niveles:** 1=Bajo, 2=Moderado, 3=Alto, 4=Critico — basado en cuotas vencidas, dias de atraso, mora y refinanciaciones
- **Badges en agenda:** Los creditos con riesgo >= 2 muestran badge de color en la agenda del cobrador
- **Alerta en dashboard:** Seccion "Creditos en Riesgo" con los 8 creditos mas criticos y link al reporte completo
- **Reporte completo:** `admin/riesgo_cartera.php` — tabla filtrable por cobrador y nivel de riesgo con 4 KPIs
- **Archivos:** `config/funciones.php`, `cobrador/agenda.php`, `admin/dashboard.php`, `admin/riesgo_cartera.php`

### 2. Dashboard Cobrador Mejorado (Feature 7)
- **Meta semanal:** Barra de progreso con porcentaje de cumplimiento, leyendo la meta desde `ic_usuarios.meta_semanal`
- **Gamificacion:** Strip de 3 KPIs — Racha (dias consecutivos cobrando), Posicion en ranking, Tendencia 4 semanas
- **Filtro origen:** Todas las queries de gamificacion filtran `AND origen = 'cobrador'` (no cuentan pagos manuales)
- **Archivos:** `cobrador/agenda.php`

### 3. Metas Semanales — Pagina de Admin
- **Pagina dedicada:** `admin/metas.php` para configurar la meta semanal de cada cobrador
- **Vista:** Tabla con avatar, nombre, input editable, cobrado esta semana, barra de progreso y estado
- **Permiso:** Solo admin (`gestionar_usuarios`)
- **Archivos:** `admin/metas.php`, `views/layout.php` (sidebar)

### 4. Exportacion CSV en Reportes (Feature 9)
- **Atrasados CSV:** Boton "Exportar CSV" en `admin/atrasados.php`
- **Estadisticas CSV:** Boton CSV en `admin/estadisticas_cobranza.php`
- **Formato:** UTF-8 BOM + separador `;` para compatibilidad con Excel argentino
- **Archivos:** `admin/atrasados.php`, `admin/estadisticas_cobranza.php`

### 5. Reporte de Antiguedad de Deuda (Aging Report)
- **Nuevo reporte:** `admin/aging_report.php` con 5 buckets: Al dia, 1-14, 15-30, 31-60, 60+ dias
- **KPI cards:** 5 tarjetas con cantidad de cuotas y monto por bucket
- **Detalle:** Tabla colapsable por cobrador x zona con totales
- **CSV integrado:** Exportacion CSV con los mismos filtros aplicados
- **Archivos:** `admin/aging_report.php`, `views/layout.php` (sidebar)

---

## Mejoras en PDFs de Rendicion

### 6. Rediseno completo de rendicion_pdf.php
- **Landscape A4:** Orientacion horizontal para mas espacio (257mm utiles)
- **Fuentes mas grandes:** Titulo 16pt, datos 10pt, resumen 11pt
- **Nuevas columnas:** #, Cliente, Articulo, Cuota(s), Vlr. Cuota, Efectivo, Transfer., Mora, Total
- **Agrupacion multi-cuota:** Pagos del mismo credito se muestran en 1 fila con "Cuota(s): #3, #4" y montos sumados
- **Columna Mora clara:** Muestra monto cobrado o "$ xxx (Pend.)" en italica si es cuota pura
- **Solicitudes de baja inline:** Se muestran debajo de la fila correspondiente
- **Subtitulo explicativo:** "Detalle de pagos registrados por el cobrador, pendientes de aprobacion"
- **Resumen mejorado:** Separa "Mora Cobrada" y "Mora Pendiente (cuota pura)"
- **Nota al pie:** Explica que montos "(Pend.)" NO estan incluidos en subtotales ni Total Rendido
- **Archivos:** `admin/rendicion_pdf.php`

### 7. Soporte multi-jornada en PDF
- **PDF unificado:** Si se llama sin parametro `fecha`, genera un PDF con TODAS las jornadas pendientes
- **Sub-encabezados:** Cada jornada tiene su header "Jornada: Martes 17/03/2026" con fondo gris
- **Subtotales por jornada** + **Total General** al final
- **Boton "PDF Completo":** En `admin/rendiciones.php`, visible cuando hay >1 jornada pendiente
- **Archivos:** `admin/rendicion_pdf.php`, `admin/rendiciones.php`

### 8. Consistencia en historial_rendiciones_pdf.php
- Mismos cambios de layout landscape, columnas con mora y agrupacion multi-cuota
- Nota al pie sobre mora pendiente
- **Archivos:** `admin/historial_rendiciones_pdf.php`

---

## Sidebar

Se agregaron las siguientes entradas al menu lateral (`views/layout.php`):

| Entrada | Icono | Seccion | Visible para |
|---------|-------|---------|-------------|
| Riesgo Cartera | `fa-shield-halved` | Reportes | Admin, Supervisor |
| Antig. Deuda | `fa-layer-group` | Reportes | Admin, Supervisor |
| Metas | `fa-bullseye` | Administracion | Solo Admin |

---

## Manual de Usuario

- **Archivo:** `manual_usuario.html` — Manual completo autocontenido en HTML
- **Contenido:** 7 capitulos organizados por rol (Intro, Cobrador, Vendedor, Supervisor, Admin, Tickets, FAQ)
- **Formato:** Optimizado para imprimir a PDF desde el navegador (Ctrl+P)
- **Audiencia:** Usuarios no tecnicos, lenguaje simple con instrucciones paso a paso

---

## Base de Datos

- **Nueva columna:** `ic_usuarios.meta_semanal DECIMAL(12,2) DEFAULT 500000`
- Ejecutar en bases existentes:
```sql
ALTER TABLE ic_usuarios ADD COLUMN meta_semanal DECIMAL(12,2) DEFAULT 500000 AFTER zona;
```
