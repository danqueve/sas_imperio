# Plan — Módulo de Estadísticas de Cobradores

## Objetivo
Crear una vista unificada en `admin/estadisticas_cobrador.php` (o mejorar [estadisticas_cobranza.php](file:///c:/wamp64/www/creditos/admin/estadisticas_cobranza.php)) que permita medir el rendimiento individual de cada cobrador en múltiples dimensiones.

---

## Sección 1 — KPIs de Producción (¿cuánto cobra?)

| Métrica | Descripción | Período |
|---------|-------------|---------|
| **Total cobrado** | Suma de todos los pagos aprobados | Día / Semana / Mes / Custom |
| **Cantidad de pagos** | Número de cuotas cobradas | Igual |
| **Ticket promedio** | Total cobrado / cantidad de pagos | Igual |
| **Mora cobrada** | Suma del campo `monto_mora_cobrada` | Igual |
| **% sobre meta** | Si se define una meta mensual por cobrador | Mensual |

**Fuente SQL:** `ic_pagos_confirmados JOIN ic_usuarios`

---

## Sección 2 — Calidad de Cartera (¿qué tan sana está su cartera?)

| Métrica | Descripción |
|---------|-------------|
| **Créditos en curso** | Total activos asignados |
| **Créditos morosos** | Estado = MOROSO en su cartera |
| **% morosidad** | MOROSO / total en curso |
| **Cuotas vencidas pendientes** | Cuotas vencidas sin pagar en su cartera |
| **Días promedio de atraso** | Promedio de [dias_atraso](file:///c:/wamp64/www/creditos/config/funciones.php#39-53) de cuotas vencidas |
| **Deuda vencida total $** | Suma de capital de cuotas vencidas en su cartera |

**Fuente SQL:** `ic_creditos + ic_cuotas WHERE cobrador_id = ?`

---

## Sección 3 — Eficiencia (¿qué tan efectivo es?)

| Métrica | Descripción |
|---------|-------------|
| **Tasa de recuperación** | Cuotas cobradas / cuotas que vencían en el período |
| **Créditos finalizados** | Cuántos créditos cerró (pago completo) |
| **Tasa de finalización** | Finalizados / total administrados |
| **Clientes sin visitar** | Créditos morosos sin pago en los últimos 30 días |
| **Rendiciones en tiempo** | % de rendiciones aprobadas vs rechazadas |

---

## Sección 4 — Comparativa / Ranking

Un **ranking visual** de cobradores ordenado por:
- Mayor monto cobrado en el mes
- Menor % de morosidad
- Mayor tasa de recuperación

Con barras de progreso horizontales y podio top 3.

---

## Sección 5 — Gráficos Históricos

- 📊 **Barras por día/semana** — cobros del cobrador seleccionado vs promedio del equipo
- 📈 **Línea de morosidad** — evolución del % moroso de su cartera en los últimos 6 meses
- 🥧 **Torta de motivos de finalización** — de los créditos que cerró: pago completo vs mora vs otros

---

## Filtros de la Vista

- 👤 **Cobrador** (selector o vista individual por cobrador)
- 📅 **Período** — Hoy / Esta semana / Este mes / Rango custom
- 🔁 **Comparar con** — otro cobrador o el promedio del equipo

---

## Vistas Posibles

### A) Página de Ranking General (`admin/ranking_cobradores.php`)
Una sola página con todos los cobradores en tabla + mini KPIs + podio.
→ **Más rápido de implementar.**

### B) Ficha Individual (`admin/cobrador_detalle.php?id=X`)
Vista detallada de un cobrador con gráficos históricos y desglose completo.
→ **Más información, más trabajo.**

### C) Reporte Comparativo (`admin/estadisticas_cobrador.php`)
Selector de período + cobrador, con KPIs lado a lado para comparar 2 cobradores.
→ **Más flexible.**

---

## Recomendación de Prioridad

| # | Implementación | Valor | Esfuerzo |
|---|---------------|-------|----------|
| 1 | **Ranking mensual con KPIs** (A) | ⭐⭐⭐⭐⭐ | Bajo |
| 2 | **Calidad de cartera por cobrador** | ⭐⭐⭐⭐⭐ | Medio |
| 3 | **Tasa de recuperación y clientes sin visitar** | ⭐⭐⭐⭐ | Medio |
| 4 | **Gráficos históricos individuales** | ⭐⭐⭐ | Alto |
| 5 | **Ficha individual con comparativa** | ⭐⭐⭐ | Alto |

> **Recomiendo empezar con el Ranking + Calidad de Cartera.** Es la combinación con mayor valor inmediato: en una sola pantalla ves quién cobra más Y quién tiene la cartera más sana.
