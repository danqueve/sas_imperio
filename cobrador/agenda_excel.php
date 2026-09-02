<?php
// ============================================================
// cobrador/agenda_excel.php — Ficha semanal de cobros por cobrador,
// exportada a XLSX (PhpSpreadsheet). Mismas 3 consultas y mismos
// criterios de filtro/calculo que cobrador/agenda_pdf.php, pero en
// hojas separadas (Semanales, Quinc-Mensual, Criticos, Resumen) en
// vez de paginas PDF — no comparte codigo con el PDF porque ese esta
// entrelazado con el posicionamiento de celdas del documento impreso.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
require_once __DIR__ . '/../vendor/autoload.php';
verificar_sesion();
verificar_permiso('ver_agenda');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$pdo         = obtener_conexion();
$is_cobrador = es_cobrador();
$user_id     = $_SESSION['user_id'];

// ── Parámetros GET (mismos que agenda_pdf.php) ─────────────────
$cobrador_id = $is_cobrador ? $user_id : (int) ($_GET['cobrador_id'] ?? 0);
$dias_sel    = array_map('intval', (array) ($_GET['dias'] ?? [1, 2, 3, 4, 5, 6]));
$dias_sel    = array_filter($dias_sel, fn($d) => $d >= 1 && $d <= 6);
sort($dias_sel);
$incluir_qm  = ($_GET['incluir_qm'] ?? '1') === '1';

if (!$cobrador_id) die('Seleccioná un cobrador.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

if (empty($dias_sel)) die('Seleccioná al menos un día.');

// ── Helper de cálculo — mismo criterio que agenda_pdf.php ──────
function calcularAjuste(array $r): array
{
    $mora_db = (float) $r['monto_mora'];
    $mora = $mora_db > 0
        ? $mora_db
        : calcular_mora((float) $r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float) $r['interes_moratorio_pct']);
    $saldo_p = (float) ($r['saldo_pagado'] ?? 0);
    $total_cobrar = ($r['cuota_estado'] === 'CAP_PAGADA')
        ? $mora
        : max(0, (float) $r['monto_cuota'] + $mora - $saldo_p);
    return ['mora' => $mora, 'saldo_pagado' => $saldo_p, 'total_cobrar' => $total_cobrar];
}

// ── Consulta 1: Semanales (idéntica a agenda_pdf.php) ───────────
$placeholders = implode(',', array_fill(0, count($dias_sel), '?'));
$params = array_merge([$cobrador_id], $dias_sel);

$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.direccion, cl.localidad, cl.barrio, cr.dia_cobro,
           cr.id AS credito_id, cr.interes_moratorio_pct, cr.cant_cuotas,
           cr.estado AS credito_estado,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado, cu.monto_mora, cu.saldo_pagado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id = cu.id AND pt.estado IN ('PENDIENTE','APROBADO')) AS pago_pen
    FROM ic_clientes cl
    JOIN ic_creditos cr  ON cr.cliente_id = cl.id
                        AND cr.cobrador_id = ?
                        AND cr.estado IN ('EN_CURSO','MOROSO')
                        AND cr.frecuencia = 'semanal'
    JOIN ic_cuotas  cu   ON cu.credito_id = cr.id
                        AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    LEFT JOIN (
        SELECT credito_id
        FROM ic_cuotas
        WHERE fecha_vencimiento < CURDATE()
          AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
        GROUP BY credito_id
        HAVING COUNT(*) >= 5
    ) filtro ON filtro.credito_id = cr.id
    WHERE cr.dia_cobro IN ($placeholders)
      AND filtro.credito_id IS NULL
    ORDER BY cr.dia_cobro ASC, COALESCE(cl.zona,'') ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Un registro por (día, crédito) — la cuota más atrasada, mismo criterio que el PDF
$por_dia = [];
foreach ($dias_sel as $d) $por_dia[$d] = [];
$visto = [];
foreach ($rows as $r) {
    $clave = $r['dia_cobro'] . '-' . $r['credito_id'];
    if (isset($visto[$clave])) continue;
    $visto[$clave] = true;
    $por_dia[$r['dia_cobro']][] = $r;
}
foreach ($dias_sel as $d) {
    usort($por_dia[$d], fn($a, $b) =>
        strcmp(mb_strtoupper(($a['zona'] ?? '') . $a['apellidos']), mb_strtoupper(($b['zona'] ?? '') . $b['apellidos']))
    );
}

// ── Consulta 2: Quincenales/Mensuales/Diarios (idéntica a agenda_pdf.php) ──
$rows_qm = [];
if ($incluir_qm) {
    $stmt_qm = $pdo->prepare("
        SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.direccion, cl.localidad, cl.barrio,
               cr.frecuencia, cr.cant_cuotas, cr.estado AS credito_estado,
               cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota, cu.estado AS cuota_estado,
               cu.monto_mora, cu.saldo_pagado,
               cr.interes_moratorio_pct,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
               (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id = cu.id AND pt.estado IN ('PENDIENTE','APROBADO')) AS pago_pen
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
        LEFT JOIN (
            SELECT credito_id
            FROM ic_cuotas
            WHERE fecha_vencimiento < CURDATE()
              AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
            GROUP BY credito_id
            HAVING COUNT(*) >= 5
        ) filtro ON filtro.credito_id = cr.id
        WHERE cr.cobrador_id = ?
          AND cr.estado IN ('EN_CURSO','MOROSO')
          AND cr.frecuencia IN ('diario', 'quincenal', 'mensual')
          AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'CAP_PAGADA', 'PARCIAL')
          AND filtro.credito_id IS NULL
        ORDER BY cr.frecuencia ASC, COALESCE(cl.zona,'') ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
    ");
    $stmt_qm->execute([$cobrador_id]);
    $rows_qm_raw = $stmt_qm->fetchAll();

    // Un registro por cliente y frecuencia — la cuota más atrasada, mismo criterio que el PDF
    $qm_aux = ['diario' => [], 'quincenal' => [], 'mensual' => []];
    foreach ($rows_qm_raw as $r) {
        $key  = $r['cliente_id'];
        $frec = $r['frecuencia'];
        if (!isset($qm_aux[$frec][$key])) {
            $qm_aux[$frec][$key] = $r;
        }
    }
    foreach ($qm_aux as $frec => $lista) {
        $lista = array_values($lista);
        usort($lista, fn($a, $b) =>
            strcmp(mb_strtoupper(($a['zona'] ?? '') . $a['apellidos']), mb_strtoupper(($b['zona'] ?? '') . $b['apellidos']))
        );
        foreach ($lista as $r) {
            $r['frecuencia_label'] = match ($frec) { 'diario' => 'Diario', 'quincenal' => 'Quincenal', default => 'Mensual' };
            $rows_qm[] = $r;
        }
    }
}

// ── Consulta 3: Clientes con 5+ cuotas atrasadas (idéntica a agenda_pdf.php) ──
$stmt_atr = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cl.zona,'') AS zona, cl.localidad, cl.barrio,
           cr.id AS credito_id, cr.cant_cuotas, cr.frecuencia,
           cr.estado AS credito_estado, cr.interes_moratorio_pct,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           COUNT(cu.id)        AS cuotas_atrasadas,
           MIN(cu.monto_cuota) AS valor_cuota,
           SUM(cu.monto_cuota) AS monto_base,
           (SELECT MAX(pt.fecha_jornada)
            FROM ic_pagos_confirmados pc
            JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
            JOIN ic_cuotas cu2          ON cu2.id = pc.cuota_id
            WHERE cu2.credito_id = cr.id) AS ultimo_pago
    FROM ic_creditos cr
    JOIN ic_clientes cl  ON cl.id  = cr.cliente_id
    JOIN ic_cuotas   cu  ON cu.credito_id = cr.id
                        AND cu.fecha_vencimiento < CURDATE()
                        AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
    GROUP BY cr.id, cl.id, cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.localidad, cl.barrio,
             cr.cant_cuotas, cr.frecuencia, cr.estado, cr.interes_moratorio_pct, articulo
    HAVING COUNT(cu.id) >= 5
    ORDER BY COALESCE(cl.zona,'') ASC, cl.apellidos ASC
");
$stmt_atr->execute([$cobrador_id]);
$rows_atr = $stmt_atr->fetchAll();

// ============================================================
// Construcción del Excel
// ============================================================
$AZUL_CLARO = 'D9E2F3';
$GRIS_CLARO = 'F0F0F0';

function estilizarEncabezado($sheet, string $rango, string $color): void
{
    $sheet->getStyle($rango)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
    ]);
}

function autoAjustar($sheet, int $cantColumnas): void
{
    for ($i = 1; $i <= $cantColumnas; $i++) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Imperio Comercial')
    ->setTitle('Agenda Semanal - ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']);

$nombres_dia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado'];

// ── Hoja 1: Semanales ────────────────────────────────────────
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Semanales');

$headers1 = ['Dia', 'Zona', 'Cliente', 'Telefono', 'Moroso', 'Pago Pendiente Aprobacion',
             'Vencimiento', 'Dias Atraso', 'Direccion', 'Localidad', 'Barrio', 'Articulo',
             'Cuota', 'Monto Cuota', 'Mora', 'Saldo Pagado', 'Total a Cobrar'];
$sheet1->fromArray($headers1, null, 'A1');
estilizarEncabezado($sheet1, 'A1:Q1', $AZUL_CLARO);

$fila = 2;
foreach ($dias_sel as $d) {
    foreach ($por_dia[$d] as $r) {
        $aj = calcularAjuste($r);
        $sheet1->fromArray([
            $nombres_dia[$d],
            $r['zona'] ?: '',
            $r['apellidos'] . ', ' . $r['nombres'],
            $r['telefono'] ?: '',
            $r['credito_estado'] === 'MOROSO' ? 'Si' : 'No',
            (int) ($r['pago_pen'] ?? 0) > 0 ? 'Si' : 'No',
            $r['fecha_vencimiento'],
            dias_atraso_habiles($r['fecha_vencimiento']),
            $r['direccion'] ?: '',
            $r['localidad'] ?: '',
            $r['barrio'] ?: '',
            $r['articulo'] ?: '',
            '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'],
            (float) $r['monto_cuota'],
            $aj['mora'],
            $aj['saldo_pagado'],
            $aj['total_cobrar'],
        ], null, 'A' . $fila);
        $fila++;
    }
}
if ($fila > 2) {
    $sheet1->getStyle('N2:Q' . ($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet1->getStyle('G2:G' . ($fila - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
}
$sheet1->freezePane('A2');
autoAjustar($sheet1, 17);

// ── Hoja 2: Quincenales/Mensuales (solo si incluir_qm y hay datos) ──
if ($incluir_qm && !empty($rows_qm)) {
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Quinc-Mensual');

    $headers2 = ['Frecuencia', 'Zona', 'Cliente', 'Telefono', 'Moroso', 'Pago Pendiente Aprobacion',
                 'Vencimiento', 'Dias Atraso', 'Direccion', 'Localidad', 'Barrio', 'Articulo',
                 'Cuota', 'Monto Cuota', 'Mora', 'Saldo Pagado', 'Total a Cobrar'];
    $sheet2->fromArray($headers2, null, 'A1');
    estilizarEncabezado($sheet2, 'A1:Q1', $AZUL_CLARO);

    $fila = 2;
    foreach ($rows_qm as $r) {
        $aj = calcularAjuste($r);
        $sheet2->fromArray([
            $r['frecuencia_label'],
            $r['zona'] ?: '',
            $r['apellidos'] . ', ' . $r['nombres'],
            $r['telefono'] ?: '',
            $r['credito_estado'] === 'MOROSO' ? 'Si' : 'No',
            (int) ($r['pago_pen'] ?? 0) > 0 ? 'Si' : 'No',
            $r['fecha_vencimiento'],
            dias_atraso_habiles($r['fecha_vencimiento']),
            $r['direccion'] ?: '',
            $r['localidad'] ?: '',
            $r['barrio'] ?: '',
            $r['articulo'] ?: '',
            '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'],
            (float) $r['monto_cuota'],
            $aj['mora'],
            $aj['saldo_pagado'],
            $aj['total_cobrar'],
        ], null, 'A' . $fila);
        $fila++;
    }
    $sheet2->getStyle('N2:Q' . ($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle('G2:G' . ($fila - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    $sheet2->freezePane('A2');
    autoAjustar($sheet2, 17);
}

// ── Hoja 3: Críticos (solo si hay datos) ──────────────────────
if (!empty($rows_atr)) {
    $sheet3 = $spreadsheet->createSheet();
    $sheet3->setTitle('Criticos');

    $headers3 = ['Cliente', 'Telefono', 'Zona', 'Localidad', 'Barrio', 'Articulo',
                 'Cuotas Atrasadas', 'Cant. Total Cuotas', 'Valor Cuota', 'Ultimo Pago',
                 'Total (suma de cuotas nominales, sin mora)'];
    $sheet3->fromArray($headers3, null, 'A1');
    estilizarEncabezado($sheet3, 'A1:K1', $AZUL_CLARO);

    $fila = 2;
    foreach ($rows_atr as $r) {
        $sheet3->fromArray([
            $r['apellidos'] . ', ' . $r['nombres'],
            $r['telefono'] ?: '',
            $r['zona'] ?: '',
            $r['localidad'] ?: '',
            $r['barrio'] ?: '',
            $r['articulo'] ?: '',
            (int) $r['cuotas_atrasadas'],
            (int) $r['cant_cuotas'],
            (float) $r['valor_cuota'],
            $r['ultimo_pago'] ?: '',
            (float) $r['monto_base'],
        ], null, 'A' . $fila);
        if (!empty($r['ultimo_pago'])) {
            $sheet3->setCellValue('J' . $fila, $r['ultimo_pago']);
        }
        $fila++;
    }
    if ($fila > 2) {
        $sheet3->getStyle('I2:I' . ($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet3->getStyle('K2:K' . ($fila - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet3->getStyle('J2:J' . ($fila - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    }
    $sheet3->freezePane('A2');
    autoAjustar($sheet3, 11);
}

// ── Hoja 4: Resumen ────────────────────────────────────────────
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('Resumen');

$sheet4->setCellValue('A1', 'Resumen General — ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']);
$sheet4->getStyle('A1')->getFont()->setBold(true)->setSize(13);

$fila = 3;
$sheet4->setCellValue('A' . $fila, 'Semanales');
estilizarEncabezado($sheet4, 'A' . $fila . ':C' . $fila, $GRIS_CLARO);
$fila++;
$sheet4->fromArray(['Dia', 'Cuotas', 'Monto'], null, 'A' . $fila);
estilizarEncabezado($sheet4, 'A' . $fila . ':C' . $fila, $AZUL_CLARO);
$fila++;

$total_gral_cant  = 0;
$total_gral_monto = 0.0;
$sub_cant_dias    = 0;
$sub_monto_dias   = 0.0;
foreach ($dias_sel as $d) {
    $cant  = count($por_dia[$d]);
    if ($cant === 0) continue;
    $monto = 0.0;
    foreach ($por_dia[$d] as $r) $monto += calcularAjuste($r)['total_cobrar'];
    $sheet4->fromArray([$nombres_dia[$d], $cant, $monto], null, 'A' . $fila);
    $sub_cant_dias  += $cant;
    $sub_monto_dias += $monto;
    $fila++;
}
$sheet4->fromArray(['Subtotal Semanales', $sub_cant_dias, $sub_monto_dias], null, 'A' . $fila);
$sheet4->getStyle('A' . $fila . ':C' . $fila)->getFont()->setBold(true);
$total_gral_cant  += $sub_cant_dias;
$total_gral_monto += $sub_monto_dias;
$fila += 2;

if (!empty($rows_qm)) {
    $sheet4->setCellValue('A' . $fila, 'Quincenales / Mensuales');
    estilizarEncabezado($sheet4, 'A' . $fila . ':C' . $fila, $GRIS_CLARO);
    $fila++;
    $sheet4->fromArray(['Frecuencia', 'Clientes', 'Monto'], null, 'A' . $fila);
    estilizarEncabezado($sheet4, 'A' . $fila . ':C' . $fila, $AZUL_CLARO);
    $fila++;

    $por_frec_res = [];
    foreach ($rows_qm as $r) {
        $lbl = $r['frecuencia_label'];
        $por_frec_res[$lbl]['cant']  = ($por_frec_res[$lbl]['cant']  ?? 0) + 1;
        $por_frec_res[$lbl]['monto'] = ($por_frec_res[$lbl]['monto'] ?? 0.0) + calcularAjuste($r)['total_cobrar'];
    }
    $sub_cant_frec  = 0;
    $sub_monto_frec = 0.0;
    foreach ($por_frec_res as $lbl => $d2) {
        $sheet4->fromArray([$lbl, $d2['cant'], $d2['monto']], null, 'A' . $fila);
        $sub_cant_frec  += $d2['cant'];
        $sub_monto_frec += $d2['monto'];
        $fila++;
    }
    $sheet4->fromArray(['Subtotal Quinc./Mens.', $sub_cant_frec, $sub_monto_frec], null, 'A' . $fila);
    $sheet4->getStyle('A' . $fila . ':C' . $fila)->getFont()->setBold(true);
    $total_gral_cant  += $sub_cant_frec;
    $total_gral_monto += $sub_monto_frec;
    $fila += 2;
}

$sheet4->fromArray(['TOTAL GENERAL', $total_gral_cant, $total_gral_monto], null, 'A' . $fila);
estilizarEncabezado($sheet4, 'A' . $fila . ':C' . $fila, $AZUL_CLARO);
$fila += 2;

$sheet4->setCellValue('A' . $fila, 'Nota: excluye Criticos (5+ atrasadas, ver hoja aparte, sin mora) y toma 1 cuota por cliente (la mas antigua pendiente) - puede diferir del Monto Estimado de la Rendicion.');
$sheet4->getStyle('A' . $fila)->getFont()->setItalic(true)->setSize(9);
$sheet4->mergeCells('A' . $fila . ':C' . $fila);
$sheet4->getRowDimension($fila)->setRowHeight(30);
$sheet4->getStyle('A' . $fila)->getAlignment()->setWrapText(true);

$sheet4->getStyle('C:C')->getNumberFormat()->setFormatCode('#,##0.00');
autoAjustar($sheet4, 3);

// ── Salida ───────────────────────────────────────────────────
$spreadsheet->setActiveSheetIndex(0);

$nombre = 'agenda_semanal_' . $cobrador_id . '_' . date('Ymd') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
