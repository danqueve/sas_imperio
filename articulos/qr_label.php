<?php
// articulos/qr_label.php — Etiqueta PDF 70x50mm con QR
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);

if (!$id) {
    header('Location: index');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM ic_articulos WHERE id=?");
$stmt->execute([$id]);
$a = $stmt->fetch();

if (!$a) {
    header('Location: index');
    exit;
}

// Contenido del QR: SKU si existe, sino ID
$qr_data = $a['sku'] ?: ('ART-' . $id);

// Generar QR a archivo temporal
$tmp_qr = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_art_' . $id . '_' . time() . '.png';

// Suprimir deprecation warnings de phpqrcode en PHP 8.4
$old_error_reporting = error_reporting(E_ERROR);
require_once __DIR__ . '/../phpqrcode/qrlib.php';
QRcode::png($qr_data, $tmp_qr, QR_ECLEVEL_M, 6, 2);
error_reporting($old_error_reporting);

if (!file_exists($tmp_qr)) {
    die('Error al generar QR.');
}

// Generar PDF con FPDF
require_once __DIR__ . '/../fpdf/fpdf.php';

// Etiqueta 70x50mm landscape (width=70, height=50)
$pdf = new FPDF('L', 'mm', [50, 70]);
$pdf->SetMargins(3, 3, 3);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// QR: 28x28mm, margen izquierdo 3mm, centrado verticalmente (top=(50-28)/2=11)
$pdf->Image($tmp_qr, 3, 11, 28, 28);

// Texto a la derecha del QR (x=33, ancho=34mm)
$x_text  = 33;
$w_text  = 34;
$y_start = 4;

// Descripción (truncada si es muy larga)
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetXY($x_text, $y_start);
$desc_truncada = mb_strlen($a['descripcion']) > 60
    ? mb_substr($a['descripcion'], 0, 57) . '...'
    : $a['descripcion'];
$pdf->MultiCell($w_text, 4.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $desc_truncada), 0, 'L');

$y_after_desc = $pdf->GetY() + 1;

// SKU
if (!empty($a['sku'])) {
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetXY($x_text, $y_after_desc);
    $pdf->Cell($w_text, 4, 'SKU: ' . $a['sku'], 0, 1, 'L');
    $y_after_desc = $pdf->GetY();
}

// Precio Venta (principal)
if ($a['precio_venta']) {
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetXY($x_text, $y_after_desc);
    $precio_fmt = '$ ' . number_format((float)$a['precio_venta'], 2, ',', '.');
    $pdf->Cell($w_text, 6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $precio_fmt), 0, 1, 'L');
    $y_after_desc = $pdf->GetY();
}

// Precio Contado
if ($a['precio_contado']) {
    $pdf->SetFont('Helvetica', '', 6.5);
    $pdf->SetXY($x_text, $y_after_desc);
    $p_fmt = '$ ' . number_format((float)$a['precio_contado'], 2, ',', '.');
    $pdf->Cell($w_text, 3.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Contado: ' . $p_fmt), 0, 1, 'L');
    $y_after_desc = $pdf->GetY();
}

// Precio Tarjeta
if ($a['precio_tarjeta']) {
    $pdf->SetFont('Helvetica', '', 6.5);
    $pdf->SetXY($x_text, $y_after_desc);
    $p_fmt = '$ ' . number_format((float)$a['precio_tarjeta'], 2, ',', '.');
    $pdf->Cell($w_text, 3.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Tarjeta: ' . $p_fmt), 0, 1, 'L');
}

// Pie: nombre empresa centrado
$pdf->SetFont('Helvetica', 'I', 5.5);
$pdf->SetXY(3, 45);
$pdf->Cell(64, 3, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Imperio Comercial'), 0, 0, 'C');

// Limpiar temp
@unlink($tmp_qr);

// Salida inline (abre en nueva pestaña)
$pdf->Output('I', 'etiqueta_art_' . $id . '.pdf');
exit;
