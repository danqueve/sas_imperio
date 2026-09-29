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

// meta_objetivo NULL = snapshot de antes de la migración que agregó el
// objetivo efectivo — se resuelve a meta_automatica como "criterio
// anterior" tanto acá como en admin/historial_metas.php (misma regla).
if ($cobrador_id > 0) {
    $stmt = $pdo->prepare("
        SELECT semana_lunes, meta_automatica, meta_objetivo, origen_meta,
               cobrado_real, meta_fija_semanal, cobrado_efectivo, cobrado_transferencia
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
    // Fila por (cobrador, semana) en vez de sumar por semana — permite
    // desglosar el PDF por cobrador cuando se eligió "Todos".
    $stmt = $pdo->prepare("
        SELECT hm.cobrador_id, u.apellido, u.nombre, hm.semana_lunes,
               hm.meta_automatica, hm.meta_objetivo, hm.origen_meta,
               hm.cobrado_real, hm.meta_fija_semanal, hm.cobrado_efectivo, hm.cobrado_transferencia
        FROM ic_historial_metas hm
        JOIN ic_usuarios u ON hm.cobrador_id = u.id
        WHERE hm.semana_lunes BETWEEN ? AND ?
        ORDER BY u.apellido ASC, u.nombre ASC, hm.semana_lunes ASC
    ");
    $stmt->execute([$desde, $hasta]);
    $label_cobrador = 'Todos los cobradores (desglosado por cobrador)';
}
$filas = $stmt->fetchAll();

// Agrupar por cobrador (solo se usa cuando cobrador_id === 0)
$por_cobrador = [];
if ($cobrador_id === 0) {
    foreach ($filas as $f) {
        $cid = (int) $f['cobrador_id'];
        if (!isset($por_cobrador[$cid])) {
            $por_cobrador[$cid] = ['label' => $f['apellido'] . ', ' . $f['nombre'], 'filas' => []];
        }
        $por_cobrador[$cid]['filas'][] = $f;
    }
}

if (empty($filas)) {
    die(iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE',
        'No hay snapshots de metas en el periodo ' .
        date('d/m/Y', strtotime($desde)) . ' - ' .
        date('d/m/Y', strtotime($hasta)) . '.'
    ));
}

// meta_objetivo de una fila: NULL (snapshot anterior a la migración) cae
// a meta_automatica — mismo criterio que admin/historial_metas.php.
function meta_objetivo_fila(array $f): float
{
    return $f['meta_objetivo'] !== null ? (float) $f['meta_objetivo'] : (float) $f['meta_automatica'];
}

// ── Totales del período ──────────────────────────────────────
$tot_meta_auto = 0.0; $tot_meta_objetivo = 0.0; $tot_cobrado_real = 0.0; $tot_meta_fija = 0.0; $tot_efectivo = 0.0; $tot_transferencia = 0.0;
$tot_criterio_anterior = 0;
foreach ($filas as $f) {
    $tot_meta_auto     += (float) $f['meta_automatica'];
    $tot_meta_objetivo += meta_objetivo_fila($f);
    $tot_cobrado_real  += (float) $f['cobrado_real'];
    $tot_meta_fija     += (float) $f['meta_fija_semanal'];
    $tot_efectivo      += (float) $f['cobrado_efectivo'];
    $tot_transferencia += (float) $f['cobrado_transferencia'];
    if ($f['meta_objetivo'] === null) $tot_criterio_anterior++;
}
$tot_pct_objetivo = $tot_meta_objetivo > 0 ? min(100, round($tot_cobrado_real / $tot_meta_objetivo * 100)) : 0;

// ── PDF ────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: Semana(30)+MetaAuto(22)+MetaObjetivo(24)+CobradoReal(24)+%(14)+MetaFija(22)+Efectivo(27)+Transferencia(27) = 190
$COLS   = [30, 22, 24, 24, 14, 22, 27, 27];
$LABELS = ['Semana', 'M. Automatica', 'M. Objetivo', 'Cobrado Real', '%', 'M. Fija Sem.', 'Efectivo', 'Transferencia'];
$ALIGNS = ['L', 'R', 'R', 'R', 'C', 'R', 'R', 'R'];

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

// Dibuja una fila de semana; devuelve sus montos (para acumular subtotales).
function render_fila_metas(HistorialMetasPDF $pdf, array $COLS, array $ALIGNS, array $f, int $i): array
{
    $meta_auto      = (float) $f['meta_automatica'];
    $meta_objetivo  = meta_objetivo_fila($f);
    $es_criterio_anterior = ($f['meta_objetivo'] === null);
    $cob_real       = (float) $f['cobrado_real'];
    $meta_fija      = (float) $f['meta_fija_semanal'];
    $efectivo       = (float) $f['cobrado_efectivo'];
    $transferencia  = (float) $f['cobrado_transferencia'];
    $pct_obj        = $meta_objetivo > 0 ? min(100, round($cob_real / $meta_objetivo * 100)) : 0;

    $lunes_dt   = strtotime($f['semana_lunes']);
    $semana_lbl = date('d/m', $lunes_dt) . ' - ' . date('d/m/Y', strtotime('+5 days', $lunes_dt));

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(...($i % 2 === 0 ? [255, 255, 255] : [245, 245, 248]));
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 6, lat($semana_lbl), 1, 0, $ALIGNS[0], true);
    $pdf->Cell($COLS[1], 6, lat(fmt($meta_auto)), 1, 0, $ALIGNS[1], true);
    if ($es_criterio_anterior) $pdf->SetTextColor(140, 140, 140);
    $pdf->Cell($COLS[2], 6, lat(fmt($meta_objetivo)) . ($es_criterio_anterior ? ' *' : ''), 1, 0, $ALIGNS[2], true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($COLS[3], 6, lat(fmt($cob_real)), 1, 0, $ALIGNS[3], true);

    $pdf->SetFont('Helvetica', 'B', 8);
    color_pdf($pdf, $pct_obj);
    $pdf->Cell($COLS[4], 6, $pct_obj . '%', 1, 0, $ALIGNS[4], true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($COLS[5], 6, lat(fmt($meta_fija)), 1, 0, $ALIGNS[5], true);
    $pdf->Cell($COLS[6], 6, lat(fmt($efectivo)), 1, 0, $ALIGNS[6], true);
    $pdf->Cell($COLS[7], 6, lat(fmt($transferencia)), 1, 0, $ALIGNS[7], true);

    $pdf->Ln();

    return [$meta_auto, $meta_objetivo, $cob_real, $meta_fija, $efectivo, $transferencia];
}

// Fila de total/subtotal — reutilizada tanto para el subtotal por cobrador
// como para el TOTAL PERIODO final (mismo cálculo, distinto color de fondo).
function render_total_metas(HistorialMetasPDF $pdf, array $COLS, string $label, float $meta_auto, float $meta_objetivo, float $cob_real, float $meta_fija, float $efectivo, float $transferencia, array $fill): void
{
    $pct_obj = $meta_objetivo > 0 ? min(100, round($cob_real / $meta_objetivo * 100)) : 0;

    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(...$fill);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 7, $pdf->fitText($label, $COLS[0] - 2), 1, 0, 'L', true);
    $pdf->Cell($COLS[1], 7, lat(fmt($meta_auto)), 1, 0, 'R', true);
    $pdf->Cell($COLS[2], 7, lat(fmt($meta_objetivo)), 1, 0, 'R', true);
    $pdf->Cell($COLS[3], 7, lat(fmt($cob_real)), 1, 0, 'R', true);
    color_pdf($pdf, $pct_obj);
    $pdf->Cell($COLS[4], 7, $pct_obj . '%', 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($COLS[5], 7, lat(fmt($meta_fija)), 1, 0, 'R', true);
    $pdf->Cell($COLS[6], 7, lat(fmt($efectivo)), 1, 0, 'R', true);
    $pdf->Cell($COLS[7], 7, lat(fmt($transferencia)), 1, 0, 'R', true);
    $pdf->Ln();
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

if ($cobrador_id > 0) {
    foreach ($filas as $i => $f) {
        render_fila_metas($pdf, $COLS, $ALIGNS, $f, $i);
    }
} else {
    // Desglose: un bloque por cobrador (nombre + sus semanas + subtotal)
    foreach ($por_cobrador as $grupo) {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(210, 214, 235);
        $pdf->SetX(10);
        $pdf->Cell(array_sum($COLS), 6, lat($grupo['label']), 1, 1, 'L', true);

        $sub_meta_auto = 0.0; $sub_meta_objetivo = 0.0; $sub_cobrado_real = 0.0; $sub_meta_fija = 0.0; $sub_efectivo = 0.0; $sub_transferencia = 0.0;
        foreach ($grupo['filas'] as $i => $f) {
            [$ma, $mo, $cr, $mf, $ef, $tr] = render_fila_metas($pdf, $COLS, $ALIGNS, $f, $i);
            $sub_meta_auto     += $ma;
            $sub_meta_objetivo += $mo;
            $sub_cobrado_real  += $cr;
            $sub_meta_fija     += $mf;
            $sub_efectivo      += $ef;
            $sub_transferencia += $tr;
        }
        render_total_metas($pdf, $COLS, 'Subtotal ' . $grupo['label'], $sub_meta_auto, $sub_meta_objetivo, $sub_cobrado_real, $sub_meta_fija, $sub_efectivo, $sub_transferencia, [225, 228, 245]);
        $pdf->Ln(2);
    }
}

// ── Total del período ────────────────────────────────────────
render_total_metas($pdf, $COLS, 'TOTAL PERIODO', $tot_meta_auto, $tot_meta_objetivo, $tot_cobrado_real, $tot_meta_fija, $tot_efectivo, $tot_transferencia, [230, 230, 230]);

// ── Nota al pie ────────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell(190, 5, lat(
    'Meta Automatica/Cobrado Real: incluye mora. Meta Objetivo: override manual del cobrador si tenia uno cargado, o Meta Automatica si no. '
    . 'Efectivo/Transferencia: desglose de lo cobrado por metodo de pago.'
    . ($tot_criterio_anterior > 0
        ? ' * = semana anterior a que se empezara a guardar el objetivo efectivo (' . $tot_criterio_anterior . ' en este periodo) — se muestra Meta Automatica como referencia.'
        : '')
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$filename = 'historial_metas_' . str_replace('-', '', $desde) . '_' . str_replace('-', '', $hasta) . '.pdf';
$pdf->Output('I', $filename);
