<?php
// ============================================================
// admin/estadisticas_zona_faltantes_pdf.php — Clientes con cuotas
// sin cobrar en una zona puntual del cobrador, en el periodo elegido.
// Mismo criterio de calculo (Estimado/Faltante) que estadisticas_zona.php,
// agregado por cliente en vez de por zona.
// Columnas: # | Cliente | Telefono | Direccion | Cuotas Pend. | Vencim. | Monto Faltante
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
           cr.estado AS credito_estado,
           cu.estado, cu.monto_cuota, cu.monto_mora, cu.saldo_pagado, cu.fecha_vencimiento,
           cr.interes_moratorio_pct,
           EXISTS(
               SELECT 1 FROM ic_pagos_temporales pt2
               WHERE pt2.cuota_id = cu.id AND pt2.estado IN ('PENDIENTE','APROBADO')
                 AND pt2.origen = 'cobrador'
           ) AS tiene_pago
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
      AND cu.estado != 'CANCELADA'
      AND cu.fecha_vencimiento BETWEEN ? AND ?
      AND COALESCE(NULLIF(TRIM(cl.zona), ''), 'Sin zona') = ?
    ORDER BY cl.apellidos ASC, cl.nombres ASC, cu.fecha_vencimiento ASC
");
$stmt->execute([$cobrador_id, $desde, $hasta, $zona]);

$por_cliente = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cu) {
    $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
    $mora = (float)$cu['monto_mora'] > 0
        ? (float)$cu['monto_mora']
        : calcular_mora((float)$cu['monto_cuota'], $dias_atraso, (float)$cu['interes_moratorio_pct']);

    $cobrado_cuota = ((float)$cu['saldo_pagado'] > 0)
        || in_array($cu['estado'], ['PAGADA', 'CAP_PAGADA'], true)
        || (bool)$cu['tiene_pago'];

    if ($cobrado_cuota) continue;

    $monto_falta = max(0, (float)$cu['monto_cuota'] + $mora - (float)$cu['saldo_pagado']);
    if ($monto_falta <= 0) continue;

    $cid = $cu['cliente_id'];
    if (!isset($por_cliente[$cid])) {
        $por_cliente[$cid] = [
            'apellidos'  => $cu['apellidos'],
            'nombres'    => $cu['nombres'],
            'telefono'   => $cu['telefono'],
            'direccion'  => $cu['direccion'],
            'moroso'     => $cu['credito_estado'] === 'MOROSO',
            'cant_cuotas' => 0,
            'monto'      => 0.0,
            'primer_venc' => $cu['fecha_vencimiento'],
        ];
    }
    $por_cliente[$cid]['cant_cuotas']++;
    $por_cliente[$cid]['monto'] += $monto_falta;
    if ($cu['fecha_vencimiento'] < $por_cliente[$cid]['primer_venc']) {
        $por_cliente[$cid]['primer_venc'] = $cu['fecha_vencimiento'];
    }
}

if (empty($por_cliente)) {
    die('No hay clientes con cuotas sin cobrar en esa zona con los filtros aplicados.');
}

uasort($por_cliente, fn($a, $b) => $b['monto'] <=> $a['monto']);

$total_cuotas = array_sum(array_column($por_cliente, 'cant_cuotas'));
$total_monto  = array_sum(array_column($por_cliente, 'monto'));

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: #(8) + Cliente(48) + Telefono(24) + Direccion(44) + Cuotas Pend.(18) + Vencim.(18) + Monto Faltante(30) = 190
$COLS   = [8, 48, 24, 44, 18, 18, 30];
$LABELS = ['#', 'Cliente', 'Telefono', 'Direccion', 'Cuotas Pend.', 'Vencim.', 'Monto Faltante'];
$ALIGNS = ['C', 'L', 'L', 'L', 'C', 'C', 'R'];

class ZonaFaltantesPDF extends PDFBase
{
    public string $cobrador_lbl = '';
    public string $zona_lbl     = '';
    public string $periodo_lbl  = '';
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
        $this->Cell(190, 5, lat('Clientes con Cuotas sin Cobrar por Zona'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_lbl . '   |   Zona: ' . $this->zona_lbl), 0, 0, 'L');
        $this->Cell(95, 5, lat('Periodo: ' . $this->periodo_lbl . '   |   ' . $this->num_clientes . ' clientes'), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(240, 220, 220);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new ZonaFaltantesPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->cobrador_lbl = $cob_label;
$pdf->zona_lbl     = $zona;
$pdf->periodo_lbl  = date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta));
$pdf->num_clientes = count($por_cliente);
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->AddPage();

$index = 1;
foreach ($por_cliente as $r) {
    $nombre_cli = $r['apellidos'] . ', ' . $r['nombres'];
    if ($r['moroso']) $nombre_cli = '[M] ' . $nombre_cli;

    $pdf->SetFont('Helvetica', $r['moroso'] ? 'B' : '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, (string)$index, 1, 0, 'C');
    $pdf->Cell($COLS[1], 5.5, $pdf->fitText($nombre_cli, $COLS[1] - 2), 1, 0, 'L');
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Cell($COLS[2], 5.5, $pdf->fitText($r['telefono'] ?: '—', $COLS[2] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[3], 5.5, $pdf->fitText($r['direccion'] ?: '—', $COLS[3] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[4], 5.5, (string)$r['cant_cuotas'], 1, 0, 'C');
    $pdf->Cell($COLS[5], 5.5, date('d/m/Y', strtotime($r['primer_venc'])), 1, 0, 'C');
    $pdf->Cell($COLS[6], 5.5, lat(fmt($r['monto'])), 1, 0, 'R');
    $pdf->Ln();

    $index++;
}

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($COLS[0] + $COLS[1] + $COLS[2] + $COLS[3], 6, lat('TOTALES (' . count($por_cliente) . ' clientes)'), 1, 0, 'R');
$pdf->Cell($COLS[4], 6, (string)$total_cuotas, 1, 0, 'C');
$pdf->Cell($COLS[5], 6, '', 1, 0, 'C');
$pdf->Cell($COLS[6], 6, lat(fmt($total_monto)), 1, 0, 'R');
$pdf->Ln();

$zona_slug = preg_replace('/\W+/', '', $zona) ?: 'zona';
$nombre = 'zona_faltantes_cob' . $cobrador_id . '_' . $zona_slug . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
