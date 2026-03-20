<?php
// ============================================================
// creditos/resumen_pdf.php — PDF Resumen completo de un crédito
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

// FPDF — buscar en rutas comunes
$fpdf_paths = [
    __DIR__ . '/../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf182/fpdf.php',
    __DIR__ . '/../../vendor/fpdf/fpdf.php',
];
$fpdf_found = false;
foreach ($fpdf_paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $fpdf_found = true;
        break;
    }
}
if (!$fpdf_found) {
    die('Error: FPDF no encontrado.');
}

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) die('ID invalido');

// ── Datos principales ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.dni, cl.telefono, cl.direccion,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    WHERE cr.id=?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) die('Credito no encontrado');

// ── Cuotas ───────────────────────────────────────────────────
$cuotas_stmt = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas_stmt->execute([$id]);
$lista_cuotas = $cuotas_stmt->fetchAll();

// Calcular mora dinámica
foreach ($lista_cuotas as &$cuota) {
    if (in_array($cuota['estado'], ['PENDIENTE', 'VENCIDA', 'PARCIAL'])) {
        $dias = dias_atraso_habiles($cuota['fecha_vencimiento']);
        $cuota['dias_atraso_calc'] = $dias;
        $cuota['mora_calc'] = (float)$cuota['monto_mora'] > 0
            ? (float)$cuota['monto_mora']
            : calcular_mora($cuota['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
    } else {
        $cuota['dias_atraso_calc'] = 0;
        $cuota['mora_calc'] = $cuota['estado'] === 'CAP_PAGADA' ? (float)$cuota['monto_mora'] : 0;
    }
}
unset($cuota);

// ── Historial de pagos confirmados ────────────────────────────
$hist_stmt = $pdo->prepare("
    SELECT cu.numero_cuota, pt.fecha_jornada, pt.monto_efectivo,
           pt.monto_transferencia, pt.monto_mora_cobrada, pt.monto_total,
           CONCAT(u.nombre, ' ', u.apellido) AS cobrador_nombre
    FROM ic_pagos_confirmados pc
    JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    JOIN ic_cuotas cu           ON cu.id = pc.cuota_id
    JOIN ic_usuarios u          ON u.id  = pt.cobrador_id
    WHERE cu.credito_id = ?
    ORDER BY pt.fecha_jornada ASC, pc.id ASC
");
$hist_stmt->execute([$id]);
$historial_pagos = $hist_stmt->fetchAll();

// Totales
$pagadas          = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PAGADA'));
$total_cuotas_cnt = count($lista_cuotas);
$mora_pendiente   = array_sum(array_map(fn($c) => $c['mora_calc'], $lista_cuotas));
$capital_pend     = array_sum(array_map(
    fn($c) => max(0, (float)$c['monto_cuota'] - (float)($c['saldo_pagado'] ?? 0)),
    array_filter($lista_cuotas, fn($c) => !in_array($c['estado'], ['PAGADA']))
));
$hist_total_ef    = array_sum(array_column($historial_pagos, 'monto_efectivo'));
$hist_total_tr    = array_sum(array_column($historial_pagos, 'monto_transferencia'));
$hist_total_mora  = array_sum(array_column($historial_pagos, 'monto_mora_cobrada'));
$hist_total       = array_sum(array_column($historial_pagos, 'monto_total'));

function lat($s) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s);
}
function pesos($v) {
    return '$ ' . number_format((float)$v, 2, ',', '.');
}

// ── Generar PDF ───────────────────────────────────────────────
ob_clean();
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle(lat('Resumen Credito #' . $id . ' - ' . $cr['apellidos']));
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

// ─── ENCABEZADO ───────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell(0, 10, 'IMPERIO COMERCIAL', 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, lat('Resumen de Credito #') . $id, 'B', 1, 'C');
$pdf->Ln(4);

// ─── DATOS DEL CRÉDITO ───────────────────────────────────────
$pdf->SetFillColor(243, 244, 246);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 7, 'DATOS DEL CREDITO', 0, 1, 'L', true);
$pdf->SetFont('Arial', '', 9);

$left_w = 95;
$pdf->Cell($left_w, 5, 'Cliente: ' . lat($cr['apellidos'] . ', ' . $cr['nombres']), 0);
$pdf->Cell($left_w, 5, lat('Articulo: ') . lat($cr['articulo']), 0, 1);

$pdf->Cell($left_w, 5, 'DNI: ' . ($cr['dni'] ?: '—'), 0);
$pdf->Cell($left_w, 5, 'Estado: ' . $cr['estado'], 0, 1);

$pdf->Cell($left_w, 5, lat('Tel.: ') . ($cr['telefono'] ?: '—'), 0);
$pdf->Cell($left_w, 5, 'Precio art.: ' . pesos($cr['precio_articulo']), 0, 1);

$pdf->Cell($left_w, 5, lat('Direccion: ') . lat($cr['direccion'] ?: '—'), 0);
$pdf->Cell($left_w, 5, 'Monto total: ' . pesos($cr['monto_total']), 0, 1);

$pdf->Cell($left_w, 5, 'Cobrador: ' . lat($cr['cobrador_n'] . ' ' . $cr['cobrador_a']), 0);
$pdf->Cell($left_w, 5, 'Cuota: ' . pesos($cr['monto_cuota']) . ' (' . $cr['frecuencia'] . ')', 0, 1);

$pdf->Cell($left_w, 5, 'Alta: ' . date('d/m/Y', strtotime($cr['fecha_alta'])), 0);
$pdf->Cell($left_w, 5, '1er venc.: ' . date('d/m/Y', strtotime($cr['primer_vencimiento'])), 0, 1);

$pdf->Cell($left_w, 5, lat('Interes: ') . $cr['interes_pct'] . lat('%'), 0);
$pdf->Cell($left_w, 5, 'Mora/sem.: ' . $cr['interes_moratorio_pct'] . lat('%'), 0, 1);

if (!empty($cr['veces_refinanciado']) && (int)$cr['veces_refinanciado'] > 0) {
    $pdf->Cell(0, 5, 'Refinanciaciones: ' . (int)$cr['veces_refinanciado'], 0, 1);
}

$pdf->Ln(5);

// ─── CRONOGRAMA ───────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(243, 244, 246);
$pdf->Cell(0, 7, 'CRONOGRAMA DE CUOTAS', 0, 1, 'L', true);

$pdf->SetFont('Arial', 'B', 7.5);
// Cols totales 190mm: #10, Venc28, Monto30, Mora28, Total30, Estado25, Abonado30, FechaPago29
$cols = ['#' => 10, 'Vencimiento' => 28, 'Monto' => 28, 'Mora' => 26, 'Total' => 28, 'Estado' => 26, 'Abonado' => 26, 'F.Pago' => 18];
foreach ($cols as $h => $w) {
    $pdf->Cell($w, 7, lat($h), 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Arial', '', 7.5);
$fill = false;
foreach ($lista_cuotas as $q) {
    $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
    $mora_str = $q['mora_calc'] > 0 ? pesos($q['mora_calc']) : '';
    $total_c  = (float)$q['monto_cuota'] + $q['mora_calc'];
    $abonado  = '';
    $fecha_p  = '';
    if ($q['estado'] === 'PAGADA') {
        $abonado = pesos($q['saldo_pagado'] ?? $q['monto_cuota']);
        $fecha_p = $q['fecha_pago'] ? date('d/m/Y', strtotime($q['fecha_pago'])) : '';
    } elseif (in_array($q['estado'], ['PARCIAL', 'CAP_PAGADA']) && !empty($q['saldo_pagado'])) {
        $abonado = pesos($q['saldo_pagado']);
    }
    $pdf->Cell(10, 6, $q['numero_cuota'], 1, 0, 'C', $fill);
    $pdf->Cell(28, 6, date('d/m/Y', strtotime($q['fecha_vencimiento'])), 1, 0, 'C', $fill);
    $pdf->Cell(28, 6, pesos($q['monto_cuota']), 1, 0, 'R', $fill);
    $pdf->Cell(26, 6, lat($mora_str), 1, 0, 'R', $fill);
    $pdf->Cell(28, 6, pesos($total_c), 1, 0, 'R', $fill);
    $pdf->Cell(26, 6, lat($q['estado']), 1, 0, 'C', $fill);
    $pdf->Cell(26, 6, lat($abonado), 1, 0, 'R', $fill);
    $pdf->Cell(18, 6, lat($fecha_p), 1, 1, 'C', $fill);
    $fill = !$fill;
}
$pdf->Ln(5);

// ─── HISTORIAL DE PAGOS ───────────────────────────────────────
if (!empty($historial_pagos)) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(243, 244, 246);
    $pdf->Cell(0, 7, 'HISTORIAL DE PAGOS CONFIRMADOS', 0, 1, 'L', true);

    $pdf->SetFont('Arial', 'B', 7.5);
    // Cols: Cuota12, Fecha25, Efectivo30, Transfer30, Mora28, Total30, Cobrador35
    $hcols = ['Cuota' => 12, 'Fecha' => 25, 'Efectivo' => 28, 'Transf.' => 28, 'Mora' => 24, 'Total' => 28, 'Cobrador' => 45];
    foreach ($hcols as $h => $w) {
        $pdf->Cell($w, 7, lat($h), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 7.5);
    $fill = false;
    foreach ($historial_pagos as $h) {
        $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
        $pdf->Cell(12, 6, '#' . $h['numero_cuota'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, date('d/m/Y', strtotime($h['fecha_jornada'])), 1, 0, 'C', $fill);
        $pdf->Cell(28, 6, $h['monto_efectivo'] > 0 ? pesos($h['monto_efectivo']) : '—', 1, 0, 'R', $fill);
        $pdf->Cell(28, 6, $h['monto_transferencia'] > 0 ? pesos($h['monto_transferencia']) : '—', 1, 0, 'R', $fill);
        $pdf->Cell(24, 6, $h['monto_mora_cobrada'] > 0 ? pesos($h['monto_mora_cobrada']) : '—', 1, 0, 'R', $fill);
        $pdf->Cell(28, 6, pesos($h['monto_total']), 1, 0, 'R', $fill);
        $pdf->Cell(45, 6, lat(mb_substr($h['cobrador_nombre'], 0, 20)), 1, 1, 'L', $fill);
        $fill = !$fill;
    }

    // Totales historial
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(12 + 25, 6, 'TOTAL', 1, 0, 'R', true);
    $pdf->Cell(28, 6, pesos($hist_total_ef), 1, 0, 'R', true);
    $pdf->Cell(28, 6, pesos($hist_total_tr), 1, 0, 'R', true);
    $pdf->Cell(24, 6, $hist_total_mora > 0 ? pesos($hist_total_mora) : '—', 1, 0, 'R', true);
    $pdf->Cell(28, 6, pesos($hist_total), 1, 0, 'R', true);
    $pdf->Cell(45, 6, '', 1, 1, 'L', true);
    $pdf->Ln(5);
}

// ─── RESUMEN FINANCIERO ───────────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(243, 244, 246);
$pdf->Cell(0, 7, 'RESUMEN FINANCIERO', 0, 1, 'L', true);
$pdf->SetFont('Arial', '', 9);

$porc = $total_cuotas_cnt > 0 ? round($pagadas / $total_cuotas_cnt * 100) : 0;

$resumen = [
    ['Cuotas pagadas',      $pagadas . ' de ' . $total_cuotas_cnt . ' (' . $porc . '%)'],
    ['Total cobrado',       pesos($hist_total)],
    ['- del cual: efectivo',pesos($hist_total_ef)],
    ['- del cual: transf.', pesos($hist_total_tr)],
    ['- del cual: mora',    pesos($hist_total_mora)],
    ['Capital pendiente',   pesos($capital_pend)],
    ['Mora acumulada',      pesos($mora_pendiente)],
    ['Total si paga hoy',   pesos($capital_pend + $mora_pendiente)],
];
$fill = false;
foreach ($resumen as [$label, $value]) {
    $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
    $pdf->Cell(95, 6, lat($label), 1, 0, 'L', $fill);
    $pdf->Cell(95, 6, lat($value), 1, 1, 'R', $fill);
    $fill = !$fill;
}

// ─── FOOTER ───────────────────────────────────────────────────
$pdf->SetY(-20);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(107, 114, 128);
$pdf->Cell(0, 5, lat('Generado el ') . date('d/m/Y H:i') . lat(' — Imperio Comercial'), 0, 1, 'C');
$pdf->Cell(0, 5, lat('Pagina ') . $pdf->PageNo(), 0, 0, 'C');

$nombre_archivo = 'resumen_credito_' . $id . '_' . str_replace(' ', '_', $cr['apellidos']) . '.pdf';
$pdf->Output('D', lat($nombre_archivo));
exit;
