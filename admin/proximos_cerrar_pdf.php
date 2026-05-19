<?php
// ============================================================
// admin/proximos_cerrar_pdf.php — PDF de créditos próximos a cerrar
// A4 vertical, mismo patrón que atrasados_pdf.php
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros (mismos que proximos_cerrar.php) ──────────────────
$cobrador_f = (int)($_GET['cobrador_id'] ?? 0);
$cuotas_f   = max(1, min(5, (int)($_GET['cuotas_max'] ?? 2)));

// ── Datos ─────────────────────────────────────────────────────
$where_cob = $cobrador_f ? 'AND cr.cobrador_id = ?' : '';
$params_q  = $cobrador_f ? [$cobrador_f, $cuotas_f] : [$cuotas_f];

$stmt = $pdo->prepare("
    SELECT
        cr.id AS credito_id,
        CONCAT(cl.apellidos, ', ', cl.nombres)       AS cliente,
        cl.puntaje_pago,
        CONCAT(u.nombre, ' ', u.apellido)            AS cobrador,
        IF(vend.id IS NOT NULL,
           CONCAT(vend.nombre,' ',vend.apellido),
           NULL)                                     AS vendedor,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        cr.monto_cuota,
        (SELECT MIN(fecha_vencimiento) FROM ic_cuotas
         WHERE credito_id = cr.id
           AND estado NOT IN ('PAGADA','CANCELADA'))  AS proximo_vencimiento,
        (SELECT COUNT(*) FROM ic_cuotas
         WHERE credito_id = cr.id
           AND estado NOT IN ('PAGADA','CANCELADA'))  AS cuotas_pendientes,
        (SELECT COUNT(*) FROM ic_cuotas
         WHERE credito_id = cr.id)                   AS total_cuotas,
        (SELECT COALESCE(SUM(monto_cuota - COALESCE(saldo_pagado,0)), 0) FROM ic_cuotas
         WHERE credito_id = cr.id
           AND estado NOT IN ('PAGADA','CANCELADA'))  AS saldo_restante
    FROM ic_creditos cr
    JOIN ic_clientes    cl   ON cr.cliente_id  = cl.id
    LEFT JOIN ic_articulos a  ON cr.articulo_id = a.id
    JOIN ic_usuarios    u    ON cr.cobrador_id  = u.id
    LEFT JOIN ic_vendedores vend ON cr.vendedor_id = vend.id
    WHERE cr.estado = 'EN_CURSO'
      $where_cob
    HAVING cuotas_pendientes BETWEEN 1 AND ?
    ORDER BY cuotas_pendientes ASC, proximo_vencimiento ASC
");
$stmt->execute($params_q);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    die('No hay creditos proximos a cerrar con los filtros aplicados.');
}

$saldo_total    = array_sum(array_column($rows, 'saldo_restante'));
$puntaje_labels = [1 => 'Excelente', 2 => 'Bueno', 3 => 'Regular', 4 => 'Malo'];

// ── Label cobrador para encabezado ────────────────────────────
$cob_label = 'Todos';
if ($cobrador_f > 0) {
    $cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id=?");
    $cs->execute([$cobrador_f]);
    $cob = $cs->fetch();
    if ($cob) $cob_label = $cob['nombre'] . ' ' . $cob['apellido'];
}

require_once __DIR__ . '/../lib/PDFBase.php';

// ── Columnas A4 vertical: 190mm de ancho útil ─────────────────
// #(7) Cliente(42) Articulo(38) Cuota(20) Prox.Venc(20) Cobrador(25) Saldo(20) Vendedor(18)
$COLS   = [7,  42,  38,  20,  20,  25,  20,  18];
$LABELS = ['#', 'Cliente', 'Articulo', 'Cuota', 'Prox. Venc.', 'Cobrador', 'Saldo', 'Vendedor'];
$ALIGNS = ['C', 'L',  'L',  'R',  'C',  'L',  'R',  'L'];

class ProximosCerrarPDF extends PDFBase
{
    public string $cob_label  = '';
    public int    $cuotas_max = 2;
    public string $fecha_gen  = '';
    public int    $num_reg    = 0;
    public array  $cols       = [];
    public array  $labels     = [];
    public array  $aligns     = [];

    function Header(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 7, lat('Imperio Comercial - Creditos Proximos a Cerrar'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(95, 5,
            lat('Cobrador: ' . $this->cob_label
                . '   |   Max. cuotas pendientes: ' . $this->cuotas_max),
            0, 0, 'L');
        $this->Cell(95, 5,
            lat('Generado: ' . $this->fecha_gen . '   |   Registros: ' . $this->num_reg),
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

$pdf = new ProximosCerrarPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cob_label  = $cob_label;
$pdf->cuotas_max = $cuotas_f;
$pdf->fecha_gen  = date('d/m/Y H:i');
$pdf->num_reg    = count($rows);
$pdf->cols       = $COLS;
$pdf->labels     = $LABELS;
$pdf->aligns     = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── Filas ─────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 7);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

$index = 1;
foreach ($rows as $r) {
    $cliente    = $pdf->fitText($r['cliente'],   $COLS[1] - 1);
    $articulo   = $pdf->fitText($r['articulo'],  $COLS[2] - 1);
    $cuota      = lat(fmt((float)$r['monto_cuota']));
    $prox_venc  = $r['proximo_vencimiento']
        ? date('d/m/Y', strtotime($r['proximo_vencimiento']))
        : '—';
    $cobrador   = $pdf->fitText($r['cobrador'] ?? '—', $COLS[5] - 1);
    $saldo      = lat(fmt((float)$r['saldo_restante']));
    $vendedor   = $r['vendedor']
        ? $pdf->fitText($r['vendedor'], $COLS[7] - 1)
        : lat('—');

    $pdf->Cell($COLS[0], 6, $index,     1, 0, 'C');
    $pdf->Cell($COLS[1], 6, $cliente,   1, 0, 'L');
    $pdf->Cell($COLS[2], 6, $articulo,  1, 0, 'L');
    $pdf->Cell($COLS[3], 6, $cuota,     1, 0, 'R');
    $pdf->Cell($COLS[4], 6, $prox_venc, 1, 0, 'C');
    $pdf->Cell($COLS[5], 6, $cobrador,  1, 0, 'L');
    $pdf->Cell($COLS[6], 6, $saldo,     1, 0, 'R');
    $pdf->Cell($COLS[7], 6, $vendedor,  1, 0, 'L');
    $pdf->Ln();
    $index++;
}

// ── Fila TOTALES ──────────────────────────────────────────────
$ancho_izq = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3] + $COLS[4] + $COLS[5];
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell($ancho_izq, 7, lat('TOTAL SALDO RESTANTE'), 1, 0, 'R');
$pdf->Cell($COLS[6],   7, lat(fmt($saldo_total)),       1, 0, 'R');
$pdf->Cell($COLS[7],   7, '',                            1, 0, 'L');
$pdf->Ln();

// ── Resumen al pie ────────────────────────────────────────────
$pdf->Ln(8);

// Conteo por puntaje
$por_puntaje = [];
foreach ($rows as $r) {
    $k = $r['puntaje_pago'] ? (int)$r['puntaje_pago'] : 0;
    $por_puntaje[$k] = ($por_puntaje[$k] ?? 0) + 1;
}

$bx  = 120;
$bw1 = 44;
$bw2 = 26;

$pdf->SetX($bx);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell($bw1 + $bw2, 6, lat('Distribucion por puntaje'), 1, 1, 'C');

$pdf->SetFont('Helvetica', '', 8);
$orden_puntaje = [1 => 'Excelente', 2 => 'Bueno', 3 => 'Regular', 4 => 'Malo', 0 => 'Sin datos'];
foreach ($orden_puntaje as $k => $lbl) {
    if (!isset($por_puntaje[$k])) continue;
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 6, lat($lbl), 1, 0, 'L');
    $pdf->Cell($bw2, 6, $por_puntaje[$k] . ' credito' . ($por_puntaje[$k] !== 1 ? 's' : ''), 1, 1, 'R');
}

$pdf->SetX($bx);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->Cell($bw1, 6, lat('Saldo total por cobrar'), 1, 0, 'L');
$pdf->Cell($bw2, 6, lat(fmt($saldo_total)),         1, 1, 'R');

// ── Output ────────────────────────────────────────────────────
$nombre = 'proximos_cerrar_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
