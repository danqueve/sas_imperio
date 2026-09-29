# Metas generales y por cobrador Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Hacer que las metas semanales individuales y generales reflejen cuotas realmente cobrables —incluidas las reglas recién aplicadas al histórico— y que los snapshots históricos se calculen con la fecha de su propio cierre.

**Architecture:** Mantener `config/funciones.php` como fuente única de los importes de meta y separar tres conceptos explícitos: cartera automática cobrable, meta objetivo efectiva (automática u override manual) y cobrado atribuido al cobrador. Reutilizar las reglas de cuota vigente/pago adelantado del histórico para la parte semanal; las vistas solo presentan resultados y no vuelven a decidir qué entra. Los snapshots existentes no se reescriben automáticamente: se preservan y cualquier recálculo histórico será un proceso de vista previa y aprobación explícita.

**Tech Stack:** PHP 8.2, PDO/MySQL, PHPUnit 10, FPDF.

---

## Estado relevado y criterio propuesto

- `calcular_metas_semanales_auto()` y `calcular_meta_semanal_pura()` incluyen hoy cuotas semanales en estado `PAGADA` aunque fueron saldadas antes de la semana; a diferencia de `obtener_agenda_historica()`, no calculan `pagado_antes` ni excluyen el estado vacío de bajas.
- `admin/estadisticas_cobranza.php` repite ese criterio para el Estimado general y por cobrador. `admin/estadisticas_pdf.php` usa una tercera consulta, todavía más amplia, para su agenda/estimado diario.
- Los snapshots históricos pasan `$fin_semana` a las funciones de meta, pero la mora se calcula con `dias_atraso_habiles()` sin fecha de referencia; por lo tanto, un snapshot pasado usa la mora de hoy.
- Las metas manuales son override por cobrador en `ic_usuarios.meta_semanal`. Funcionan en `admin/metas.php` y `cobrador/agenda.php`, pero el historial guarda únicamente la meta automática: hoy no existe una “meta objetivo efectiva” histórica ni general.
- Línea de base al 29/09/2026: 7 cobradores activos, $65.005.329,74 de meta automática, $2.507.250 cobrado en la semana y 0 overrides manuales. Hay 42 snapshots entre 10/08 y 21/09/2026.

## Decisiones necesarias antes de escribir código

1. **Definición de meta:** usar como regla por defecto “cobrable real al inicio de la semana”: excluir bajas, cuotas ya saldadas por adelantado y pagos de otra semana; mantener pagos parciales dentro de la meta por su valor nominal. Es la misma regla ya aprobada para el Estimado histórico.
2. **Alcance de frecuencias no semanales:** conservar el criterio actual de cartera exigible acumulada para diario/quincenal/mensual, o cambiar todas las frecuencias a cuotas con vencimiento en la semana. El plan asume conservar la cartera exigible acumulada, porque es la conducta actual declarada de la Meta Automática.
3. **Overrides y meta general:** si un cobrador tiene override, la meta general debe sumar su override (meta objetivo efectiva), conservando la meta automática como indicador de capacidad de cartera. El plan asume que sí.
4. **Snapshots ya registrados:** no sobrescribir las 42 filas existentes. Si se desea corregirlas, ejecutar primero una vista previa por semana/cobrador y aprobar un recálculo; no hay historial de asignaciones de cobrador, por lo que no se debe alterar pasado silenciosamente.

### Task 1: Documentar el contrato y congelar una línea de base de solo lectura

**Files:**
- Create: `tests/MetasSemanalesTest.php`
- Modify: `config/funciones.php:151-376`
- Modify: `docs/plans/2026-09-29-metas-generales-y-cobradores.md`

**Step 1: Write the failing test**

Crear fixtures/mocks PDO para estos casos de cuota semanal:

```php
// Baja CANCELADA o estado vacío sin cobro semanal: no integra la meta.
// Baja con cobro confirmado en la semana: sí integra la meta por salvaguarda.
// Pago completo confirmado antes del lunes: no integra la meta.
// Pago parcial antes del lunes: integra el monto nominal.
// Pago de la misma semana: integra la meta y es cobrado, no “ya pagada”.
// Snapshot 08/09: los días de mora se calculan contra 14/09, nunca contra hoy.
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/MetasSemanalesTest.php`

Expected: FAIL porque aún no existe un cálculo de cuota cobrable por semana ni se propaga la fecha de referencia de mora.

**Step 3: Capture the read-only baseline**

Agregar a la salida de prueba (no a la aplicación) una consulta/fixture que compare, para la semana actual y dos semanas cerradas:

```text
cobrador | meta_actual | meta_cobrable_propuesta | diferencia_por_bajas | diferencia_por_adelantos
```

Guardar los resultados de validación en el comentario del PR/commit, no en tablas de producción.

**Step 4: Commit tests only**

```bash
git add tests/MetasSemanalesTest.php
git commit -m "test: cubrir reglas cobrables de metas semanales"
```

### Task 2: Centralizar las cuotas cobrables y hacer la mora temporalmente correcta

**Files:**
- Modify: `config/funciones.php:151-376`
- Test: `tests/MetasSemanalesTest.php`

**Step 1: Add minimal shared helpers**

Agregar un helper privado de cálculo que reciba filas ya obtenidas, el lunes de la semana y una fecha de referencia. Cada fila debe traer `pagado_antes`, `pago_en_semana` y los datos de cuota necesarios. Su decisión central será:

```php
$ya_estaba_paga = !$fila['pago_en_semana']
    && (float) $fila['pagado_antes'] >= (float) $fila['monto_cuota'] - 0.01;

$incluida = !$ya_estaba_paga;
$mora = dias_atraso_habiles($fila['fecha_vencimiento'], $fecha_referencia);
```

En la consulta semanal usar la whitelist `PENDIENTE`, `PAGADA`, `VENCIDA`, `PARCIAL`, `CAP_PAGADA`, con la misma salvaguarda de pago semanal confirmado usada por el histórico. Para `pagado_antes`, sumar únicamente pagos confirmados no revertidos con `semana_lunes < :lunes` y sin filtrar por cobrador.

**Step 2: Refactor callers without changing manual overrides**

Hacer que `calcular_metas_semanales_auto()` y `calcular_meta_semanal_pura()` compartan la selección semanal corregida. Pasar explícitamente la fecha de referencia a `dias_atraso_habiles()` en todas las rutas de meta y snapshot. Mantener para diario/quincenal/mensual la cartera exigible actual y sus estados impagos.

**Step 3: Run tests**

Run: `vendor/bin/phpunit tests/MetasSemanalesTest.php; vendor/bin/phpunit`

Expected: PASS.

**Step 4: Commit**

```bash
git add config/funciones.php tests/MetasSemanalesTest.php
git commit -m "fix(metas): calcular cartera semanal realmente cobrable"
```

### Task 3: Aplicar el contrato a metas por cobrador y meta general vigente

**Files:**
- Modify: `admin/metas.php:60-204`
- Modify: `cobrador/agenda.php:348-370,679-730`
- Modify: `admin/dashboard.php:218-232,474-507`
- Test: `tests/MetasSemanalesTest.php`

**Step 1: Define explicit aggregates**

En `admin/metas.php`, calcular por cobrador:

```php
$meta_automatica = $metas_auto[$id] ?? 0.0;
$meta_objetivo = $meta_manual === null ? $meta_automatica : (float) $meta_manual;
```

Agregar un resumen superior que sume ambas columnas por separado: **Meta automática de cartera**, **Meta objetivo del equipo**, **Cobrado atribuido** y porcentaje contra meta objetivo. No mutar `meta_semanal` al renderizar.

**Step 2: Make dashboard ranking comparable**

Reemplazar las barras del ranking basadas en “mayor monto del grupo” por avance contra la meta objetivo de cada cobrador; conservar el ranking de monto como orden secundario o etiqueta, no como porcentaje de cumplimiento.

**Step 3: Keep the collector view clear**

En `cobrador/agenda.php`, dejar visible si la meta aplicada es automática o manual y conservar “Meta Fija Semanal” como métrica separada. Nunca comparar cobrado con una meta que incluya una cuota marcada como ya pagada antes de la semana.

**Step 4: Run tests and syntax checks**

Run: `vendor/bin/phpunit tests/MetasSemanalesTest.php; php -l config/funciones.php; php -l admin/metas.php; php -l admin/dashboard.php; php -l cobrador/agenda.php`

Expected: all PASS / `No syntax errors detected`.

**Step 5: Commit**

```bash
git add admin/metas.php admin/dashboard.php cobrador/agenda.php config/funciones.php tests/MetasSemanalesTest.php
git commit -m "feat(metas): mostrar objetivo efectivo general y por cobrador"
```

### Task 4: Alinear estadísticas generales y exportaciones con la regla de meta

**Files:**
- Modify: `admin/estadisticas_cobranza.php:23-207`
- Modify: `admin/estadisticas_pdf.php:37-117`
- Test: `tests/MetasSemanalesTest.php`

**Step 1: Update the screen calculation**

En `calcular_estadisticas()`, incorporar `pagado_antes` y la misma exclusión de cuotas ya saldadas antes de la semana. Mantener por separado las cuotas cobradas en la semana y los adelantos, para que un anticipo anterior no aparezca como “cobrado” en la semana consultada.

**Step 2: Decide the PDF scope explicitly**

El PDF actual es un reporte diario de agenda, no el mismo resumen que `estadisticas_cobranza.php`. Aplicar la regla de exclusión a sus cuotas agendadas y actualizar el encabezado/notas para aclarar que el “estimado” es cobrable; si se desea igualdad absoluta de todas las métricas, extraer un proveedor compartido antes de cambiar el formato.

**Step 3: Verify reconciliation**

Para un cobrador con adelantos y uno con bajas, comprobar:

```text
Meta automática semanal = Estimado semanal de Estadísticas
Meta objetivo = override manual o Meta automática
Cobrado = pagos de origen cobrador de esa semana, sin pagos de otra semana
```

Verificar además que el total general sea la suma de los objetivos efectivos, no la suma de porcentajes individuales.

**Step 4: Commit**

```bash
git add admin/estadisticas_cobranza.php admin/estadisticas_pdf.php tests/MetasSemanalesTest.php
git commit -m "fix(estadisticas): alinear estimado con metas cobrables"
```

### Task 5: Versionar el objetivo efectivo en snapshots futuros

**Files:**
- Create: `sql/migration_meta_objetivo_snapshot.sql`
- Modify: `config/funciones.php:319-376`
- Modify: `cron/snapshot_metas_semanales.php:47-91`
- Modify: `admin/historial_metas.php:34-187`
- Modify: `admin/historial_metas_pdf.php:17-100`
- Test: `tests/MetasSemanalesTest.php`

**Step 1: Write the failing snapshot test**

Cubrir que un snapshot futuro guarde:

```php
assertSame(500000.0, $snapshot['meta_automatica']);
assertSame(450000.0, $snapshot['meta_objetivo']); // override manual
assertSame('MANUAL', $snapshot['origen_meta']);
```

**Step 2: Add migration and write path**

Agregar campos no nulos con valores por defecto compatibles:

```sql
ALTER TABLE ic_historial_metas
  ADD COLUMN meta_objetivo DECIMAL(12,2) NULL AFTER meta_automatica,
  ADD COLUMN origen_meta ENUM('AUTOMATICA','MANUAL') NOT NULL DEFAULT 'AUTOMATICA' AFTER meta_objetivo;
```

En snapshots nuevos, guardar tanto cartera automática como objetivo efectivo. Para filas existentes, mostrar `meta_automatica` como objetivo histórico con una etiqueta “criterio anterior”; no inferir un override inexistente.

**Step 3: Preserve historical data by default**

Agregar al cron un modo `--dry-run --semana=YYYY-MM-DD` que imprima diferencias entre snapshot guardado y cálculo nuevo. No incluir un `UPDATE` masivo. Si se aprueba corrección histórica, crear un comando separado que exija `--confirmar-recalculo` y genere CSV de antes/después.

**Step 4: Run test and migration verification on a copy/backup**

Run: `vendor/bin/phpunit tests/MetasSemanalesTest.php; php cron/snapshot_metas_semanales.php --dry-run --semana=2026-09-21`

Expected: PASS and a non-destructive per-cobrador summary.

**Step 5: Commit**

```bash
git add sql/migration_meta_objetivo_snapshot.sql config/funciones.php cron/snapshot_metas_semanales.php admin/historial_metas.php admin/historial_metas_pdf.php tests/MetasSemanalesTest.php
git commit -m "feat(metas): guardar objetivo efectivo en snapshots"
```

### Task 6: Acceptance review and deployment

**Files:**
- Verify: `admin/metas.php`
- Verify: `cobrador/agenda.php`
- Verify: `admin/dashboard.php`
- Verify: `admin/estadisticas_cobranza.php`
- Verify: `admin/estadisticas_pdf.php`
- Verify: `admin/historial_metas.php`

**Step 1: Re-run the historical-agenda control case**

Usar Diego Loto (id 45), semana 07/09/2026: las 10 bajas no integran la base y las 9 cuotas ya pagadas antes no integran Estimado ni Faltante; las cuotas 28791 y 28801 se mantienen como parciales cobrables.

**Step 2: Validate current-week goal reconciliation**

Para los siete cobradores activos, exportar una tabla de auditoría con meta automática, override, meta objetivo, cobrado, porcentaje y diferencia. Revisar individualmente todo salto material respecto de la línea de base de $65.005.329,74.

**Step 3: Validate snapshot timing**

Elegir una semana cerrada con cuotas vencidas y verificar que la mora del dry-run se calcule al domingo de esa semana, no a la fecha de ejecución.

**Step 4: Run full checks**

Run: `vendor/bin/phpunit; php -l config/funciones.php; php -l admin/metas.php; php -l admin/dashboard.php; php -l cobrador/agenda.php; php -l admin/estadisticas_cobranza.php; php -l admin/estadisticas_pdf.php; php -l cron/snapshot_metas_semanales.php; php -l admin/historial_metas.php; php -l admin/historial_metas_pdf.php`

Expected: all tests and syntax checks pass.

**Step 5: Commit and deploy after approval**

```bash
git status --short
git push origin main
```

Only apply `sql/migration_meta_objetivo_snapshot.sql` after taking a database backup and receiving explicit approval for that migration.
