<?php
// ============================================================
// admin/atrasados_pdf.php — Export PDF de cuotas atrasadas
// A4 vertical, blanco y negro (patrón historial_rendiciones_pdf.php)
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
    "cu.estado IN ('VENCIDA','PARCIAL')",
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

// ── Datos (sin paginación) ───────────────────────────────────
$sql = "
    SELECT
        cl.apellidos, cl.nombres, cl.zona,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        cu.numero_cuota, cr.cant_cuotas,
        cu.monto_cuota, cu.saldo_pagado,
        (cu.monto_cuota - cu.saldo_pagado) AS monto_adeudado,
        DATEDIFF(CURDATE(), cu.fecha_vencimiento)           AS dias_atraso,
        cu.fecha_vencimiento,
        (SELECT MAX(pc.fecha_pago)
         FROM ic_pagos_confirmados pc
         JOIN ic_cuotas cu2 ON pc.cuota_id = cu2.id
         WHERE cu2.credito_id = cr.id)                     AS ultimo_pago,
        u.nombre AS cob_nombre, u.apellido AS cob_apellido
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    JOIN ic_usuarios u       ON cr.cobrador_id = u.id
    WHERE $whereStr
    ORDER BY dias_atraso DESC, cl.apellidos ASC
";
$stmt     = $pdo->prepare($sql);
$stmt->execute($params);
$atrasados = $stmt->fetchAll();

if (empty($atrasados)) {
    die('No hay cuotas atrasadas con los filtros aplicados.');
}

$total_adeudado = array_sum(array_column($atrasados, 'monto_adeudado'));

// ── Nombre del cobrador para el header ──────────────────────
$cob_label  = 'Todos';
if ($cobrador_id > 0) {
    $cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
    $cs->execute([$cobrador_id]);
    $cob = $cs->fetch();
    if ($cob) $cob_label = $cob['nombre'] . ' ' . $cob['apellido'];
}

// ── Helpers FPDF ─────────────────────────────────────────────
function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas: suma = 190mm (margen 10mm c/lado)
// #(7) Cliente(38) Articulo(28) Cuota(12) Monto Adeudado(25) Dias(14) Ult.Pago(22) Zona(20) Cobrador(24)
$COLS   = [7, 38, 28, 12, 25, 14, 22, 20, 24];
$LABELS = ['#', 'Cliente', 'Articulo', 'Cuota', 'Monto Adeudado', 'Dias', 'Ult. Pago', 'Zona', 'Cobrador'];
$ALIGNS = ['C', 'L',  'L',  'C', 'R', 'C', 'C', 'L', 'L'];

class AtrasadosPDF extends FPDF
{
    public string $cobrador_label = '';
    public string $zona_label     = '';
    public string $fecha_gen      = '';
    public int    $num_registros  = 0;
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    function Header()
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 7, lat('Imperio Comercial - Cuotas Atrasadas'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_label . '   |   Zona: ' . $this->zona_label), 0, 0, 'L');
        $this->Cell(95, 5, lat('Generado: ' . $this->fecha_gen . '   |   Registros: ' . $this->num_registros), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(4);

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

$pdf = new AtrasadosPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_label = $cob_label;
$pdf->zona_label     = $zona_sel !== '' ? $zona_sel : 'Todas';
$pdf->fecha_gen      = date('d/m/Y H:i');
$pdf->num_registros  = count($atrasados);
$pdf->cols           = $COLS;
$pdf->labels         = $LABELS;
$pdf->aligns         = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── Filas de datos ───────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

$index = 1;
foreach ($atrasados as $r) {
    $cliente  = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 28, '..');
    $articulo = mb_strimwidth($r['articulo'], 0, 22, '..');
    $cobrador = mb_strimwidth($r['cob_apellido'] . ', ' . $r['cob_nombre'], 0, 18, '..');
    $zona     = mb_strimwidth($r['zona'] ?? '—', 0, 14, '..');
    $ult_pago = $r['ultimo_pago'] ? date('d/m/Y', strtotime($r['ultimo_pago'])) : '—';

    $pdf->Cell($COLS[0], 6, $index,                                   1, 0, 'C');
    $pdf->Cell($COLS[1], 6, lat($cliente),                            1, 0, 'L');
    $pdf->Cell($COLS[2], 6, lat($articulo),                           1, 0, 'L');
    $pdf->Cell($COLS[3], 6, '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'], 1, 0, 'C');
    $pdf->Cell($COLS[4], 6, fmt((float) $r['monto_adeudado']),        1, 0, 'R');
    $pdf->Cell($COLS[5], 6, $r['dias_atraso'] . ' d.',                1, 0, 'C');
    $pdf->Cell($COLS[6], 6, $ult_pago,                                1, 0, 'C');
    $pdf->Cell($COLS[7], 6, lat($zona),                               1, 0, 'L');
    $pdf->Cell($COLS[8], 6, lat($cobrador),                           1, 0, 'L');
    $pdf->Ln();
    $index++;
}

// ── Fila TOTALES ─────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 8);
$ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3];
$pdf->Cell($ancho_label, 7, lat('TOTALES'), 1, 0, 'R');
$pdf->Cell($COLS[4], 7, fmt($total_adeudado), 1, 0, 'R');
$pdf->Cell($COLS[5] + $COLS[6] + $COLS[7] + $COLS[8], 7, '', 1, 0, 'L');
$pdf->Ln();

// ── Resumen al pie ────────────────────────────────────────────
$pdf->Ln(8);
$bx  = 120;
$bw1 = 42;
$bw2 = 38;

$resumen = [
    ['Total cuotas atrasadas', count($atrasados) . ' cuotas'],
    ['TOTAL ADEUDADO',         fmt($total_adeudado)],
];

foreach ($resumen as $i => [$label, $valor]) {
    $es_total = ($i === count($resumen) - 1);
    $pdf->SetFont('Helvetica', $es_total ? 'B' : '', 9);
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 7, lat($label), 1, 0, 'L', false);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($bw2, 7, lat($valor), 1, 1, 'R', false);
}

// ── Output ───────────────────────────────────────────────────
$cob_slug  = $cobrador_id > 0 ? 'cob' . $cobrador_id : 'todos';
$zona_slug = $zona_sel !== '' ? '_' . preg_replace('/\W+/', '', $zona_sel) : '_todas';
$nombre    = 'atrasados_' . $cob_slug . $zona_slug . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
