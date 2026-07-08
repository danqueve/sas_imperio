<?php
// ============================================================
// ventas/recibo_pdf.php — Recibo de venta en PDF (A4, B&N)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('registrar_ventas');

$pdo = obtener_conexion();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) die('ID de venta inválido.');

$stmt = $pdo->prepare("
    SELECT v.*,
           a.sku,
           vd.nombre   AS vendedor_nombre,
           vd.apellido AS vendedor_apellido
    FROM ic_ventas v
    LEFT JOIN ic_articulos  a  ON a.id  = v.articulo_id
    LEFT JOIN ic_vendedores vd ON vd.id = v.vendedor_id
    WHERE v.id = ?
");
$stmt->execute([$id]);
$v = $stmt->fetch();
if (!$v) die('Recibo no encontrado.');

// ── Datos empresa ──────────────────────────────────────────
define('EMP_RAZON',    'IMPERIO COMERCIAL S.A.S');
define('EMP_CUIT',     '30-71907246-8');
define('EMP_IB',       '30719072468');
define('EMP_INICIO',   '01/08/2025');

// ── Cálculos ───────────────────────────────────────────────
$precio_unit = (float)$v['precio_venta'];
$cantidad    = (int)$v['cantidad'];
$total       = $precio_unit * $cantidad;
$forma_pago  = ucfirst($v['forma_pago']);
$vendedor    = trim(($v['vendedor_nombre'] ?? '') . ' ' . ($v['vendedor_apellido'] ?? '')) ?: '—';
$obs         = trim($v['observaciones'] ?? '') ?: '—';
$fecha       = date('d/m/Y', strtotime($v['fecha_venta']));
$nro_recibo  = str_pad($id, 8, '0', STR_PAD_LEFT);

function fmtv(float $n): string {
    return '$ ' . number_format($n, 2, ',', '.');
}

// ── PDF ────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';
ob_clean();

$pdf = new PDFBase('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

$W = 180; // usable width (210 - 15*2)
$X = 15;  // left margin

// ── Bloque empresa + número de recibo ──────────────────────
$pdf->SetLineWidth(0.3);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetTextColor(0, 0, 0);

// Nombre empresa
$pdf->SetXY($X, 15);
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->Cell($W * 0.6, 8, lat(EMP_RAZON), 0, 0, 'L');

// Nro. recibo (alineado a la derecha)
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell($W * 0.4, 8, lat('RECIBO N° ' . $nro_recibo), 0, 1, 'R');

// Datos fiscales izquierda + fecha derecha
$pdf->SetFont('Helvetica', '', 8.5);
$pdf->SetX($X);
$pdf->Cell($W * 0.6, 5, lat('CUIT: ' . EMP_CUIT), 0, 0, 'L');
$pdf->Cell($W * 0.4, 5, lat('Fecha: ' . $fecha), 0, 1, 'R');

$pdf->SetX($X);
$pdf->Cell($W * 0.6, 5, lat('Ingresos Brutos: ' . EMP_IB), 0, 1, 'L');

$pdf->SetX($X);
$pdf->Cell($W * 0.6, 5, lat('Inicio de Actividades: ' . EMP_INICIO), 0, 1, 'L');

// Línea separadora
$pdf->Ln(3);
$yLine = $pdf->GetY();
$pdf->SetLineWidth(0.5);
$pdf->Line($X, $yLine, $X + $W, $yLine);
$pdf->Ln(4);

// ── CONSUMIDOR FINAL ───────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetX($X);
$pdf->Cell($W, 8, lat('CONSUMIDOR FINAL'), 1, 1, 'C');
$pdf->Ln(4);

// ── Tabla de artículo ─────────────────────────────────────
// Encabezados: Descripción(100) Cant(15) P.Unit(32) Total(33)
$c = [100, 15, 32, 33];
$pdf->SetFont('Helvetica', 'B', 8.5);
$pdf->SetX($X);
$pdf->Cell($c[0], 7, lat('Descripcion'),   1, 0, 'L');
$pdf->Cell($c[1], 7, lat('Cant.'),         1, 0, 'C');
$pdf->Cell($c[2], 7, lat('Precio Unit.'),  1, 0, 'R');
$pdf->Cell($c[3], 7, lat('Total'),         1, 1, 'R');

// Fila artículo
$pdf->SetFont('Helvetica', '', 8.5);
$pdf->SetX($X);
$desc = $pdf->fitText($v['articulo_desc'], $c[0] - 2);
$pdf->Cell($c[0], 7, $desc,                      1, 0, 'L');
$pdf->Cell($c[1], 7, lat((string)$cantidad),      1, 0, 'C');
$pdf->Cell($c[2], 7, lat(fmtv($precio_unit)),     1, 0, 'R');
$pdf->Cell($c[3], 7, lat(fmtv($total)),           1, 1, 'R');

// Fila TOTAL
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetX($X);
$pdf->Cell($c[0] + $c[1] + $c[2], 7, lat('TOTAL'), 1, 0, 'R');
$pdf->Cell($c[3], 7, lat(fmtv($total)),             1, 1, 'R');

$pdf->Ln(5);

// ── Datos adicionales ──────────────────────────────────────
$pdf->SetLineWidth(0.3);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetX($X);
$pdf->Cell($W * 0.5, 6, lat('Forma de Pago: ' . $forma_pago), 0, 0, 'L');
$pdf->Cell($W * 0.5, 6, lat('Vendedor: ' . $vendedor),        0, 1, 'R');

if ($obs !== '—') {
    $pdf->SetX($X);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell($W, 6, lat('Observaciones: ' . $obs), 0, 1, 'L');
}

// Línea separadora
$pdf->Ln(4);
$yLine2 = $pdf->GetY();
$pdf->SetLineWidth(0.5);
$pdf->Line($X, $yLine2, $X + $W, $yLine2);
$pdf->Ln(5);

// ── Mensaje cierre ──────────────────────────────────────────
$pdf->SetFont('Helvetica', 'I', 10);
$pdf->SetX($X);
$pdf->Cell($W, 7, lat('¡Gracias por su compra!'), 0, 1, 'C');

$pdf->Output('I', lat('recibo_venta_' . $nro_recibo . '.pdf'));
exit;
