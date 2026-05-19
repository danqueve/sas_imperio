<?php
// ============================================================
// admin/finalizados_pdf.php — Reporte PDF de créditos finalizados
// A4 vertical, mismo patrón que atrasados_pdf.php
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros (mismos que finalizados.php) ─────────────────────
$fecha_desde = $_GET['desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
$motivo_f    = $_GET['motivo'] ?? '';
$cobrador_f  = (int)($_GET['cobrador_id'] ?? 0);

// ── WHERE dinámico ───────────────────────────────────────────
$where_extra = '';
$params      = [$fecha_desde, $fecha_hasta];
if ($motivo_f)   { $where_extra .= " AND cr.motivo_finalizacion=?"; $params[] = $motivo_f; }
if ($cobrador_f) { $where_extra .= " AND cr.cobrador_id=?";         $params[] = $cobrador_f; }

// ── Datos ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT cr.id,
           CONCAT(cl.apellidos,', ',cl.nombres)    AS cliente,
           COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
           cr.fecha_finalizacion,
           cr.motivo_finalizacion,
           cl.puntaje_pago,
           CONCAT(u.nombre,' ',u.apellido)          AS cobrador,
           IF(vend.id IS NOT NULL,
              CONCAT(vend.nombre,' ',vend.apellido),
              NULL)                                 AS vendedor
    FROM ic_creditos cr
    JOIN ic_clientes  cl   ON cr.cliente_id  = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    JOIN ic_usuarios  u    ON cr.cobrador_id  = u.id
    LEFT JOIN ic_vendedores vend ON cr.vendedor_id = vend.id
    WHERE cr.estado = 'FINALIZADO'
      AND cr.fecha_finalizacion BETWEEN ? AND ?
      $where_extra
    ORDER BY cr.fecha_finalizacion DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    die('No hay créditos finalizados en el período seleccionado.');
}

// ── Labels ───────────────────────────────────────────────────
$motivos_labels = [
    'PAGO_COMPLETO'          => 'Pago completo',
    'PAGO_COMPLETO_CON_MORA' => 'Pago c/mora',
    'RETIRO_PRODUCTO'        => 'Retiro prod.',
    'INCOBRABILIDAD'         => 'Incobrable',
    'ACUERDO_EXTRAJUDICIAL'  => 'Acuerdo ext.',
    'FINALIZADO_CREDITO'     => 'Fin. manual',
    'REFINANCIACION'         => 'Refinanciacion',
];
$puntaje_labels = [1 => 'Excelente', 2 => 'Bueno', 3 => 'Regular', 4 => 'Malo'];

// ── Encabezado del filtro cobrador ───────────────────────────
$cob_label = 'Todos';
if ($cobrador_f > 0) {
    $cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id=?");
    $cs->execute([$cobrador_f]);
    $cob = $cs->fetch();
    if ($cob) $cob_label = $cob['nombre'] . ' ' . $cob['apellido'];
}
$motivo_label = $motivo_f ? ($motivos_labels[$motivo_f] ?? $motivo_f) : 'Todos';

require_once __DIR__ . '/../lib/PDFBase.php';

// ── Columnas A4 vertical: 190mm de ancho útil ────────────────
// #(7) Cliente(40) Articulo(35) FechaFin(18) Motivo(27) Puntaje(16) Cobrador(23) Vendedor(24)
$COLS   = [7,  40,  35,  18,  27,  16,  23,  24];
$LABELS = ['#', 'Cliente', 'Articulo', 'Fecha Fin', 'Motivo', 'Puntaje', 'Cobrador', 'Vendedor'];
$ALIGNS = ['C', 'L',  'L',  'C',  'L',  'C',  'L',  'L'];

class FinalizadosPDF extends PDFBase
{
    public string $cob_label    = '';
    public string $motivo_label = '';
    public string $fecha_desde  = '';
    public string $fecha_hasta  = '';
    public string $fecha_gen    = '';
    public int    $num_reg      = 0;
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
        $this->Cell(190, 7, lat('Imperio Comercial - Creditos Finalizados'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(95, 5,
            lat('Período: ' . $this->fecha_desde . ' al ' . $this->fecha_hasta
                . '   |   Motivo: ' . $this->motivo_label),
            0, 0, 'L');
        $this->Cell(95, 5,
            lat('Cobrador: ' . $this->cob_label
                . '   |   Registros: ' . $this->num_reg
                . '   |   ' . $this->fecha_gen),
            0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(4);

        $this->SetFont('Helvetica', 'B', 7);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 7, lat($this->labels[$i]), 1, 0, $this->aligns[$i]);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 7);
    }
}

$pdf = new FinalizadosPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cob_label    = $cob_label;
$pdf->motivo_label = $motivo_label;
$pdf->fecha_desde  = date('d/m/Y', strtotime($fecha_desde));
$pdf->fecha_hasta  = date('d/m/Y', strtotime($fecha_hasta));
$pdf->fecha_gen    = date('d/m/Y H:i');
$pdf->num_reg      = count($rows);
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── Filas ─────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

$index = 1;
foreach ($rows as $r) {
    $cliente   = $pdf->fitText($r['cliente'],  $COLS[1] - 1);
    $articulo  = $pdf->fitText($r['articulo'], $COLS[2] - 1);
    $fecha_fin = $r['fecha_finalizacion']
        ? date('d/m/Y', strtotime($r['fecha_finalizacion']))
        : '—';
    $motivo    = lat($motivos_labels[$r['motivo_finalizacion']] ?? ($r['motivo_finalizacion'] ?? '—'));
    $puntaje   = lat($puntaje_labels[(int)$r['puntaje_pago']] ?? '—');
    $cobrador  = $pdf->fitText($r['cobrador'] ?? '—', $COLS[6] - 1);
    $vendedor  = $r['vendedor'] ? $pdf->fitText($r['vendedor'], $COLS[7] - 1) : lat('—');

    $pdf->Cell($COLS[0], 6, $index,      1, 0, 'C');
    $pdf->Cell($COLS[1], 6, $cliente,    1, 0, 'L');
    $pdf->Cell($COLS[2], 6, $articulo,   1, 0, 'L');
    $pdf->Cell($COLS[3], 6, $fecha_fin,  1, 0, 'C');
    $pdf->Cell($COLS[4], 6, $motivo,     1, 0, 'L');
    $pdf->Cell($COLS[5], 6, $puntaje,    1, 0, 'C');
    $pdf->Cell($COLS[6], 6, $cobrador,   1, 0, 'L');
    $pdf->Cell($COLS[7], 6, $vendedor,   1, 0, 'L');
    $pdf->Ln();
    $index++;
}

// ── Resumen al pie ────────────────────────────────────────────
$pdf->Ln(8);
$totales_motivo = [];
foreach ($rows as $r) {
    $k = $r['motivo_finalizacion'] ?? '—';
    $totales_motivo[$k] = ($totales_motivo[$k] ?? 0) + 1;
}

$bx  = 110;
$bw1 = 52;
$bw2 = 28;

$pdf->SetX($bx);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell($bw1 + $bw2, 6, lat('Resumen por motivo'), 1, 1, 'C');

$pdf->SetFont('Helvetica', '', 8);
foreach ($totales_motivo as $k => $cant) {
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 6, lat($motivos_labels[$k] ?? $k), 1, 0, 'L');
    $pdf->Cell($bw2, 6, $cant . ' credito' . ($cant !== 1 ? 's' : ''), 1, 1, 'R');
}

$pdf->SetX($bx);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell($bw1, 6, lat('TOTAL'), 1, 0, 'L');
$pdf->Cell($bw2, 6, count($rows) . ' creditos', 1, 1, 'R');

// ── Output ────────────────────────────────────────────────────
$nombre = 'finalizados_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
