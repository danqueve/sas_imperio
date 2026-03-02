<?php
// ============================================================
// admin/rendicion_pdf.php — Exportación PDF con FPDF
// A4 vertical, blanco y negro, sin rellenos (ahorro de tinta)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo         = obtener_conexion();
$fecha_sel   = $_GET['fecha']       ?? date('Y-m-d', strtotime('-1 day'));
$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);

if (!$cobrador_id) die('Cobrador no especificado.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

$dstmt = $pdo->prepare("
    SELECT pt.*,
           cl.nombres, cl.apellidos,
           cu.numero_cuota, cu.fecha_vencimiento,
           a.descripcion AS articulo
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas cu   ON pt.cuota_id     = cu.id
    JOIN ic_creditos cr ON cu.credito_id   = cr.id
    JOIN ic_clientes cl ON cr.cliente_id   = cl.id
    JOIN ic_articulos a ON cr.articulo_id  = a.id
    WHERE pt.cobrador_id = ? AND DATE(pt.fecha_registro) = ? AND pt.estado = 'PENDIENTE'
    ORDER BY pt.fecha_registro
");
$dstmt->execute([$cobrador_id, $fecha_sel]);
$pagos = $dstmt->fetchAll();

$total_efectivo      = array_sum(array_column($pagos, 'monto_efectivo'));
$total_transferencia = array_sum(array_column($pagos, 'monto_transferencia'));
$total_mora          = array_sum(array_column($pagos, 'monto_mora_cobrada'));
$total_general       = array_sum(array_column($pagos, 'monto_total'));

function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 2, ',', '.');
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas: suma = 190mm (margen 10mm c/lado)
// Cliente(48) + Articulo(42) + Cuota(13) + Vencim.(22) + Efectivo(22) + Transfer.(22) + Total(21)
$COLS   = [48, 42, 13, 22, 22, 22, 21];
$LABELS = ['Cliente', 'Articulo', 'Cuota', 'Vencim.', 'Efectivo', 'Transfer.', 'Total'];
$ALIGNS = ['L', 'L', 'C', 'C', 'R', 'R', 'R'];

class RendicionPDF extends FPDF
{
    public string $cobrador_nombre = '';
    public string $fecha_label    = '';
    public int    $num_pagos      = 0;
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    function Header()
    {
        // Encabezado: solo texto, sin relleno
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 7, lat('Imperio Comercial - Rendicion de Cobranza'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_nombre), 0, 0, 'L');
        $this->Cell(95, 5, lat('Fecha: ' . $this->fecha_label . '   |   Pagos: ' . $this->num_pagos), 0, 1, 'R');

        // Línea separadora
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(4);

        // Encabezado de tabla — negrita, sin relleno
        $this->SetFont('Helvetica', 'B', 7);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 7, lat($this->labels[$i]), 1, 0, $this->aligns[$i], false);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 7);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new RendicionPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->fecha_label     = date('d/m/Y', strtotime($fecha_sel));
$pdf->num_pagos       = count($pagos);
$pdf->cols            = $COLS;
$pdf->labels          = $LABELS;
$pdf->aligns          = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── Filas de datos ─────────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

foreach ($pagos as $p) {
    $cliente  = mb_strimwidth($p['apellidos'] . ', ' . $p['nombres'], 0, 34, '..');
    $articulo = mb_strimwidth($p['articulo'], 0, 30, '..');

    $pdf->Cell($COLS[0], 6, lat($cliente),               1, 0, 'L', false);
    $pdf->Cell($COLS[1], 6, lat($articulo),              1, 0, 'L', false);
    $pdf->Cell($COLS[2], 6, '#' . $p['numero_cuota'],    1, 0, 'C', false);
    $pdf->Cell($COLS[3], 6, date('d/m/Y', strtotime($p['fecha_vencimiento'])), 1, 0, 'C', false);
    $pdf->Cell($COLS[4], 6, fmt($p['monto_efectivo']),       1, 0, 'R', false);
    $pdf->Cell($COLS[5], 6, fmt($p['monto_transferencia']),  1, 0, 'R', false);
    $pdf->Cell($COLS[6], 6, fmt($p['monto_total']),          1, 0, 'R', false);
    $pdf->Ln();
}

// ── Fila TOTALES — negrita, sin relleno ────────────────────────
$pdf->SetFont('Helvetica', 'B', 8);
$ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3];
$pdf->Cell($ancho_label, 7, lat('TOTALES'), 1, 0, 'R', false);
$pdf->Cell($COLS[4], 7, fmt($total_efectivo),      1, 0, 'R', false);
$pdf->Cell($COLS[5], 7, fmt($total_transferencia), 1, 0, 'R', false);
$pdf->Cell($COLS[6], 7, fmt($total_general),       1, 0, 'R', false);
$pdf->Ln();

// ── Resumen al pie — sin rellenos ─────────────────────────────
$pdf->Ln(8);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$bx  = 120;
$bw1 = 42;
$bw2 = 38;

$resumen = [
    ['Efectivo cobrado',  fmt($total_efectivo)],
    ['Transferencias',    fmt($total_transferencia)],
    ['Mora cobrada',      fmt($total_mora)],
    ['TOTAL GENERAL',     fmt($total_general)],
];

foreach ($resumen as $i => [$label, $valor]) {
    $es_total = ($i === count($resumen) - 1);
    $pdf->SetFont('Helvetica', $es_total ? 'B' : '', 9);
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 7, lat($label), 1, 0, 'L', false);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($bw2, 7, lat($valor), 1, 1, 'R', false);
}

$nombre = 'rendicion_' . str_replace('-', '', $fecha_sel) . '_' . $cobrador_id . '.pdf';
$pdf->Output('I', $nombre);
