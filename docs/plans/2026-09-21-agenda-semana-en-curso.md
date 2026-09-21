# Agenda de la semana en curso Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Incluir en la ficha de agenda las cuotas exigibles hasta el sábado de la semana actual, no solo hasta la fecha de emisión.

**Architecture:** Calcular una fecha límite común a partir de la fecha actual (sábado de la semana lunes-sábado) y usarla en las consultas de cuotas de la ficha PDF. Replicar el criterio en Excel para que ambas exportaciones no diverjan; la sección de créditos críticos conserva su regla de cuotas ya vencidas.

**Tech Stack:** PHP 8, PDO/MySQL, FPDF, PhpSpreadsheet.

---

### Task 1: Definir y probar el límite semanal

**Files:**
- Modify: `cobrador/agenda_pdf.php:16-28`
- Modify: `cobrador/agenda_excel.php:31-43`
- Test: `tests/LogicaFinancieraTest.php`

**Step 1: Write the failing test**

Agregar una prueba para una función pura que, dada una fecha lunes-domingo, devuelva el sábado correspondiente de la semana lunes-sábado.

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/LogicaFinancieraTest.php`

Expected: FAIL porque el helper no existe.

**Step 3: Write minimal implementation**

Agregar un helper reutilizable en `config/funciones.php` que reciba una fecha opcional y devuelva el sábado de esa semana en formato `Y-m-d`.

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/LogicaFinancieraTest.php`

Expected: PASS.

**Step 5: Commit**

```bash
git add config/funciones.php tests/LogicaFinancieraTest.php
git commit -m "feat: calcular cierre de agenda semanal"
```

### Task 2: Aplicar el límite a las exportaciones de agenda

**Files:**
- Modify: `cobrador/agenda_pdf.php:68-86,458-502`
- Modify: `cobrador/agenda_excel.php:56-93,114-146`

**Step 1: Update the PDF queries**

Reemplazar `cu.fecha_vencimiento <= CURDATE()` por `cu.fecha_vencimiento <= ?`, usando el sábado calculado como parámetro en las consultas semanal y diario/quincenal/mensual. Mantener `fecha_vencimiento < CURDATE()` sin cambios en la detección de críticos.

**Step 2: Update the Excel queries**

Aplicar el mismo límite y parámetros para que sus hojas coincidan con el PDF.

**Step 3: Run syntax checks**

Run: `php -l cobrador/agenda_pdf.php; php -l cobrador/agenda_excel.php; php -l config/funciones.php`

Expected: No syntax errors detected.

**Step 4: Verify María Lourdes Delgadillo**

Generar la ficha de Sebastián el lunes 21/09/2026: sus cuotas del miércoles 23/09 deben aparecer en Agenda General, ya que el límite será 26/09/2026.

**Step 5: Commit**

```bash
git add cobrador/agenda_pdf.php cobrador/agenda_excel.php
git commit -m "fix: incluir cuotas de la semana en agenda"
```
