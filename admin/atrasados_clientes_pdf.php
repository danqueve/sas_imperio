<?php
// ============================================================
// admin/atrasados_clientes_pdf.php — PDF clientes atrasados
// Una fila por cliente, ordenado por cuotas vencidas (mayor→menor)
// Columnas: # | Cliente | Cuotas Venc. | Monto Cuotas | Total Adeudado | Máx. Días | Ult. Pago | Zona
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo         = obtener_conexion();
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$zona_sel    = trim($_GET['zona'] ?? '');

// ── WHERE dinámico ───────────────────────────────────────────
$where  = [
    "cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL')",
    "cr.estado IN ('EN_CURSO','MOROSO')",
    "cu.fecha_vencimiento < CURDATE()",
    "(cu.monto_cuota - cu.saldo_pagado) > 0",
];
$params = [];

if ($cobrador_id > 0) {
    $where[]  = 'cr.cobrador_id = ?';
    $params[] = $cobrador_id;
}
if ($zona_sel !== '') {
    $where[]  = 'cl.zona = ?';
    $params[] = $zona_sel;
}

$whereStr = implode(' AND ', $where);

// ── Query: una fila por cliente ──────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        cl.id          AS cliente_id,
        cl.apellidos, cl.nombres, cl.zona,
        COUNT(*)                                                    AS cant_cuotas,
        SUM(cu.monto_cuota)                                         AS monto_cuotas,
        SUM(cu.monto_cuota - cu.saldo_pagado)                       AS total_adeudado,
        MAX(DATEDIFF(CURDATE(), cu.fecha_vencimiento))              AS max_dias,
        (SELECT MAX(pc.fecha_pago)
         FROM ic_pagos_confirmados pc
         JOIN ic_cuotas cu2       ON pc.cuota_id    = cu2.id
         JOIN ic_creditos cr2     ON cu2.credito_id = cr2.id
         WHERE cr2.cliente_id = cl.id)                             AS ultimo_pago
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE $whereStr
    GROUP BY cl.id, cl.apellidos, cl.nombres, cl.zona
    ORDER BY cant_cuotas DESC, total_adeudado DESC, cl.apellidos ASC
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

if (empty($clientes)) {
    die('No hay clientes atrasados con los filtros aplicados.');
}

// ── Totales ──────────────────────────────────────────────────
$total_cuotas    = array_sum(array_column($clientes, 'cant_cuotas'));
$total_monto     = array_sum(array_column($clientes, 'monto_cuotas'));
$total_adeudado  = array_sum(array_column($clientes, 'total_adeudado'));

// ── Cobrador para el encabezado ──────────────────────────────
$cob_label = 'Todos';
if ($cobrador_id > 0) {
    $cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
    $cs->execute([$cobrador_id]);
    $cob = $cs->fetch();
    if ($cob) $cob_label = $cob['apellido'] . ', ' . $cob['nombre'];
}

// ── PDF ──────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: suma = 190mm (A4 portrait, márgenes 10mm c/lado)
// #(6) + Cliente(54) + C.Venc.(18) + Monto Cuotas(26) + Total Adeudado(28) + Máx. Días(15) + Ult. Pago(22) + Zona(21) = 190
$COLS   = [6,  54, 18, 26, 28, 15, 22, 21];
$LABELS = ['#','Cliente','C.Venc.','Monto Cuotas','Total Adeudado','Max. Dias','Ult. Pago','Zona'];
$ALIGNS = ['C','L','C','R','R','C','C','L'];

class AtrasadosClientesPDF extends PDFBase
{
    public string $cobrador_lbl = '';
    public string $zona_lbl     = '';
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
        $this->Cell(190, 5, lat('Clientes Atrasados — ordenados por cantidad de cuotas vencidas'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(65, 5, lat('Cobrador: ' . $this->cobrador_lbl), 0, 0, 'L');
        $this->Cell(65, 5, lat('Zona: ' . $this->zona_lbl), 0, 0, 'L');
        $this->Cell(60, 5, lat('Generado: ' . $this->fecha_gen . '  |  ' . $this->num_clientes . ' clientes'), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        // Encabezado de tabla
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(220, 220, 230);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new AtrasadosClientesPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->cobrador_lbl = $cob_label;
$pdf->zona_lbl     = $zona_sel !== '' ? $zona_sel : 'Todas';
$pdf->fecha_gen    = date('d/m/Y H:i');
$pdf->num_clientes = count($clientes);
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->AddPage();

// ── Filas de datos ───────────────────────────────────────────
$index = 1;
foreach ($clientes as $r) {
    $ult_pago = $r['ultimo_pago'] ? date('d/m/Y', strtotime($r['ultimo_pago'])) : '—';

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);

    $pdf->Cell($COLS[0], 5.5, $index,                                                        1, 0, 'C');
    $pdf->Cell($COLS[1], 5.5, $pdf->fitText($r['apellidos'] . ', ' . $r['nombres'], $COLS[1] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[2], 5.5, (int)$r['cant_cuotas'],                                        1, 0, 'C');
    $pdf->Cell($COLS[3], 5.5, lat(fmt((float)$r['monto_cuotas'])),                           1, 0, 'R');
    $pdf->Cell($COLS[4], 5.5, lat(fmt((float)$r['total_adeudado'])),                         1, 0, 'R');
    $pdf->Cell($COLS[5], 5.5, lat((int)$r['max_dias'] . ' d.'),                              1, 0, 'C');
    $pdf->Cell($COLS[6], 5.5, lat($ult_pago),                                                1, 0, 'C');
    $pdf->Cell($COLS[7], 5.5, $pdf->fitText($r['zona'] ?: '—', $COLS[7] - 2),               1, 0, 'L');
    $pdf->Ln();

    $index++;
}

// ── Fila TOTALES ─────────────────────────────────────────────
$ancho_label = $COLS[0] + $COLS[1];
$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($ancho_label, 6, lat('TOTALES (' . count($clientes) . ' clientes)'), 1, 0, 'R');
$pdf->Cell($COLS[2], 6, lat((string)$total_cuotas),         1, 0, 'C');
$pdf->Cell($COLS[3], 6, lat(fmt($total_monto)),              1, 0, 'R');
$pdf->Cell($COLS[4], 6, lat(fmt($total_adeudado)),           1, 0, 'R');
$pdf->Cell($COLS[5] + $COLS[6] + $COLS[7], 6, '',           1, 0, 'L');
$pdf->Ln();

// ── Resumen al pie ────────────────────────────────────────────
$pdf->Ln(8);
$bx  = 100;
$bw1 = 55;
$bw2 = 35;

$resumen = [
    ['Clientes atrasados',   count($clientes) . ' clientes'],
    ['Total cuotas vencidas', $total_cuotas . ' cuotas'],
    ['Monto total cuotas',   fmt($total_monto)],
    ['TOTAL ADEUDADO',       fmt($total_adeudado)],
];

foreach ($resumen as $i => [$label, $valor]) {
    $es_total = ($i === count($resumen) - 1);
    $pdf->SetFont('Helvetica', $es_total ? 'B' : '', 9);
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 7, lat($label), 1, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($bw2, 7, lat($valor), 1, 1, 'R');
}

// ── Output ───────────────────────────────────────────────────
$cob_slug  = $cobrador_id > 0 ? 'cob' . $cobrador_id : 'todos';
$zona_slug = $zona_sel !== '' ? '_' . preg_replace('/\W+/', '', $zona_sel) : '';
$nombre    = 'atrasados_clientes_' . $cob_slug . $zona_slug . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
