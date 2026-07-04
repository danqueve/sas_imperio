<?php
// ============================================================
// creditos/imprimir_cronograma.php — PDF del cronograma FPDF
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();

// FPDF — ruta relativa al sistema existente (mismo servidor)
$fpdf_paths = [
    __DIR__ . '/../../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf182/fpdf.php',
    __DIR__ . '/../../vendor/fpdf/fpdf.php',
    dirname(__DIR__, 3) . '/prestasys/fpdf/fpdf.php',
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
    die('Error: FPDF no encontrado. Copiá la carpeta fpdf/ al directorio raíz del sistema.');
}

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id)
    die('ID inválido');

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
if (!$cr)
    die('Crédito no encontrado');

$cuotas = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas->execute([$id]);
$lista_cuotas = $cuotas->fetchAll();

$combo_stmt = $pdo->prepare("SELECT * FROM ic_credito_articulos WHERE credito_id = ? ORDER BY id");
$combo_stmt->execute([$id]);
$combo_items = $combo_stmt->fetchAll();

// ── Generar PDF ───────────────────────────────────────────────
ob_clean();

function lat_imp(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);

// Header
$pdf->SetFillColor(79, 70, 229);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 12, 'IMPERIO COMERCIAL', 0, 1, 'C', true);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, 'Cronograma de Pagos - Credito #' . $id, 0, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(4);

// Datos del crédito
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(95, 6, 'DATOS DEL CLIENTE:', 0);
$pdf->Cell(95, 6, 'DATOS DEL CREDITO:', 0, 1);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(95, 5, 'Nombre: ' . lat_imp($cr['apellidos'] . ', ' . $cr['nombres']), 0);
if (!$combo_items) {
    $pdf->Cell(95, 5, 'Articulo: ' . lat_imp($cr['articulo']), 0, 1);
} else {
    $pdf->Cell(95, 5, 'Credito Combo (' . count($combo_items) . ' art.)', 0, 1);
}
$pdf->Cell(95, 5, 'DNI: ' . ($cr['dni'] ?: '-'), 0);
if (!$combo_items) {
    $pdf->Cell(95, 5, 'Precio Art.: $ ' . number_format($cr['precio_articulo'], 2, ',', '.'), 0, 1);
} else {
    $pdf->Cell(95, 5, 'Total items: $ ' . number_format($cr['precio_articulo'], 2, ',', '.'), 0, 1);
}
$pdf->Cell(95, 5, 'Tel.: ' . ($cr['telefono'] ?: '-'), 0);
$pdf->Cell(95, 5, 'Total Credito: $ ' . number_format($cr['monto_total'], 2, ',', '.'), 0, 1);
$pdf->Cell(95, 5, lat_imp('Direccion: ' . ($cr['direccion'] ?: '-')), 0);
$pdf->Cell(95, 5, 'Cuotas: ' . $cr['cant_cuotas'] . ' x $ ' . number_format($cr['monto_cuota'], 2, ',', '.') . ' (' . $cr['frecuencia'] . ')', 0, 1);
$pdf->Ln(3);

// Detalle artículos combo
if ($combo_items) {
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(230, 230, 240);
    $pdf->Cell(0, 6, 'ARTICULOS DEL COMBO', 0, 1, 'L', true);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(95, 5, 'Descripcion', 1, 0, 'L', true);
    $pdf->Cell(20, 5, 'Cant.', 1, 0, 'C', true);
    $pdf->Cell(37, 5, 'Precio Unit.', 1, 0, 'R', true);
    $pdf->Cell(38, 5, 'Subtotal', 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', 8);
    $total_items = 0;
    foreach ($combo_items as $ci) {
        $desc = lat_imp(mb_substr($ci['descripcion'], 0, 50));
        $pdf->Cell(95, 5, $desc, 1, 0, 'L');
        $pdf->Cell(20, 5, (int)$ci['cantidad'], 1, 0, 'C');
        $pdf->Cell(37, 5, '$ ' . number_format($ci['precio_unitario'], 2, ',', '.'), 1, 0, 'R');
        $pdf->Cell(38, 5, '$ ' . number_format($ci['subtotal'], 2, ',', '.'), 1, 1, 'R');
        $total_items += (float)$ci['subtotal'];
    }
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(115, 5, '', 0, 0);
    $pdf->Cell(37, 5, 'Total:', 0, 0, 'R');
    $pdf->Cell(38, 5, '$ ' . number_format($total_items, 2, ',', '.'), 0, 1, 'R');
    $pdf->Ln(3);
}

// Tabla de cuotas
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 9);
$cols = ['N°' => 10, 'Vencimiento' => 38, 'Monto Cuota' => 38, 'Mora' => 28, 'Total a Pagar' => 38, 'Estado' => 30];
foreach ($cols as $header => $w) {
    $pdf->Cell($w, 7, iconv('UTF-8', 'ISO-8859-1', $header), 1, 0, 'C', true);
}
$pdf->Ln();
$pdf->SetFont('Arial', '', 8);
$fill = false;
foreach ($lista_cuotas as $c) {
    $dias = dias_atraso_habiles($c['fecha_vencimiento']);
    $mora = calcular_mora($c['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
    $total = $c['monto_cuota'] + $mora;
    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
    $pdf->Cell(10, 6, $c['numero_cuota'], 1, 0, 'C', $fill);
    $pdf->Cell(38, 6, date('d/m/Y', strtotime($c['fecha_vencimiento'])), 1, 0, 'C', $fill);
    $pdf->Cell(38, 6, '$ ' . number_format($c['monto_cuota'], 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell(28, 6, $mora > 0 ? '$ ' . number_format($mora, 2, ',', '.') : '—', 1, 0, 'R', $fill);
    $pdf->Cell(38, 6, '$ ' . number_format($total, 2, ',', '.'), 1, 0, 'R', $fill);
    $pdf->Cell(30, 6, iconv('UTF-8', 'ISO-8859-1', $c['estado']), 1, 1, 'C', $fill);
    $fill = !$fill;
}

// Firma
$pdf->Ln(10);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(95, 5, '_________________________', 0, 0, 'C');
$pdf->Cell(95, 5, '_________________________', 0, 1, 'C');
$pdf->Cell(95, 5, iconv('UTF-8', 'ISO-8859-1', 'Firma del Cliente'), 0, 0, 'C');
$pdf->Cell(95, 5, 'Firma del Cobrador', 0, 1, 'C');
$pdf->Ln(3);
$pdf->SetFont('Arial', 'I', 7);
$pdf->Cell(0, 5, 'Generado el ' . date('d/m/Y H:i') . ' — Imperio Comercial', 0, 0, 'C');

$pdf->Output('I', 'cronograma_credito_' . $id . '.pdf');
exit;
