<?php
// ============================================================
// admin/cartera_zona_clientes_pdf.php — PDF del detalle por crédito
// de admin/cartera_zona_clientes.php (drill-down de una zona puntual)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$cobrador_id    = (int) ($_GET['cobrador_id'] ?? 0);
$zona           = trim($_GET['zona'] ?? '');
$modo_historico = ($_GET['historico'] ?? '') === '1';

if ($modo_historico) {
    $desde = null;
    $hasta = null;
} else {
    $desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
        ? $_GET['desde'] : date('Y-m-01');
    $hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
        ? $_GET['hasta'] : date('Y-m-d');
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
}

if (!$cobrador_id || $zona === '') {
    die('Faltan parámetros (cobrador o zona).');
}

$cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cs->execute([$cobrador_id]);
$cob = $cs->fetch();
if (!$cob) die('Cobrador no encontrado.');
$cob_label = $cob['apellido'] . ', ' . $cob['nombre'];

$creditos = obtener_creditos_cartera_zona($pdo, $cobrador_id, $zona, $desde, $hasta);
if (empty($creditos)) {
    die('Sin créditos para esta combinación de cobrador, zona y período.');
}

$total_otorgado   = array_sum(array_column($creditos, 'monto_otorgado'));
$total_cobrado    = array_sum(array_column($creditos, 'cobrado'));
$total_devolucion = array_sum(array_column($creditos, 'devolucion'));
$total_incobrable = array_sum(array_column($creditos, 'incobrable'));
$total_faltante   = max(0, $total_otorgado - $total_cobrado - $total_devolucion - $total_incobrable);
$total_atraso     = array_sum(array_column($creditos, 'atraso'));

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas (A4 landscape, 277mm utiles):
// Cliente(50) + Telefono(26) + Valor Total(32) + Cobrado(30) + Devolucion(28)
// + Incobrable(28) + Faltante(30) + Atraso(28) + Estado(25) = 277
$COLS   = [50, 26, 32, 30, 28, 28, 30, 28, 25];
$LABELS = ['Cliente', 'Telefono', 'Valor Total', 'Cobrado', 'Devolucion', 'Incobrable', 'Faltante', 'Atraso', 'Estado'];
$ALIGNS = ['L', 'L', 'R', 'R', 'R', 'R', 'R', 'R', 'C'];

class CarteraZonaClientesPDF extends PDFBase
{
    public string $cobrador_lbl = '';
    public string $zona_lbl     = '';
    public string $periodo_lbl  = '';
    public array  $cols         = [];
    public array  $labels       = [];
    public array  $aligns       = [];

    function Header(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(277, 6, lat('Imperio Comercial'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(277, 5, lat('Cartera por Zona - Detalle de Creditos'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(92.33, 5, lat('Cobrador: ' . $this->cobrador_lbl), 0, 0, 'L');
        $this->Cell(92.34, 5, lat('Zona: ' . $this->zona_lbl), 0, 0, 'C');
        $this->Cell(92.33, 5, lat('Periodo: ' . $this->periodo_lbl), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 287, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(230, 230, 245);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new CarteraZonaClientesPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->cobrador_lbl = $cob_label;
$pdf->zona_lbl     = $zona;
$pdf->periodo_lbl  = $modo_historico ? 'General (toda la cartera)' : date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta));
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->AddPage();

foreach ($creditos as $c) {
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, $pdf->fitText($c['apellidos'] . ', ' . $c['nombres'], $COLS[0] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[1], 5.5, lat($c['telefono'] ?: '-'), 1, 0, 'L');
    $pdf->Cell($COLS[2], 5.5, lat(fmt($c['monto_otorgado'])), 1, 0, 'R');
    $pdf->Cell($COLS[3], 5.5, lat(fmt($c['cobrado'])), 1, 0, 'R');
    $pdf->Cell($COLS[4], 5.5, lat(fmt($c['devolucion'])), 1, 0, 'R');
    $pdf->Cell($COLS[5], 5.5, lat(fmt($c['incobrable'])), 1, 0, 'R');
    $pdf->Cell($COLS[6], 5.5, lat(fmt($c['faltante'])), 1, 0, 'R');
    $pdf->SetTextColor($c['atraso'] > 0 ? 200 : 0, 0, 0);
    $pdf->Cell($COLS[7], 5.5, lat(fmt($c['atraso'])), 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($COLS[8], 5.5, lat($c['estado']), 1, 0, 'C');
    $pdf->Ln();
}

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($COLS[0] + $COLS[1], 6, lat('TOTAL (' . count($creditos) . ' creditos)'), 1, 0, 'R');
$pdf->Cell($COLS[2], 6, lat(fmt($total_otorgado)), 1, 0, 'R');
$pdf->Cell($COLS[3], 6, lat(fmt($total_cobrado)), 1, 0, 'R');
$pdf->Cell($COLS[4], 6, lat(fmt($total_devolucion)), 1, 0, 'R');
$pdf->Cell($COLS[5], 6, lat(fmt($total_incobrable)), 1, 0, 'R');
$pdf->Cell($COLS[6], 6, lat(fmt($total_faltante)), 1, 0, 'R');
$pdf->Cell($COLS[7], 6, lat(fmt($total_atraso)), 1, 0, 'R');
$pdf->Cell($COLS[8], 6, '', 1, 1, 'C');

$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell(277, 4, lat(
    'Detalle por credito de esta zona — Valor Total = Cobrado + Devolucion + Incobrable + Faltante para ' .
    'cada credito. Faltante puede ser levemente negativo en un credito puntual si se cobro de mas (mora), ' .
    'sin afectar el total general. Atraso = la porcion de Faltante ya vencida hoy.'
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$slug   = preg_replace('/[^A-Za-z0-9]+/', '_', $zona);
$nombre = 'cartera_zona_clientes_cob' . $cobrador_id . '_' . $slug . '_' . ($desde ?? 'general') . '.pdf';
$pdf->Output('I', $nombre);
