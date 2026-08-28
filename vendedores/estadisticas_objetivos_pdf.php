<?php
// ============================================================
// vendedores/estadisticas_objetivos_pdf.php — PDF de la tabla
// "Objetivos de Venta" de vendedores/estadisticas.php
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Mismo parseo de período que estadisticas.php ─────────────
$preset  = $_GET['periodo'] ?? 'historico';
$validos = ['mes_actual', 'mes_ant', 'trim', 'sem', 'anio', 'historico', 'custom'];
if (!in_array($preset, $validos)) $preset = 'historico';

$hoy = date('Y-m-d');
switch ($preset) {
    case 'mes_actual':
        $f_desde = date('Y-m-01'); $f_hasta = $hoy; break;
    case 'mes_ant':
        $f_desde = date('Y-m-01', strtotime('first day of last month'));
        $f_hasta = date('Y-m-t',  strtotime('last month')); break;
    case 'trim':
        $f_desde = date('Y-m-d', strtotime('-3 months')); $f_hasta = $hoy; break;
    case 'sem':
        $f_desde = date('Y-m-d', strtotime('-6 months')); $f_hasta = $hoy; break;
    case 'anio':
        $f_desde = date('Y-01-01'); $f_hasta = $hoy; break;
    case 'custom':
        $f_desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-01-01');
        $f_hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy; break;
    default:
        $f_desde = null; $f_hasta = null; break;
}

if (!$f_desde || !$f_hasta) {
    die('Elegí un período con fecha (no "Histórico completo") para generar este PDF.');
}

$presets_labels = [
    'mes_actual' => 'Mes actual', 'mes_ant' => 'Mes anterior', 'trim' => 'Últimos 3 meses',
    'sem' => 'Últimos 6 meses', 'anio' => date('Y') . ' (año actual)', 'custom' => 'Personalizado',
];
$label_periodo = $presets_labels[$preset] ?? 'Personalizado';

$filas = obtener_objetivos_vendedores($pdo, $f_desde, $f_hasta);

// Solo vendedores con ventas en el período, de mayor a menor vendido
$filas = array_values(array_filter($filas, fn($f) => $f['vendido'] > 0));
usort($filas, fn($a, $b) => $b['vendido'] <=> $a['vendido']);

if (empty($filas)) {
    die('Ningún vendedor con objetivo cargado tuvo ventas en el período elegido.');
}

$total_vendido  = array_sum(array_column($filas, 'vendido'));
$total_objetivo = array_sum(array_column($filas, 'objetivo'));
$total_faltante = array_sum(array_column($filas, 'faltante'));
$pct_total       = $total_objetivo > 0 ? round($total_vendido / $total_objetivo * 100) : 0;

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: #(8) + Vendedor(45) + Vendido(30) + Objetivo(30) + Faltante(30) + %Cumpl(23) + %Falta(24) = 190
$COLS   = [8, 45, 30, 30, 30, 23, 24];
$LABELS = ['#', 'Vendedor', 'Vendido', 'Objetivo', 'Faltante', '% Cumpl.', '% Falta'];
$ALIGNS = ['C', 'L', 'R', 'R', 'R', 'R', 'R'];

class ObjetivosVentaPDF extends PDFBase
{
    public string $periodo_lbl = '';
    public array  $cols        = [];
    public array  $labels      = [];
    public array  $aligns      = [];

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
        $this->Cell(190, 5, lat('Objetivos de Venta por Vendedor'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Periodo: ' . $this->periodo_lbl . '   |   Emision: ' . date('d/m/Y H:i')), 0, 1, 'C');

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

$pdf = new ObjetivosVentaPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->periodo_lbl = $label_periodo . ' (' . date('d/m/Y', strtotime($f_desde)) . ' - ' . date('d/m/Y', strtotime($f_hasta)) . ')';
$pdf->cols        = $COLS;
$pdf->labels      = $LABELS;
$pdf->aligns      = $ALIGNS;
$pdf->AddPage();

$index = 1;
foreach ($filas as $f) {
    $color_pct = $f['pct_cumpl'] >= 100 ? [34, 197, 94] : ($f['pct_cumpl'] >= 70 ? [245, 158, 11] : [239, 68, 68]);

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, (string)$index, 1, 0, 'C');
    $pdf->Cell($COLS[1], 5.5, $pdf->fitText($f['vendedor'], $COLS[1] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[2], 5.5, lat(fmt($f['vendido'])), 1, 0, 'R');
    $pdf->Cell($COLS[3], 5.5, lat(fmt($f['objetivo'])), 1, 0, 'R');
    $pdf->SetTextColor($f['faltante'] > 0 ? 200 : 0, 0, 0);
    $pdf->Cell($COLS[4], 5.5, lat(fmt($f['faltante'])), 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetTextColor($color_pct[0], $color_pct[1], $color_pct[2]);
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($COLS[5], 5.5, $f['pct_cumpl'] . '%', 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Cell($COLS[6], 5.5, $f['pct_falta'] . '%', 1, 0, 'R');
    $pdf->Ln();

    $index++;
}

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($COLS[0] + $COLS[1], 6, lat('TOTALES (' . count($filas) . ' vendedores)'), 1, 0, 'R');
$pdf->Cell($COLS[2], 6, lat(fmt($total_vendido)), 1, 0, 'R');
$pdf->Cell($COLS[3], 6, lat(fmt($total_objetivo)), 1, 0, 'R');
$pdf->Cell($COLS[4], 6, lat(fmt($total_faltante)), 1, 0, 'R');
$pdf->Cell($COLS[5], 6, $pct_total . '%', 1, 0, 'R');
$pdf->Cell($COLS[6], 6, max(0, 100 - $pct_total) . '%', 1, 0, 'R');
$pdf->Ln();

$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell(190, 4, lat(
    'Objetivo = objetivo mensual cargado en Vendedores > Objetivos, escalado a la cantidad de meses del ' .
    'periodo elegido (Mes actual/Mes anterior = objetivo mensual completo; trimestre = x3; semestre = x6; etc.). ' .
    'Solo se listan vendedores con objetivo cargado.'
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$nombre = 'objetivos_venta_' . ($f_desde ?? 'periodo') . '.pdf';
$pdf->Output('I', $nombre);
