<?php
// ============================================================
// admin/historial_metas_pdf.php — PDF Balance mensual de Metas
// Mismos filtros y misma tabla que admin/historial_metas.php
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_estadisticas');

$pdo = obtener_conexion();

$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$desde       = trim($_GET['desde'] ?? date('Y-m-01'));
$hasta       = trim($_GET['hasta'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta))  $hasta = date('Y-m-d');
if ($desde > $hasta) $desde = $hasta;

if ($cobrador_id > 0) {
    $stmt = $pdo->prepare("
        SELECT semana_lunes, meta_automatica, cobrado_real, meta_fija_semanal, cobrado_semanal_puro
        FROM ic_historial_metas
        WHERE cobrador_id = ? AND semana_lunes BETWEEN ? AND ?
        ORDER BY semana_lunes ASC
    ");
    $stmt->execute([$cobrador_id, $desde, $hasta]);
    $sc = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
    $sc->execute([$cobrador_id]);
    $cv = $sc->fetch();
    $label_cobrador = $cv ? $cv['apellido'] . ', ' . $cv['nombre'] : 'Todos los cobradores';
} else {
    $stmt = $pdo->prepare("
        SELECT semana_lunes,
               SUM(meta_automatica)      AS meta_automatica,
               SUM(cobrado_real)         AS cobrado_real,
               SUM(meta_fija_semanal)    AS meta_fija_semanal,
               SUM(cobrado_semanal_puro) AS cobrado_semanal_puro
        FROM ic_historial_metas
        WHERE semana_lunes BETWEEN ? AND ?
        GROUP BY semana_lunes
        ORDER BY semana_lunes ASC
    ");
    $stmt->execute([$desde, $hasta]);
    $label_cobrador = 'Todos los cobradores (suma semanal)';
}
$filas = $stmt->fetchAll();

if (empty($filas)) {
    die(iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE',
        'No hay snapshots de metas en el periodo ' .
        date('d/m/Y', strtotime($desde)) . ' - ' .
        date('d/m/Y', strtotime($hasta)) . '.'
    ));
}

// ── Totales del período ──────────────────────────────────────
$tot_meta_auto = 0.0; $tot_cobrado_real = 0.0; $tot_meta_fija = 0.0; $tot_cobrado_puro = 0.0;
foreach ($filas as $f) {
    $tot_meta_auto    += (float) $f['meta_automatica'];
    $tot_cobrado_real += (float) $f['cobrado_real'];
    $tot_meta_fija    += (float) $f['meta_fija_semanal'];
    $tot_cobrado_puro += (float) $f['cobrado_semanal_puro'];
}
$tot_pct_real = $tot_meta_auto > 0 ? min(100, round($tot_cobrado_real / $tot_meta_auto * 100)) : 0;
$tot_pct_puro = $tot_meta_fija > 0 ? min(100, round($tot_cobrado_puro / $tot_meta_fija * 100)) : 0;

// ── PDF ────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: Semana(38)+MetaAuto(30)+CobradoReal(30)+%(16)+MetaFija(30)+CobradoPuro(30)+%(16) = 190
$COLS   = [38, 30, 30, 16, 30, 30, 16];
$LABELS = ['Semana', 'Meta Automatica', 'Cobrado Real', '%', 'Meta Fija Semanal', 'Cobrado (sin mora)', '%'];
$ALIGNS = ['L', 'R', 'R', 'C', 'R', 'R', 'C'];

function color_pdf(FPDF $pdf, int $pct): void
{
    if ($pct >= 100)     $pdf->SetTextColor(180, 130, 10);
    elseif ($pct >= 70)  $pdf->SetTextColor(22, 163, 74);
    elseif ($pct >= 40)  $pdf->SetTextColor(217, 119, 6);
    else                 $pdf->SetTextColor(190, 30, 45);
}

class HistorialMetasPDF extends PDFBase
{
    public string $periodo_lbl  = '';
    public string $cob_lbl      = '';
    public string $gen_fecha    = '';
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
        $this->Cell(190, 5, lat('Historial de Metas — Periodo ' . $this->periodo_lbl), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(126, 5, lat('Cobrador: ' . $this->cob_lbl), 0, 0, 'L');
        $this->Cell(64, 5, lat('Generado: ' . $this->gen_fecha), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(220, 220, 230);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new HistorialMetasPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->periodo_lbl = date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
$pdf->cob_lbl     = $label_cobrador;
$pdf->gen_fecha   = date('d/m/Y H:i');
$pdf->cols        = $COLS;
$pdf->labels      = $LABELS;
$pdf->aligns      = $ALIGNS;
$pdf->AddPage();

foreach ($filas as $i => $f) {
    $meta_auto = (float) $f['meta_automatica'];
    $cob_real  = (float) $f['cobrado_real'];
    $meta_fija = (float) $f['meta_fija_semanal'];
    $cob_puro  = (float) $f['cobrado_semanal_puro'];
    $pct_real  = $meta_auto > 0 ? min(100, round($cob_real / $meta_auto * 100)) : 0;
    $pct_puro  = $meta_fija > 0 ? min(100, round($cob_puro / $meta_fija * 100)) : 0;

    $lunes_dt   = strtotime($f['semana_lunes']);
    $semana_lbl = date('d/m', $lunes_dt) . ' - ' . date('d/m/Y', strtotime('+5 days', $lunes_dt));

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(...($i % 2 === 0 ? [255, 255, 255] : [245, 245, 248]));
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 6, lat($semana_lbl), 1, 0, $ALIGNS[0], true);
    $pdf->Cell($COLS[1], 6, lat(fmt($meta_auto)), 1, 0, $ALIGNS[1], true);
    $pdf->Cell($COLS[2], 6, lat(fmt($cob_real)), 1, 0, $ALIGNS[2], true);

    $pdf->SetFont('Helvetica', 'B', 8);
    color_pdf($pdf, $pct_real);
    $pdf->Cell($COLS[3], 6, $pct_real . '%', 1, 0, $ALIGNS[3], true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($COLS[4], 6, lat(fmt($meta_fija)), 1, 0, $ALIGNS[4], true);
    $pdf->Cell($COLS[5], 6, lat(fmt($cob_puro)), 1, 0, $ALIGNS[5], true);

    $pdf->SetFont('Helvetica', 'B', 8);
    color_pdf($pdf, $pct_puro);
    $pdf->Cell($COLS[6], 6, $pct_puro . '%', 1, 0, $ALIGNS[6], true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln();
}

// ── Total del período ────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(230, 230, 230);
$pdf->SetX(10);
$pdf->Cell($COLS[0], 7, lat('TOTAL PERIODO'), 1, 0, 'L', true);
$pdf->Cell($COLS[1], 7, lat(fmt($tot_meta_auto)), 1, 0, 'R', true);
$pdf->Cell($COLS[2], 7, lat(fmt($tot_cobrado_real)), 1, 0, 'R', true);
color_pdf($pdf, $tot_pct_real);
$pdf->Cell($COLS[3], 7, $tot_pct_real . '%', 1, 0, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell($COLS[4], 7, lat(fmt($tot_meta_fija)), 1, 0, 'R', true);
$pdf->Cell($COLS[5], 7, lat(fmt($tot_cobrado_puro)), 1, 0, 'R', true);
color_pdf($pdf, $tot_pct_puro);
$pdf->Cell($COLS[6], 7, $tot_pct_puro . '%', 1, 0, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln();

// ── Nota al pie ────────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->Cell(190, 5, lat('Meta Automatica/Cobrado Real: incluye mora. Meta Fija Semanal/Cobrado (sin mora): solo cuotas semanales, sin mora.'), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);

$filename = 'historial_metas_' . str_replace('-', '', $desde) . '_' . str_replace('-', '', $hasta) . '.pdf';
$pdf->Output('I', $filename);
