<?php
// ============================================================
// admin/estadisticas_zona_cobrados_pdf.php — Clientes cobrados en
// una zona puntual del cobrador, en el periodo elegido
// Columnas: # | Cliente | Telefono | Direccion | Cant.Pagos | Monto | Ult.Pago
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);
$desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
    ? $_GET['desde'] : date('Y-m-01');
$hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
    ? $_GET['hasta'] : date('Y-m-d');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$zona = trim($_GET['zona'] ?? '');

if (!$cobrador_id || $zona === '') {
    die('Faltan parametros (cobrador y zona).');
}

$cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cs->execute([$cobrador_id]);
$cob = $cs->fetch();
if (!$cob) die('Cobrador no encontrado.');
$cob_label = $cob['apellido'] . ', ' . $cob['nombre'];

$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.apellidos, cl.nombres, cl.telefono, cl.direccion,
           COUNT(pc.id) AS cant_pagos,
           SUM(pc.monto_total) AS monto_cobrado,
           MAX(pc.fecha_jornada) AS ultima_fecha
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu   ON cu.id = pc.cuota_id
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE pc.cobrador_id = ? AND pc.fecha_jornada BETWEEN ? AND ?
      AND pc.origen = 'cobrador'
      AND COALESCE(NULLIF(TRIM(cl.zona), ''), 'Sin zona') = ?
    GROUP BY cl.id, cl.apellidos, cl.nombres, cl.telefono, cl.direccion
    ORDER BY cl.apellidos ASC, cl.nombres ASC
");
$stmt->execute([$cobrador_id, $desde, $hasta, $zona]);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($clientes)) {
    die('No hay clientes cobrados en esa zona con los filtros aplicados.');
}

$total_pagos   = array_sum(array_column($clientes, 'cant_pagos'));
$total_monto   = array_sum(array_column($clientes, 'monto_cobrado'));

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: #(8) + Cliente(50) + Telefono(26) + Direccion(46) + Cant.Pagos(18) + Monto Cobrado(24) + Ult.Pago(18) = 190
$COLS   = [8, 50, 26, 46, 18, 24, 18];
$LABELS = ['#', 'Cliente', 'Telefono', 'Direccion', 'Pagos', 'Monto Cobrado', 'Ult. Pago'];
$ALIGNS = ['C', 'L', 'L', 'L', 'C', 'R', 'C'];

class ZonaCobradosPDF extends PDFBase
{
    public string $cobrador_lbl = '';
    public string $zona_lbl     = '';
    public string $periodo_lbl  = '';
    public string $fecha_gen    = '';
    public int    $num_clientes = 0;
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
        $this->Cell(190, 5, lat('Clientes Cobrados por Zona'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_lbl . '   |   Zona: ' . $this->zona_lbl), 0, 0, 'L');
        $this->Cell(95, 5, lat('Periodo: ' . $this->periodo_lbl . '   |   ' . $this->num_clientes . ' clientes'), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(220, 235, 225);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new ZonaCobradosPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->cobrador_lbl = $cob_label;
$pdf->zona_lbl     = $zona;
$pdf->periodo_lbl  = date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta));
$pdf->fecha_gen    = date('d/m/Y H:i');
$pdf->num_clientes = count($clientes);
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->AddPage();

$index = 1;
foreach ($clientes as $r) {
    $ult = $r['ultima_fecha'] ? date('d/m/Y', strtotime($r['ultima_fecha'])) : '—';

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, (string)$index, 1, 0, 'C');
    $pdf->Cell($COLS[1], 5.5, $pdf->fitText($r['apellidos'] . ', ' . $r['nombres'], $COLS[1] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[2], 5.5, $pdf->fitText($r['telefono'] ?: '—', $COLS[2] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[3], 5.5, $pdf->fitText($r['direccion'] ?: '—', $COLS[3] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[4], 5.5, (string)$r['cant_pagos'], 1, 0, 'C');
    $pdf->Cell($COLS[5], 5.5, lat(fmt((float)$r['monto_cobrado'])), 1, 0, 'R');
    $pdf->Cell($COLS[6], 5.5, lat($ult), 1, 0, 'C');
    $pdf->Ln();

    $index++;
}

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($COLS[0] + $COLS[1] + $COLS[2] + $COLS[3], 6, lat('TOTALES (' . count($clientes) . ' clientes)'), 1, 0, 'R');
$pdf->Cell($COLS[4], 6, (string)$total_pagos, 1, 0, 'C');
$pdf->Cell($COLS[5], 6, lat(fmt($total_monto)), 1, 0, 'R');
$pdf->Cell($COLS[6], 6, '', 1, 0, 'L');
$pdf->Ln();

$zona_slug = preg_replace('/\W+/', '', $zona) ?: 'zona';
$nombre = 'zona_cobrados_cob' . $cobrador_id . '_' . $zona_slug . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
