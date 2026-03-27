<?php
// ============================================================
// articulos/stock_pdf.php — PDF Reporte de Stock
// A4 landscape, tabular, stock coloreado
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo = obtener_conexion();

// Filtros opcionales heredados del index
$categoria    = trim($_GET['categoria'] ?? '');
$stock_filtro = trim($_GET['stock_filtro'] ?? '');

$where  = ['a.activo = 1'];
$params = [];
if ($categoria !== '') {
    $where[]  = 'a.categoria = ?';
    $params[] = $categoria;
}
if ($stock_filtro === 'sin_stock') {
    $where[] = 'a.stock = 0';
} elseif ($stock_filtro === 'stock_bajo') {
    $where[] = 'a.stock BETWEEN 1 AND 4';
}
$whereStr = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT a.*
    FROM ic_articulos a
    WHERE $whereStr
    ORDER BY a.categoria, a.descripcion
");
$stmt->execute($params);
$lista = $stmt->fetchAll();

if (empty($lista)) {
    die('No hay artículos activos para exportar.');
}

// ── Totales ──────────────────────────────────────────────────
$total_sin_stock  = 0;
$total_stock_bajo = 0;
$total_unidades   = 0;
foreach ($lista as $a) {
    $st = (int)$a['stock'];
    $total_unidades += $st;
    if ($st === 0) $total_sin_stock++;
    elseif ($st <= 4) $total_stock_bajo++;
}

// ── Helpers ──────────────────────────────────────────────────
function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmtp(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas (A4 landscape = 277mm usable a 10mm margen c/lado)
// SKU(22) + Descripcion(75) + Categoria(38) + P.Venta(28) + Contado(28) + Tarjeta(28) + Stock(18) = 237
$COLS   = [22, 75, 38, 28, 28, 28, 18];
$LABELS = ['SKU', 'Descripcion', 'Categoria', 'P. Venta', 'Contado', 'Tarjeta', 'Stock'];
$ALIGNS = ['L', 'L', 'L', 'R', 'R', 'R', 'C'];
$ANCHO  = array_sum($COLS);

class StockPDF extends FPDF
{
    public string $titulo_filtro   = '';
    public string $fecha_impresion = '';
    public int    $total_arts      = 0;
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    function Header()
    {
        $aw = array_sum($this->cols);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell($aw, 7, lat('Imperio Comercial - Reporte de Stock'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $hw = intval($aw / 2);
        $subtitulo = $this->titulo_filtro ?: 'Todos los articulos activos';
        $this->Cell($hw, 5, lat($subtitulo), 0, 0, 'L');
        $this->Cell($aw - $hw, 5, lat($this->fecha_impresion . '  |  ' . $this->total_arts . ' articulos'), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 10 + $aw, $this->GetY() + 1);
        $this->Ln(4);

        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(40, 40, 40);
        $this->SetTextColor(255, 255, 255);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 8);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function fitText(string $text, float $maxW): string
    {
        $enc    = lat($text);
        $suffix = '..';
        if ($this->GetStringWidth($enc) <= $maxW) return $enc;
        $sw = $this->GetStringWidth($suffix);
        while (mb_strlen($text) > 1) {
            $text = mb_substr($text, 0, -1);
            $enc  = lat($text);
            if ($this->GetStringWidth($enc . $suffix) <= $maxW) return $enc . $suffix;
        }
        return $enc;
    }
}

$pdf = new StockPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 16);
$pdf->SetMargins(10, 10, 10);
$pdf->cols            = $COLS;
$pdf->labels          = $LABELS;
$pdf->aligns          = $ALIGNS;
$pdf->total_arts      = count($lista);
$pdf->fecha_impresion = lat('Generado: ' . date('d/m/Y H:i'));
if ($categoria) $pdf->titulo_filtro = lat('Categoria: ' . $categoria);
if ($stock_filtro === 'sin_stock')  $pdf->titulo_filtro .= ($pdf->titulo_filtro ? ' | ' : '') . 'Sin stock';
if ($stock_filtro === 'stock_bajo') $pdf->titulo_filtro .= ($pdf->titulo_filtro ? ' | ' : '') . 'Stock bajo (1-4)';

$pdf->AddPage();
$pdf->SetFont('Helvetica', '', 8);

$cat_actual = null;

foreach ($lista as $a) {
    // Separador de categoría
    if ($a['categoria'] !== $cat_actual) {
        $cat_actual = $a['categoria'];
        if ($cat_actual) {
            $pdf->SetFillColor(60, 60, 80);
            $pdf->SetTextColor(220, 220, 255);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetX(10);
            $pdf->Cell($ANCHO, 5, lat('  ' . strtoupper($cat_actual)), 0, 1, 'L', true);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    $st = (int)$a['stock'];
    if ($st === 0) {
        $pdf->SetFillColor(255, 220, 220); $fill = true;
    } elseif ($st <= 4) {
        $pdf->SetFillColor(255, 240, 200); $fill = true;
    } else {
        $fill = false;
    }

    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 6, $pdf->fitText($a['sku'] ?: '-', $COLS[0] - 1), 1, 0, 'L', $fill);
    $pdf->Cell($COLS[1], 6, $pdf->fitText($a['descripcion'], $COLS[1] - 1), 1, 0, 'L', $fill);
    $pdf->Cell($COLS[2], 6, $pdf->fitText($a['categoria'] ?: '-', $COLS[2] - 1), 1, 0, 'L', $fill);
    $pdf->Cell($COLS[3], 6, lat($a['precio_venta']  ? fmtp((float)$a['precio_venta'])  : '-'), 1, 0, 'R', $fill);
    $pdf->Cell($COLS[4], 6, lat($a['precio_contado'] ? fmtp((float)$a['precio_contado']) : '-'), 1, 0, 'R', $fill);
    $pdf->Cell($COLS[5], 6, lat($a['precio_tarjeta'] ? fmtp((float)$a['precio_tarjeta']) : '-'), 1, 0, 'R', $fill);
    $pdf->Cell($COLS[6], 6, lat((string)$st), 1, 1, 'C', $fill);
}

// ── Resumen final ────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetX(10);
$pdf->SetFillColor(230, 230, 240);
$pdf->Cell($ANCHO, 6, lat(
    'Total: ' . count($lista) . ' art.  |  Unidades en stock: ' . number_format($total_unidades) .
    '  |  Sin stock: ' . $total_sin_stock . '  |  Stock bajo (1-4): ' . $total_stock_bajo
), 1, 1, 'C', true);

$pdf->Output('I', 'stock_articulos_' . date('Ymd') . '.pdf');
