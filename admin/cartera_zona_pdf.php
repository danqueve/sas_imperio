<?php
// ============================================================
// admin/cartera_zona_pdf.php — PDF de la tabla "Cartera por Zona"
// de admin/cartera_zona.php (cohorte por fecha_alta del credito)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$cobrador_id    = (int)($_GET['cobrador_id'] ?? 0);
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

if (!$cobrador_id) {
    die('Falta el parámetro cobrador.');
}

$cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cs->execute([$cobrador_id]);
$cob = $cs->fetch();
if (!$cob) die('Cobrador no encontrado.');
$cob_label = $cob['apellido'] . ', ' . $cob['nombre'];

$zonas_cob = obtener_cartera_por_zona($pdo, $cobrador_id, $desde, $hasta);
if (empty($zonas_cob)) {
    die($modo_historico ? 'Sin créditos registrados para este cobrador.' : 'Sin créditos otorgados para este cobrador en el período elegido.');
}

$total_clientes   = array_sum(array_column($zonas_cob, 'clientes'));
$total_importe    = array_sum(array_column($zonas_cob, 'importe_total'));
$total_atraso     = array_sum(array_column($zonas_cob, 'atraso'));
$total_devolucion = array_sum(array_column($zonas_cob, 'devolucion'));
$total_otorgado   = array_sum(array_column($zonas_cob, 'monto_otorgado'));
$total_cobrado    = array_sum(array_column($zonas_cob, 'cobrado'));
$total_faltante   = array_sum(array_column($zonas_cob, 'faltante'));
$pct_cobro_total  = $total_otorgado > 0 ? round($total_cobrado / $total_otorgado * 100) : 0;
$pct_atraso_total = $total_otorgado > 0 ? round($total_atraso  / $total_otorgado * 100) : 0;

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: Zona(24) + Clientes(13) + Importe Total(22) + Cobrado(21) + Faltante(21) + Atraso(20) + Devolucion(24) + %Cobro(22) + %Atraso(23) = 190
$COLS   = [24, 13, 22, 21, 21, 20, 24, 22, 23];
$LABELS = ['Zona', 'Clientes', 'Importe Total', 'Cobrado', 'Faltante', 'Atraso', 'Devol. Articulos', '% Cobro', '% Atraso'];
$ALIGNS = ['L', 'C', 'R', 'R', 'R', 'R', 'R', 'R', 'R'];

class CarteraZonaPDF extends PDFBase
{
    public string $cobrador_lbl = '';
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
        $this->Cell(190, 6, lat('Imperio Comercial'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Cartera por Zona'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_lbl), 0, 0, 'L');
        $this->Cell(95, 5, lat('Creditos otorgados: ' . $this->periodo_lbl), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
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

$pdf = new CarteraZonaPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->cobrador_lbl = $cob_label;
$pdf->periodo_lbl  = $modo_historico ? 'General (toda la cartera)' : date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta));
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->AddPage();

foreach ($zonas_cob as $zona => $dz) {
    $color_cobro  = $dz['pct_cobro']  >= 70 ? [34, 197, 94] : ($dz['pct_cobro']  >= 40 ? [245, 158, 11] : [239, 68, 68]);
    $color_atraso = $dz['pct_atraso'] <= 15 ? [34, 197, 94] : ($dz['pct_atraso'] <= 35 ? [245, 158, 11] : [239, 68, 68]);

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, $pdf->fitText($zona, $COLS[0] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[1], 5.5, (string)$dz['clientes'], 1, 0, 'C');
    $pdf->Cell($COLS[2], 5.5, lat(fmt($dz['importe_total'])), 1, 0, 'R');
    $pdf->Cell($COLS[3], 5.5, lat(fmt($dz['cobrado'])), 1, 0, 'R');
    $pdf->Cell($COLS[4], 5.5, lat(fmt($dz['faltante'])), 1, 0, 'R');
    $pdf->SetTextColor($dz['atraso'] > 0 ? 200 : 0, 0, 0);
    $pdf->Cell($COLS[5], 5.5, lat(fmt($dz['atraso'])), 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($COLS[6], 5.5, lat(fmt($dz['devolucion'])), 1, 0, 'R');
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetTextColor($color_cobro[0], $color_cobro[1], $color_cobro[2]);
    $pdf->Cell($COLS[7], 5.5, $dz['pct_cobro'] . '%', 1, 0, 'R');
    $pdf->SetTextColor($color_atraso[0], $color_atraso[1], $color_atraso[2]);
    $pdf->Cell($COLS[8], 5.5, $dz['pct_atraso'] . '%', 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Ln();
}

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($COLS[0], 6, lat('TOTAL (' . count($zonas_cob) . ' zonas)'), 1, 0, 'L');
$pdf->Cell($COLS[1], 6, (string)$total_clientes, 1, 0, 'C');
$pdf->Cell($COLS[2], 6, lat(fmt($total_importe)), 1, 0, 'R');
$pdf->Cell($COLS[3], 6, lat(fmt($total_cobrado)), 1, 0, 'R');
$pdf->Cell($COLS[4], 6, lat(fmt($total_faltante)), 1, 0, 'R');
$pdf->Cell($COLS[5], 6, lat(fmt($total_atraso)), 1, 0, 'R');
$pdf->Cell($COLS[6], 6, lat(fmt($total_devolucion)), 1, 0, 'R');
$pdf->Cell($COLS[7], 6, $pct_cobro_total . '%', 1, 0, 'R');
$pdf->Cell($COLS[8], 6, $pct_atraso_total . '%', 1, 0, 'R');
$pdf->Ln();

$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell(190, 4, lat(
    ($modo_historico
        ? 'Vista general: incluye toda la cartera del cobrador, sin importar cuando se otorgo el credito. '
        : 'El periodo elige que creditos entran al reporte segun su fecha de alta; los montos reflejan el estado ' .
          'actual de esos creditos (no solo lo ocurrido dentro del rango). ') .
    'Importe Total = saldo pendiente de cobro ' .
    'de creditos activos. Atraso = solo la porcion ya vencida hoy. Devolucion Articulos = saldo que faltaba ' .
    'cobrar en los creditos finalizados por retiro de producto. Cobrado = pagos confirmados de esos creditos. ' .
    'Faltante = monto otorgado menos lo cobrado (incluye lo que quedo sin cobrar en creditos finalizados, ' .
    'por ejemplo por devolucion de articulo). ' .
    '% Cobro y % Atraso se calculan sobre el monto ' .
    'total otorgado' . ($modo_historico ? '.' : ' en el periodo.')
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$nombre = 'cartera_zona_cob' . $cobrador_id . '_' . ($desde ?? 'general') . '.pdf';
$pdf->Output('I', $nombre);
