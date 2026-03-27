<?php
// ============================================================
// articulos/creditos_pdf.php — PDF Clientes por Artículo
// A4 landscape, agrupado por artículo, muestra clientes con crédito
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo    = obtener_conexion();
$art_id = (int)($_GET['art_id'] ?? 0);

$where  = ['cr.articulo_id IS NOT NULL'];
$params = [];
if ($art_id > 0) {
    $where[]  = 'cr.articulo_id = ?';
    $params[] = $art_id;
}
$whereStr = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT a.id AS art_id,
           a.descripcion AS art_descripcion,
           a.sku,
           cr.id          AS credito_id,
           cr.fecha_alta,
           cr.monto_total,
           cr.estado,
           cl.nombres,
           cl.apellidos,
           cl.dni,
           u.nombre   AS cobrador_nombre,
           u.apellido AS cobrador_apellido
    FROM ic_creditos cr
    JOIN ic_articulos a  ON cr.articulo_id = a.id
    JOIN ic_clientes  cl ON cr.cliente_id  = cl.id
    LEFT JOIN ic_usuarios u ON cr.cobrador_id = u.id
    WHERE $whereStr
    ORDER BY a.descripcion, cr.fecha_alta DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    die('No hay creditos con articulo asignado para exportar.');
}

// ── Helpers ──────────────────────────────────────────────────
function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmtp(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}
function estado_texto(string $e): string {
    $map = [
        'EN_CURSO'   => 'En curso',
        'FINALIZADO' => 'Finalizado',
        'MOROSO'     => 'Moroso',
        'CANCELADO'  => 'Cancelado',
    ];
    return $map[$e] ?? $e;
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos A4 landscape — total 237mm
// Fecha(22) + Cliente(65) + DNI(22) + Cobrador(38) + Monto(28) + Estado(30) + Credito#(12) = 217
$COLS   = [22, 65, 22, 38, 28, 30, 12];
$LABELS = ['Fecha', 'Cliente', 'DNI', 'Cobrador', 'Monto Total', 'Estado', '#Cred'];
$ALIGNS = ['C', 'L', 'C', 'L', 'R', 'C', 'C'];
$ANCHO  = array_sum($COLS);

class CreditosPDF extends FPDF
{
    public string $fecha_impresion = '';
    public int    $total_creditos  = 0;
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    function Header()
    {
        $aw = array_sum($this->cols);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell($aw, 7, lat('Imperio Comercial - Clientes por Articulo'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $hw = intval($aw / 2);
        $this->Cell($hw, 5, lat('Creditos agrupados por articulo'), 0, 0, 'L');
        $this->Cell($aw - $hw, 5, lat($this->fecha_impresion . '  |  Total: ' . $this->total_creditos . ' creditos'), 0, 1, 'R');

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

$pdf = new CreditosPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 16);
$pdf->SetMargins(10, 10, 10);
$pdf->cols            = $COLS;
$pdf->labels          = $LABELS;
$pdf->aligns          = $ALIGNS;
$pdf->total_creditos  = count($rows);
$pdf->fecha_impresion = lat('Generado: ' . date('d/m/Y H:i'));

$pdf->AddPage();
$pdf->SetFont('Helvetica', '', 8);

$art_actual   = null;
$cnt_art      = 0;
$subtotal_art = 0.0;

foreach ($rows as $r) {
    // ── Encabezado de artículo ───────────────────────────────
    if ($r['art_id'] !== $art_actual) {
        // Subtotal del artículo anterior
        if ($art_actual !== null && $cnt_art > 0) {
            $pdf->SetFont('Helvetica', 'I', 7.5);
            $pdf->SetFillColor(230, 230, 240);
            $pdf->SetX(10);
            $pdf->Cell($ANCHO, 5, lat('  Subtotal: ' . $cnt_art . ' credito' . ($cnt_art !== 1 ? 's' : '') . '  |  ' . fmtp($subtotal_art)), 0, 1, 'R', true);
            $pdf->Ln(2);
        }

        $art_actual   = $r['art_id'];
        $cnt_art      = 0;
        $subtotal_art = 0.0;

        // Fila de título del artículo
        $sku_label = $r['sku'] ? ' [' . $r['sku'] . ']' : '';
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(55, 55, 75);
        $pdf->SetTextColor(210, 210, 255);
        $pdf->SetX(10);
        $pdf->Cell($ANCHO, 7, lat('  ' . $r['art_descripcion'] . $sku_label), 0, 1, 'L', true);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
    }

    $cnt_art++;
    $subtotal_art += (float)$r['monto_total'];

    // Color de fila según estado
    switch ($r['estado']) {
        case 'MOROSO':
            $pdf->SetFillColor(255, 220, 220); $fill = true; break;
        case 'CANCELADO':
            $pdf->SetFillColor(240, 240, 240); $fill = true; break;
        case 'FINALIZADO':
            $pdf->SetFillColor(220, 240, 220); $fill = true; break;
        default:
            $fill = false;
    }

    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 6, lat(date('d/m/Y', strtotime($r['fecha_alta']))), 1, 0, 'C', $fill);
    $pdf->Cell($COLS[1], 6, $pdf->fitText($r['apellidos'] . ', ' . $r['nombres'], $COLS[1] - 1), 1, 0, 'L', $fill);
    $pdf->Cell($COLS[2], 6, lat($r['dni'] ?: '-'), 1, 0, 'C', $fill);
    $cobrador = $r['cobrador_nombre'] ? $r['cobrador_nombre'] . ' ' . $r['cobrador_apellido'] : '-';
    $pdf->Cell($COLS[3], 6, $pdf->fitText($cobrador, $COLS[3] - 1), 1, 0, 'L', $fill);
    $pdf->Cell($COLS[4], 6, lat(fmtp((float)$r['monto_total'])), 1, 0, 'R', $fill);
    $pdf->Cell($COLS[5], 6, lat(estado_texto($r['estado'])), 1, 0, 'C', $fill);
    $pdf->Cell($COLS[6], 6, lat('#' . $r['credito_id']), 1, 1, 'C', $fill);
}

// Subtotal del último artículo
if ($cnt_art > 0) {
    $pdf->SetFont('Helvetica', 'I', 7.5);
    $pdf->SetFillColor(230, 230, 240);
    $pdf->SetX(10);
    $pdf->Cell($ANCHO, 5, lat('  Subtotal: ' . $cnt_art . ' credito' . ($cnt_art !== 1 ? 's' : '') . '  |  ' . fmtp($subtotal_art)), 0, 1, 'R', true);
}

// ── Total general ────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(40, 40, 40);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetX(10);
$total_monto = array_sum(array_column($rows, 'monto_total'));
$pdf->Cell($ANCHO, 7, lat('  TOTAL: ' . count($rows) . ' creditos  |  ' . fmtp((float)$total_monto)), 1, 1, 'R', true);
$pdf->SetTextColor(0, 0, 0);

$pdf->Output('I', 'creditos_por_articulo_' . date('Ymd') . '.pdf');
