# Cobrar pagos pendientes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Evitar que la agenda vuelva a pedir el importe de una cuota que ya tiene un pago temporal pendiente de aprobación.

**Architecture:** Las consultas de agenda expondrán por cuota la suma de pagos temporales únicamente en estado `PENDIENTE`. `calcular_grupo_cuotas()` sumará ese valor al saldo confirmado antes de calcular lo que falta cobrar, de modo que PDF y Excel compartan exactamente el mismo resultado. El indicador visual conservará la marca solo para pagos pendientes.

**Tech Stack:** PHP 8, PDO/MySQL, PHPUnit.

---

### Task 1: Cubrir el saldo temporal en la lógica financiera

**Files:**
- Modify: `tests/LogicaFinancieraTest.php`
- Modify: `config/funciones.php:104-131`

**Step 1: Write the failing test**

Agregar una cuota vencida de $1.000, sin mora, con $400 en `monto_pendiente` y verificar que `monto_atraso` y `monto_total` den $600.

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/LogicaFinancieraTest.php`

Expected: FAIL porque el cálculo actual ignora `monto_pendiente`.

**Step 3: Write minimal implementation**

Calcular el saldo efectivo como `saldo_pagado + monto_pendiente` y usarlo tanto para atraso como para la próxima cuota vigente.

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/LogicaFinancieraTest.php`

Expected: PASS.

### Task 2: Proveer pagos pendientes a ambas agendas

**Files:**
- Modify: `cobrador/agenda_pdf.php:45-87`
- Modify: `cobrador/agenda_pdf.php:457-497`
- Modify: `cobrador/agenda_excel.php:60-89`
- Modify: `cobrador/agenda_excel.php:115-145`

**Step 1: Update each cuota query**

Añadir `COALESCE(SUM(...), 0)` correlacionado por `cuota_id`, filtrado estrictamente por `pt.estado = 'PENDIENTE'`, como `monto_pendiente`.

**Step 2: Correct the payment marker**

Cambiar `pago_pen` para que cuente solo `PENDIENTE`; los pagos aprobados ya están reflejados en `saldo_pagado` y no deben llevar asterisco.

**Step 3: Lint and run tests**

Run: `php -l cobrador/agenda_pdf.php`; `php -l cobrador/agenda_excel.php`; `php -l config/funciones.php`; `vendor/bin/phpunit tests/LogicaFinancieraTest.php`.

Expected: Sin errores de sintaxis y pruebas exitosas.
