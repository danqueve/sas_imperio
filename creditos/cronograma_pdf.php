<?php
// ============================================================
// creditos/cronograma_pdf.php — PDF del cronograma FPDF
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
$id = (int) ($_GET['id'] ?? 0);
if (!$id) die('ID inválido');

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
if (!$cr) die('Crédito no encontrado');

$cuotas = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas->execute([$id]);
$lista_cuotas = $cuotas->fetchAll();

function lat($s) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}

// ── Generar PDF ───────────────────────────────────────────────
ob_clean();
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle('Cronograma - ' . $cr['apellidos']);
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

// Header
$pdf->SetTextColor(0, 0, 0); // Texto negro
$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 12, 'IMPERIO COMERCIAL', 0, 1, 'C', false);
$pdf->SetFont('Arial', '', 11);
// Agregamos un borde inferior ('B') para separar elegantemente el título del contenido, ya que no hay fondo oscuro
$pdf->Cell(0, 8, lat('Cronograma de Pagos - Crédito #') . $id, 'B', 1, 'C', false);
$pdf->Ln(6);

// Datos del crédito
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(95, 6, 'DATOS DEL CLIENTE:', 0);
$pdf->Cell(95, 6, 'DATOS DEL CREDITO:', 0, 1);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(95, 5, 'Nombre: ' . lat($cr['apellidos'] . ', ' . $cr['nombres']), 0);
$pdf->Cell(95, 5, 'Articulo: ' . lat($cr['articulo']), 0, 1);
$pdf->Cell(95, 5, 'DNI: ' . ($cr['dni'] ?: '—'), 0);
$pdf->Cell(95, 5, 'Total Credito: $ ' . number_format($cr['monto_total'], 2, ',', '.'), 0, 1);
$pdf->Cell(95, 5, 'Tel.: ' . ($cr['telefono'] ?: '—'), 0);
$pdf->Cell(95, 5, 'Cuotas: ' . $cr['cant_cuotas'] . ' x $ ' . number_format($cr['monto_cuota'], 2, ',', '.') . ' (' . $cr['frecuencia'] . ')', 0, 1);
$pdf->Cell(95, 5, lat('Dirección: ' . ($cr['direccion'] ?: '—')), 0);
$pdf->Cell(95, 5, 'Cobrador: ' . lat($cr['cobrador_n'] . ' ' . $cr['cobrador_a']), 0, 1);
$pdf->Ln(6);

// Tabla de cuotas
$pdf->SetFillColor(243, 244, 246);
$pdf->SetFont('Arial', 'B', 8);
// Cols: Num(10), Venc(25), Monto(30), Estado(25), Abonado(30), Fecha Pago(40) -> Total 160. Margen 25 total (12.5 cada lado)
// Ajustamos a 190mm total (10mm margenes)
$cols = ['#' => 10, 'Vencimiento' => 30, 'Monto Base' => 35, 'Estado' => 30, 'Abonado' => 35, 'Fecha Pago' => 50];
foreach ($cols as $header => $w) {
    $pdf->Cell($w, 8, lat($header), 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Arial', '', 8);
$fill = false;
foreach ($lista_cuotas as $q) {
    $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
    
    $pdf->Cell(10, 7, $q['numero_cuota'], 1, 0, 'C', $fill);
    $pdf->Cell(30, 7, date('d/m/Y', strtotime($q['fecha_vencimiento'])), 1, 0, 'C', $fill);
    $pdf->Cell(35, 7, '$ ' . number_format($q['monto_cuota'], 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell(30, 7, lat($q['estado']), 1, 0, 'C', $fill);
    
    $abonado = '';
    if ($q['estado'] === 'PAGADA') {
        $abonado = '$ ' . number_format($q['monto_cuota'] + (float)$q['monto_mora'], 2, ',', '.');
    } elseif ($q['estado'] === 'PARCIAL') {
        $abonado = '$ ' . number_format((float)$q['saldo_pagado'], 2, ',', '.');
    }
    $pdf->Cell(35, 7, lat($abonado), 1, 0, 'R', $fill);
    
    $fecha_pago = $q['fecha_pago'] ? date('d/m/Y H:i', strtotime($q['fecha_pago'])) : '';
    $pdf->Cell(50, 7, lat($fecha_pago), 1, 1, 'C', $fill);
    
    $fill = !$fill;
}

// Resumen / Progreso
$pdf->Ln(5);
$pagadas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PAGADA'));
$total_cuotas = count($lista_cuotas);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 7, lat('Progreso: ') . $pagadas . ' de ' . $total_cuotas . lat(' cuotas abonadas.'), 0, 1, 'L');

if ($cr['estado'] === 'FINALIZADO') {
    $pdf->SetTextColor(16, 185, 129);
    $pdf->Cell(0, 7, lat('CRÉDITO SALDADO DENTRO DE LOS TÉRMINOS.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

// Footer simple
$pdf->SetY(-20);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(107, 114, 128);
$pdf->Cell(0, 5, lat('Generado el ') . date('d/m/Y H:i') . lat(' — Imperio Comercial'), 0, 1, 'C');
$pdf->Cell(0, 5, lat('Página ') . $pdf->PageNo(), 0, 0, 'C');

$nombre_archivo = 'cronograma_' . str_replace(' ', '_', $cr['apellidos']) . '_' . $id . '.pdf';
$pdf->Output('I', lat($nombre_archivo));
exit;
