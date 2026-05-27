<?php
// ============================================================
// admin/rendiciones_rango_pdf.php — PDF Rendiciones por Rango
// Combina APROBADOS (ic_pagos_confirmados) +
//         PENDIENTES (ic_pagos_temporales) en un solo archivo
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo            = obtener_conexion();
$desde          = trim($_GET['desde'] ?? '');
$hasta          = trim($_GET['hasta'] ?? '');
$cobrador_id    = (int)($_GET['cobrador_id'] ?? 0);
$estado_filtro  = in_array($_GET['estado'] ?? '', ['aprobado', 'pendiente']) ? $_GET['estado'] : 'todos';

if (!$desde || !$hasta) {
    die('Parámetros de rango (desde/hasta) requeridos.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    die('Formato de fecha inválido. Use YYYY-MM-DD.');
}
if ($desde > $hasta) {
    die('La fecha "desde" no puede ser posterior a "hasta".');
}

// ── Query APROBADOS (omitir si filtro = pendiente) ─────────────
$aprobados = [];
if ($estado_filtro !== 'pendiente') {
    $where_cob_a = $cobrador_id > 0 ? ' AND pc.cobrador_id = ?' : '';
    $params_a    = [$desde, $hasta];
    if ($cobrador_id > 0) $params_a[] = $cobrador_id;

    $stmt_a = $pdo->prepare("
        SELECT 'APROBADO' AS estado_pago,
               pc.cobrador_id,
               CONCAT(u.apellido, ', ', u.nombre)                                  AS cobrador,
               pc.fecha_jornada,
               COALESCE(pc.cliente_apellidos_snap, cl.apellidos, '—')              AS apellidos,
               COALESCE(pc.cliente_nombres_snap,   cl.nombres,   '—')              AS nombres,
               COALESCE(pc.numero_cuota, cu.numero_cuota, 0)                       AS numero_cuota,
               COALESCE(pc.articulo_snap, cr.articulo_desc, a.descripcion, '—')   AS articulo,
               pc.monto_efectivo, pc.monto_transferencia,
               pc.monto_mora_cobrada, pc.monto_total,
               COALESCE(pc.es_cuota_pura, 0) AS es_cuota_pura
        FROM ic_pagos_confirmados pc
        JOIN ic_usuarios u        ON pc.cobrador_id  = u.id
        LEFT JOIN ic_cuotas cu    ON pc.cuota_id     = cu.id
        LEFT JOIN ic_creditos cr  ON cu.credito_id   = cr.id
        LEFT JOIN ic_clientes cl  ON cr.cliente_id   = cl.id
        LEFT JOIN ic_articulos a  ON cr.articulo_id  = a.id
        WHERE pc.fecha_jornada BETWEEN ? AND ?
          AND pc.revertido = 0$where_cob_a
        ORDER BY cobrador ASC, pc.fecha_jornada ASC, apellidos ASC
    ");
    $stmt_a->execute($params_a);
    $aprobados = $stmt_a->fetchAll();
}

// ── Query PENDIENTES (omitir si filtro = aprobado) ─────────────
$pendientes = [];
if ($estado_filtro !== 'aprobado') {
    $where_cob_p = $cobrador_id > 0 ? ' AND pt.cobrador_id = ?' : '';
    $params_p    = [$desde, $hasta];
    if ($cobrador_id > 0) $params_p[] = $cobrador_id;

    $stmt_p = $pdo->prepare("
        SELECT 'PENDIENTE' AS estado_pago,
               pt.cobrador_id,
               CONCAT(u.apellido, ', ', u.nombre)              AS cobrador,
               pt.fecha_jornada,
               cl.apellidos, cl.nombres,
               cu.numero_cuota,
               COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
               pt.monto_efectivo, pt.monto_transferencia,
               pt.monto_mora_cobrada, pt.monto_total,
               COALESCE(pt.es_cuota_pura, 0) AS es_cuota_pura
        FROM ic_pagos_temporales pt
        JOIN ic_usuarios u  ON pt.cobrador_id  = u.id
        JOIN ic_cuotas cu   ON pt.cuota_id     = cu.id
        JOIN ic_creditos cr ON cu.credito_id   = cr.id
        JOIN ic_clientes cl ON cr.cliente_id   = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE pt.fecha_jornada BETWEEN ? AND ?
          AND pt.estado = 'PENDIENTE'$where_cob_p
        ORDER BY cobrador ASC, pt.fecha_jornada ASC, cl.apellidos ASC
    ");
    $stmt_p->execute($params_p);
    $pendientes = $stmt_p->fetchAll();
}

$todos = array_merge($aprobados, $pendientes);

if (empty($todos)) {
    die(iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE',
        'No hay pagos en el período ' .
        date('d/m/Y', strtotime($desde)) . ' – ' .
        date('d/m/Y', strtotime($hasta)) . '.'
    ));
}

// Ordenar: cobrador, fecha_jornada, apellidos
usort($todos, fn($a, $b) =>
    strcmp($a['cobrador'], $b['cobrador'])
    ?: strcmp($a['fecha_jornada'], $b['fecha_jornada'])
    ?: strcmp($a['apellidos'], $b['apellidos'])
);

// Agrupar: cobrador_id → fecha_jornada → pagos
$grupos = [];
foreach ($todos as $p) {
    $cid = (int)$p['cobrador_id'];
    $fj  = $p['fecha_jornada'];
    if (!isset($grupos[$cid])) {
        $grupos[$cid] = ['nombre' => $p['cobrador'], 'jornadas' => []];
    }
    $grupos[$cid]['jornadas'][$fj][] = $p;
}

// Label cobrador filtro
$label_cobrador = 'Todos los cobradores';
if ($cobrador_id > 0) {
    $sc = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
    $sc->execute([$cobrador_id]);
    $cv = $sc->fetch();
    if ($cv) $label_cobrador = $cv['apellido'] . ', ' . $cv['nombre'];
}

// ── PDF ────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: #(7)+Cliente(42)+Artículo(33)+Cuota(11)+Efectivo(20)+Transfer.(20)+Mora(16)+Total(22)+Estado(19) = 190
$COLS   = [7,  42, 33, 11, 20, 20, 16, 22, 19];
$LABELS = ['#','Cliente','Articulo','Cuota','Efectivo','Transfer.','Mora','Total','Estado'];
$ALIGNS = ['C','L','L','C','R','R','R','R','C'];

class RendicionesRangoPDF extends PDFBase
{
    public string $desde_lbl  = '';
    public string $hasta_lbl  = '';
    public string $cob_lbl    = '';
    public string $estado_lbl = '';
    public string $gen_fecha  = '';
    public array  $cols      = [];
    public array  $labels    = [];
    public array  $aligns    = [];

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
        $this->Cell(190, 5, lat('Rendiciones — Período ' . $this->desde_lbl . ' al ' . $this->hasta_lbl . '   [' . $this->estado_lbl . ']'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cob_lbl), 0, 0, 'L');
        $this->Cell(95, 5, lat('Generado: ' . $this->gen_fecha), 0, 1, 'R');

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

$pdf = new RendicionesRangoPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->desde_lbl  = date('d/m/Y', strtotime($desde));
$pdf->hasta_lbl  = date('d/m/Y', strtotime($hasta));
$pdf->cob_lbl    = $label_cobrador;
$pdf->estado_lbl = match($estado_filtro) {
    'aprobado'  => 'Solo Aprobados',
    'pendiente' => 'Solo Pendientes',
    default     => 'Aprobados y Pendientes',
};
$pdf->gen_fecha  = date('d/m/Y H:i');
$pdf->cols      = $COLS;
$pdf->labels    = $LABELS;
$pdf->aligns    = $ALIGNS;
$pdf->AddPage();

$ancho_total  = array_sum($COLS); // 190
$label_width  = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3]; // 93
$total_global      = 0.0;
$total_global_ef   = 0.0;
$total_global_tr   = 0.0;
$total_global_mora = 0.0;
$num_global        = 0;
$hay_mora_pend     = false;

foreach ($grupos as $cid => $grupo_cob) {

    // ── Encabezado cobrador ────────────────────────────────────
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(200, 200, 215);
    $pdf->SetX(10);
    $pdf->Cell($ancho_total, 6, lat('Cobrador: ' . $grupo_cob['nombre']), 1, 1, 'L', true);

    $total_cobrador      = 0.0;
    $total_cobrador_ef   = 0.0;
    $total_cobrador_tr   = 0.0;
    $total_cobrador_mora = 0.0;
    $num_cobrador        = 0;

    foreach ($grupo_cob['jornadas'] as $fecha_jornada => $pagos_jornada) {

        // ── Sub-header jornada ─────────────────────────────────
        $lbl_jornada  = label_jornada($fecha_jornada);
        $cant_jornada = count($pagos_jornada);

        $pdf->SetFont('Helvetica', 'BI', 7);
        $pdf->SetFillColor(235, 235, 245);
        $pdf->SetX(10);
        $pdf->Cell($ancho_total, 5,
            lat($lbl_jornada . '   (' . $cant_jornada . ' pago' . ($cant_jornada !== 1 ? 's' : '') . ')'),
            1, 1, 'L', true);

        // ── Filas de pagos ─────────────────────────────────────
        foreach ($pagos_jornada as $p) {
            $num_global++;
            $num_cobrador++;

            $es_pura  = (int)($p['es_cuota_pura'] ?? 0);
            $mora_val = (float)$p['monto_mora_cobrada'];

            if ($mora_val > 0 && $es_pura) {
                $mora_str  = fmt($mora_val) . '*';
                $hay_mora_pend = true;
            } elseif ($mora_val > 0) {
                $mora_str = fmt($mora_val);
            } else {
                $mora_str = '-';
            }

            $estado_str = $p['estado_pago'] === 'APROBADO' ? 'APROBADO' : 'PEND.';

            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetX(10);

            $pdf->Cell($COLS[0], 5.5, $num_global,                                                    1, 0, 'C', true);
            $pdf->Cell($COLS[1], 5.5, $pdf->fitText($p['apellidos'] . ', ' . $p['nombres'], $COLS[1] - 2), 1, 0, 'L', true);
            $pdf->Cell($COLS[2], 5.5, $pdf->fitText($p['articulo'], $COLS[2] - 2),                    1, 0, 'L', true);
            $pdf->Cell($COLS[3], 5.5, lat('#' . (int)$p['numero_cuota']),                              1, 0, 'C', true);
            $pdf->Cell($COLS[4], 5.5, lat(fmt((float)$p['monto_efectivo'])),                           1, 0, 'R', true);
            $pdf->Cell($COLS[5], 5.5, lat(fmt((float)$p['monto_transferencia'])),                      1, 0, 'R', true);

            if ($es_pura && $mora_val > 0) {
                $pdf->SetFont('Helvetica', 'I', 7);
            }
            $pdf->Cell($COLS[6], 5.5, lat($mora_str), 1, 0, 'R', true);
            $pdf->SetFont('Helvetica', '', 7);

            $pdf->Cell($COLS[7], 5.5, lat(fmt((float)$p['monto_total'])), 1, 0, 'R', true);

            // Columna Estado con color
            if ($p['estado_pago'] === 'APROBADO') {
                $pdf->SetTextColor(22, 163, 74);  // verde
                $pdf->SetFont('Helvetica', 'B', 6);
            } else {
                $pdf->SetTextColor(161, 98, 7);   // ámbar oscuro legible
                $pdf->SetFont('Helvetica', 'B', 6);
            }
            $pdf->Cell($COLS[8], 5.5, lat($estado_str), 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);

            $pdf->Ln();

            $total_cobrador      += (float)$p['monto_total'];
            $total_cobrador_ef   += (float)$p['monto_efectivo'];
            $total_cobrador_tr   += (float)$p['monto_transferencia'];
            $total_cobrador_mora += (float)$p['monto_mora_cobrada'];
            $total_global        += (float)$p['monto_total'];
            $total_global_ef     += (float)$p['monto_efectivo'];
            $total_global_tr     += (float)$p['monto_transferencia'];
            $total_global_mora   += (float)$p['monto_mora_cobrada'];
        }

        // ── Subtotal jornada ───────────────────────────────────
        $st_ef  = array_sum(array_column($pagos_jornada, 'monto_efectivo'));
        $st_tr  = array_sum(array_column($pagos_jornada, 'monto_transferencia'));
        $st_mo  = array_sum(array_column($pagos_jornada, 'monto_mora_cobrada'));
        $st_tot = array_sum(array_column($pagos_jornada, 'monto_total'));

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetFillColor(235, 235, 245);
        $pdf->SetX(10);
        $pdf->Cell($label_width, 5.5, lat('SUB. ' . $lbl_jornada), 1, 0, 'R', true);
        $pdf->Cell($COLS[4], 5.5, lat(fmt($st_ef)),  1, 0, 'R', true);
        $pdf->Cell($COLS[5], 5.5, lat(fmt($st_tr)),  1, 0, 'R', true);
        $pdf->Cell($COLS[6], 5.5, lat(fmt($st_mo)),  1, 0, 'R', true);
        $pdf->Cell($COLS[7], 5.5, lat(fmt($st_tot)), 1, 0, 'R', true);
        $pdf->Cell($COLS[8], 5.5, '',                1, 0, 'C', true);
        $pdf->Ln();
    }

    // ── Total cobrador ─────────────────────────────────────────
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetFillColor(210, 210, 230);
    $pdf->SetX(10);
    $pdf->Cell($label_width, 6,
        lat('TOTAL ' . mb_strtoupper($grupo_cob['nombre'], 'UTF-8')
            . ' (' . $num_cobrador . ' pago' . ($num_cobrador !== 1 ? 's' : '') . ')'),
        1, 0, 'R', true);
    $pdf->Cell($COLS[4], 6, lat(fmt($total_cobrador_ef)),   1, 0, 'R', true);
    $pdf->Cell($COLS[5], 6, lat(fmt($total_cobrador_tr)),   1, 0, 'R', true);
    $pdf->Cell($COLS[6], 6, lat(fmt($total_cobrador_mora)), 1, 0, 'R', true);
    $pdf->Cell($COLS[7], 6, lat(fmt($total_cobrador)),      1, 0, 'R', true);
    $pdf->Cell($COLS[8], 6, '',                              1, 0, 'C', true);
    $pdf->Ln();
    $pdf->Ln(2);
}

// ── Total general ──────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(180, 180, 210);
$pdf->SetX(10);
$pdf->Cell($label_width, 7,
    lat('TOTAL GENERAL (' . $num_global . ' pago' . ($num_global !== 1 ? 's' : '') . ')'),
    1, 0, 'R', true);
$pdf->Cell($COLS[4], 7, lat(fmt($total_global_ef)),   1, 0, 'R', true);
$pdf->Cell($COLS[5], 7, lat(fmt($total_global_tr)),   1, 0, 'R', true);
$pdf->Cell($COLS[6], 7, lat(fmt($total_global_mora)), 1, 0, 'R', true);
$pdf->Cell($COLS[7], 7, lat(fmt($total_global)),      1, 0, 'R', true);
$pdf->Cell($COLS[8], 7, '',                            1, 0, 'C', true);
$pdf->Ln();

// ── Nota al pie ────────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$nota = 'Estado: APROBADO = rendicion aprobada | PEND. = pendiente de aprobacion.';
if ($hay_mora_pend) {
    $nota .= '   (*) Mora pendiente de cobro (cuota pura), no incluida en el total.';
}
$pdf->Cell($ancho_total, 5, lat($nota), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);

$filename = 'rendiciones_rango_' . str_replace('-', '', $desde) . '_' . str_replace('-', '', $hasta) . '.pdf';
$pdf->Output('I', $filename);
