<?php
// ============================================================
// admin/historial_rendiciones_pdf.php — Exportación PDF del Historial
// A4 vertical, blanco y negro (basado en rendicion_pdf.php)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo         = obtener_conexion();
$fecha_sel   = $_GET['fecha']       ?? '';
$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);
$origen_sel  = in_array($_GET['origen'] ?? '', ['cobrador', 'manual']) ? $_GET['origen'] : 'cobrador';

if (!$fecha_sel || !$cobrador_id) die('Faltan parametros de busqueda (fecha o cobrador).');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

// Extraer los pagos ya confirmados en esa fecha, filtrados por origen
$dstmt = $pdo->prepare("
    SELECT pc.*,
           cl.nombres, cl.apellidos,
           cu.numero_cuota, cu.fecha_vencimiento,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu   ON pc.cuota_id     = cu.id
    JOIN ic_creditos cr ON cu.credito_id   = cr.id
    JOIN ic_clientes cl ON cr.cliente_id   = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id  = a.id
    LEFT JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    WHERE pc.cobrador_id = ? AND DATE(pc.fecha_aprobacion) = ?
      AND IFNULL(pt.origen, 'cobrador') = ?
    ORDER BY cl.apellidos ASC, cl.nombres ASC, pc.fecha_pago ASC
");
$dstmt->execute([$cobrador_id, $fecha_sel, $origen_sel]);
$pagos = $dstmt->fetchAll();

if (empty($pagos)) die('No hay pagos confirmados en la rendicion de esta fecha.');

$total_efectivo      = array_sum(array_column($pagos, 'monto_efectivo'));
$total_transferencia = array_sum(array_column($pagos, 'monto_transferencia'));
$total_mora          = array_sum(array_column($pagos, 'monto_mora_cobrada'));
$total_general       = array_sum(array_column($pagos, 'monto_total'));

function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.'); // Sin decimales según pedido anterior
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas: suma = 190mm (margen 10mm c/lado)
// #(8) + Cliente(45) + Articulo(37) + Cuota(13) + Vencim.(22) + Efectivo(22) + Transfer.(22) + Total(21)
$COLS   = [8, 45, 37, 13, 22, 22, 22, 21];
$LABELS = ['#', 'Cliente', 'Articulo', 'Cuota', 'Vencim.', 'Efectivo', 'Transfer.', 'Total'];
$ALIGNS = ['C', 'L', 'L', 'C', 'C', 'R', 'R', 'R'];

class RendicionHistorialPDF extends FPDF
{
    public string $cobrador_nombre = '';
    public string $fecha_label    = '';
    public string $tipo_origen    = '';
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
        $this->Cell(190, 7, lat('Imperio Comercial - Rendicion Historica'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_nombre . '   |   Tipo: ' . $this->tipo_origen), 0, 0, 'L');
        $this->Cell(95, 5, lat('Fecha Aprob.: ' . $this->fecha_label . '   |   Pagos: ' . $this->num_pagos), 0, 1, 'R');

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

$pdf = new RendicionHistorialPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->fecha_label     = date('d/m/Y', strtotime($fecha_sel));
$pdf->tipo_origen     = $origen_sel === 'manual' ? 'Manual (Admin)' : 'Cobrador';
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

$index = 1;
foreach ($pagos as $p) {
    $cliente  = mb_strimwidth($p['apellidos'] . ', ' . $p['nombres'], 0, 31, '..');
    $articulo = mb_strimwidth($p['articulo'], 0, 26, '..');

    $pdf->Cell($COLS[0], 6, $index,                      1, 0, 'C', false);
    $pdf->Cell($COLS[1], 6, lat($cliente),               1, 0, 'L', false);
    $pdf->Cell($COLS[2], 6, lat($articulo),              1, 0, 'L', false);
    $pdf->Cell($COLS[3], 6, '#' . $p['numero_cuota'],    1, 0, 'C', false);
    $pdf->Cell($COLS[4], 6, date('d/m/Y', strtotime($p['fecha_vencimiento'])), 1, 0, 'C', false);
    $pdf->Cell($COLS[5], 6, fmt($p['monto_efectivo']),       1, 0, 'R', false);
    $pdf->Cell($COLS[6], 6, fmt($p['monto_transferencia']),  1, 0, 'R', false);
    $pdf->Cell($COLS[7], 6, fmt($p['monto_total']),          1, 0, 'R', false);
    $pdf->Ln();
    $index++;
}

// ── Fila TOTALES — negrita, sin relleno ────────────────────────
$pdf->SetFont('Helvetica', 'B', 8);
$ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3] + $COLS[4];
$pdf->Cell($ancho_label, 7, lat('TOTALES'), 1, 0, 'R', false);
$pdf->Cell($COLS[5], 7, fmt($total_efectivo),      1, 0, 'R', false);
$pdf->Cell($COLS[6], 7, fmt($total_transferencia), 1, 0, 'R', false);
$pdf->Cell($COLS[7], 7, fmt($total_general),       1, 0, 'R', false);
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

$nombre = 'rendicion_historica_' . $origen_sel . '_' . str_replace('-', '', $fecha_sel) . '_' . $cobrador_id . '.pdf';
$pdf->Output('I', $nombre);
