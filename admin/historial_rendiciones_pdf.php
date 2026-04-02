<?php
// ============================================================
// admin/historial_rendiciones_pdf.php — Exportación PDF del Historial
// A4 horizontal (landscape), blanco y negro, sin rellenos
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
// Usa columnas snapshot (inmutables) con fallback a JOIN vivo para registros legacy (NULL)
$dstmt = $pdo->prepare("
    SELECT pc.*,
           cr.id AS credito_id,
           cl.nombres, cl.apellidos, cl.id AS cliente_id,
           COALESCE(pc.numero_cuota,    cu.numero_cuota)          AS numero_cuota,
           COALESCE(pc.fecha_vcto_orig, cu.fecha_vencimiento)     AS fecha_vencimiento,
           COALESCE(pc.monto_cuota_orig, cu.monto_cuota)          AS monto_cuota,
           COALESCE(pc.articulo_snap, cr.articulo_desc, a.descripcion) AS articulo,
           COALESCE(pc.es_cuota_pura, pt.es_cuota_pura, 0)        AS es_cuota_pura,
           (SELECT COUNT(*)
            FROM ic_cuotas cu2
            JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
            WHERE cr2.cliente_id = cl.id
              AND cu2.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
              AND cr2.estado IN ('EN_CURSO','MOROSO')
              AND cu2.fecha_vencimiento < CURDATE()
              AND (cu2.monto_cuota - cu2.saldo_pagado) > 0
           ) AS cuotas_atrasadas_cliente
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu   ON pc.cuota_id     = cu.id
    JOIN ic_creditos cr ON cu.credito_id   = cr.id
    JOIN ic_clientes cl ON cr.cliente_id   = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id  = a.id
    LEFT JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
    WHERE pc.cobrador_id = ? AND DATE(pc.fecha_aprobacion) = ?
      AND COALESCE(pc.origen, IFNULL(pt.origen, 'cobrador')) = ?
    ORDER BY cl.apellidos ASC, cl.nombres ASC, numero_cuota ASC
");
$dstmt->execute([$cobrador_id, $fecha_sel, $origen_sel]);
$pagos_raw = $dstmt->fetchAll();

if (empty($pagos_raw)) die('No hay pagos confirmados en la rendicion de esta fecha.');

// ── Agrupar pagos multi-cuota por crédito ───────────────────
$agrupado = [];
foreach ($pagos_raw as $p) {
    $crid = (int) $p['credito_id'];
    if (!isset($agrupado[$crid])) {
        $agrupado[$crid] = $p;
        $agrupado[$crid]['cuotas_nums'] = [(int) $p['numero_cuota']];
        $agrupado[$crid]['monto_cuota_sum'] = (float) $p['monto_cuota'];
    } else {
        $agrupado[$crid]['cuotas_nums'][]        = (int) $p['numero_cuota'];
        $agrupado[$crid]['monto_cuota_sum']      += (float) $p['monto_cuota'];
        $agrupado[$crid]['monto_efectivo']        = (float)$agrupado[$crid]['monto_efectivo'] + (float)$p['monto_efectivo'];
        $agrupado[$crid]['monto_transferencia']   = (float)$agrupado[$crid]['monto_transferencia'] + (float)$p['monto_transferencia'];
        $agrupado[$crid]['monto_total']           = (float)$agrupado[$crid]['monto_total'] + (float)$p['monto_total'];
        $agrupado[$crid]['monto_mora_cobrada']    = (float)$agrupado[$crid]['monto_mora_cobrada'] + (float)$p['monto_mora_cobrada'];
        if ((int)($p['es_cuota_pura'] ?? 0)) $agrupado[$crid]['es_cuota_pura'] = 1;
    }
}
$pagos = array_values($agrupado);

// ── Totales ─────────────────────────────────────────────────
$total_efectivo      = 0.0;
$total_transferencia = 0.0;
$total_general       = 0.0;
$total_mora_cobrada  = 0.0;
$total_mora_pend     = 0.0;

foreach ($pagos as $p) {
    $total_efectivo      += (float) $p['monto_efectivo'];
    $total_transferencia += (float) $p['monto_transferencia'];
    $total_general       += (float) $p['monto_total'];
    if ((int)($p['es_cuota_pura'] ?? 0)) {
        $total_mora_pend += (float) $p['monto_mora_cobrada'];
    } else {
        $total_mora_cobrada += (float) $p['monto_mora_cobrada'];
    }
}

// ── Exportación CSV ──────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $nombre_cobrador = $cobrador['apellido'] . '_' . $cobrador['nombre'];
    $filename = 'rendicion_historica_' . $origen_sel . '_' . str_replace('-', '', $fecha_sel)
              . '_' . preg_replace('/[^a-zA-Z0-9_]/', '', $nombre_cobrador) . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    // BOM para que Excel abra correctamente con tildes
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Encabezado del reporte
    fputcsv($out, ['Imperio Comercial - Rendición Histórica'], ';');
    fputcsv($out, ['Cobrador:', $cobrador['apellido'] . ' ' . $cobrador['nombre']], ';');
    fputcsv($out, ['Fecha Aprobación:', date('d/m/Y', strtotime($fecha_sel))], ';');
    fputcsv($out, ['Tipo:', $origen_sel === 'manual' ? 'Manual (Admin)' : 'Cobrador'], ';');
    fputcsv($out, ['Cantidad de Pagos:', count($pagos)], ';');
    fputcsv($out, [], ';'); // línea en blanco

    // Cabeceras de columnas
    fputcsv($out, ['#', 'Cliente', 'Artículo', 'Cuota(s)', 'Valor Cuota', 'Efectivo', 'Transferencia', 'Mora', 'Total'], ';');

    // Filas de datos
    $index = 1;
    foreach ($pagos as $p) {
        $es_pura    = (int)($p['es_cuota_pura'] ?? 0);
        $mora_val   = (float) $p['monto_mora_cobrada'];
        $cuotas_str = implode(', ', array_map(fn($n) => '#' . $n, $p['cuotas_nums']));

        if ($es_pura && $mora_val > 0) {
            $mora_str = number_format($mora_val, 2, ',', '.') . ' (Pend.)';
        } elseif ($mora_val > 0) {
            $mora_str = number_format($mora_val, 2, ',', '.');
        } else {
            $mora_str = '';
        }

        fputcsv($out, [
            $index,
            $p['apellidos'] . ', ' . $p['nombres'],
            $p['articulo'],
            $cuotas_str,
            number_format((float)$p['monto_cuota_sum'], 2, ',', '.'),
            number_format((float)$p['monto_efectivo'], 2, ',', '.'),
            number_format((float)$p['monto_transferencia'], 2, ',', '.'),
            $mora_str,
            number_format((float)$p['monto_total'], 2, ',', '.'),
        ], ';');
        $index++;
    }

    // Fila de totales
    fputcsv($out, [], ';'); // línea en blanco
    $total_efectivo_csv      = array_sum(array_column($pagos, 'monto_efectivo'));
    $total_transferencia_csv = array_sum(array_column($pagos, 'monto_transferencia'));
    $total_general_csv       = array_sum(array_column($pagos, 'monto_total'));
    fputcsv($out, [
        '', '', '', '', 'TOTALES',
        number_format((float)$total_efectivo_csv, 2, ',', '.'),
        number_format((float)$total_transferencia_csv, 2, ',', '.'),
        '',
        number_format((float)$total_general_csv, 2, ',', '.'),
    ], ';');

    fclose($out);
    exit;
}
// ─────────────────────────────────────────────────────────────────

function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas: suma = 257mm
// #(10) + Cliente(55) + Articulo(48) + Cuota(s)(18) + Vlr.Cuota(25) + Efectivo(25) + Transfer.(25) + Mora(30) + Total(21)
$COLS   = [10, 55, 48, 18, 25, 25, 25, 30, 21];
$LABELS = ['#', 'Cliente', 'Articulo', 'Cuota(s)', 'Vlr. Cuota', 'Efectivo', 'Transfer.', 'Mora', 'Total'];
$ALIGNS = ['C', 'L', 'L', 'C', 'R', 'R', 'R', 'R', 'R'];

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
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        // Título
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetXY(10, 8);
        $this->Cell(257, 8, lat('Imperio Comercial - Rendicion Historica'), 0, 1, 'L');

        // Subtítulo explicativo
        $this->SetFont('Helvetica', 'I', 9);
        $this->SetX(10);
        $this->Cell(257, 5, lat('Detalle de pagos aprobados — Tipo: ' . $this->tipo_origen), 0, 1, 'L');

        // Datos del cobrador y fecha
        $this->SetFont('Helvetica', '', 9);
        $this->SetX(10);
        $this->Cell(128, 5, lat('Cobrador: ' . $this->cobrador_nombre), 0, 0, 'L');
        $this->Cell(129, 5, lat('Fecha Aprobacion: ' . $this->fecha_label . '   |   Pagos: ' . $this->num_pagos), 0, 1, 'R');

        // Línea separadora
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 2, 267, $this->GetY() + 2);
        $this->Ln(5);

        // Encabezado de tabla
        $this->SetFont('Helvetica', 'B', 9);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 7, lat($this->labels[$i]), 1, 0, $this->aligns[$i], false);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 9);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function fitText(string $text, float $maxW, string $suffix = '..'): string
    {
        $encoded = lat($text);
        if ($this->GetStringWidth($encoded) <= $maxW) {
            return $encoded;
        }
        while (mb_strlen($text) > 1) {
            $text = mb_substr($text, 0, -1);
            $encoded = lat($text);
            if ($this->GetStringWidth($encoded . $suffix) <= $maxW) {
                return $encoded . $suffix;
            }
        }
        return $suffix;
    }
}

$pdf = new RendicionHistorialPDF('L', 'mm', 'A4');
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
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

// Organizar en 2 secciones
$pagos_normal = [];
$pagos_5plus  = [];
foreach ($pagos as $p) {
    if ((int)$p['cuotas_atrasadas_cliente'] >= 5) {
        $pagos_5plus[] = $p;
    } else {
        $pagos_normal[] = $p;
    }
}

$secciones = [
    ['titulo' => 'Cobranza Normal (< 5 atrasadas)', 'datos' => $pagos_normal],
    ['titulo' => 'Morosos Criticos (5+ atrasadas)', 'datos' => $pagos_5plus]
];

$index = 1;
foreach ($secciones as $sec) {
    if (empty($sec['datos'])) continue;

    // Sub-encabezado de sección
    $pdf->SetFont('Helvetica', 'BI', 9);
    $pdf->SetTextColor(80, 80, 80);
    $ancho_total_seccion = array_sum($COLS);
    $pdf->Cell($ancho_total_seccion, 7, lat($sec['titulo']), 1, 1, 'L', false);
    $pdf->SetTextColor(0, 0, 0);

    $sec_efectivo = 0.0;
    $sec_transfer = 0.0;
    $sec_mora     = 0.0;
    $sec_total    = 0.0;

    foreach ($sec['datos'] as $p) {
        $es_pura = (int)($p['es_cuota_pura'] ?? 0);

        $cliente_raw = $p['apellidos'] . ', ' . $p['nombres'];
        if ((int)$p['cuotas_atrasadas_cliente'] >= 5) {
            $cliente_raw .= ' (At. ' . $p['cuotas_atrasadas_cliente'] . ')';
        }
        $articulo_raw = $p['articulo'];
        $cuotas_str = implode(', ', array_map(fn($n) => '#' . $n, $p['cuotas_nums']));
        $vlr_cuota  = (float) $p['monto_cuota_sum'];

        // Mora
        $mora_val = (float) $p['monto_mora_cobrada'];
        if ($es_pura && $mora_val > 0) {
            $mora_str = fmt($mora_val) . ' (Pend.)';
        } elseif ($mora_val > 0) {
            $mora_str = fmt($mora_val);
        } else {
            $mora_str = '-';
        }

        $sec_efectivo += (float)$p['monto_efectivo'];
        $sec_transfer += (float)$p['monto_transferencia'];
        $sec_total    += (float)$p['monto_total'];
        $sec_mora     += $es_pura ? 0.0 : $mora_val;

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($COLS[0], 7, $index,                                      1, 0, 'C', false);
        $pdf->Cell($COLS[1], 7, $pdf->fitText($cliente_raw, $COLS[1] - 1),   1, 0, 'L', false);
        $pdf->Cell($COLS[2], 7, $pdf->fitText($articulo_raw, $COLS[2] - 1),  1, 0, 'L', false);
        $pdf->Cell($COLS[3], 7, $pdf->fitText($cuotas_str, $COLS[3] - 1),   1, 0, 'C', false);
        $pdf->Cell($COLS[4], 7, fmt($vlr_cuota),                 1, 0, 'R', false);
        $pdf->Cell($COLS[5], 7, fmt((float)$p['monto_efectivo']),       1, 0, 'R', false);
        $pdf->Cell($COLS[6], 7, fmt((float)$p['monto_transferencia']),  1, 0, 'R', false);

        if ($es_pura && $mora_val > 0) {
            $pdf->SetFont('Helvetica', 'I', 9);
        }
        $pdf->Cell($COLS[7], 7, lat($mora_str),                  1, 0, 'R', false);
        $pdf->SetFont('Helvetica', '', 10);

        $pdf->Cell($COLS[8], 7, fmt((float)$p['monto_total']),   1, 0, 'R', false);
        $pdf->Ln();
        $index++;
    }

    // Fila SUBTOTAL de sección
    $pdf->SetFont('Helvetica', 'B', 9);
    $ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3] + $COLS[4];
    $label_total = 'SUBTOTAL ' . mb_strtoupper($sec['titulo'], 'UTF-8');
    $pdf->Cell($ancho_label, 7, lat($label_total), 1, 0, 'R', false);
    $pdf->Cell($COLS[5], 7, fmt($sec_efectivo), 1, 0, 'R', false);
    $pdf->Cell($COLS[6], 7, fmt($sec_transfer), 1, 0, 'R', false);
    $pdf->Cell($COLS[7], 7, fmt($sec_mora),     1, 0, 'R', false);
    $pdf->Cell($COLS[8], 7, fmt($sec_total),    1, 0, 'R', false);
    $pdf->Ln();
}

// ── Fila TOTALES ────────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 10);
$ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3] + $COLS[4];
$pdf->Cell($ancho_label, 8, lat('TOTALES'), 1, 0, 'R', false);
$pdf->Cell($COLS[5], 8, fmt($total_efectivo),      1, 0, 'R', false);
$pdf->Cell($COLS[6], 8, fmt($total_transferencia), 1, 0, 'R', false);
$pdf->Cell($COLS[7], 8, fmt($total_mora_cobrada),  1, 0, 'R', false);
$pdf->Cell($COLS[8], 8, fmt($total_general),       1, 0, 'R', false);
$pdf->Ln();

// ── Resumen al pie ──────────────────────────────────────────────
$pdf->Ln(10);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$bx  = 160;
$bw1 = 55;
$bw2 = 42;

$resumen = [
    ['Total Efectivo',               fmt($total_efectivo)],
    ['Total Transferencias',         fmt($total_transferencia)],
];
if ($total_mora_cobrada > 0) {
    $resumen[] = ['Mora Cobrada', fmt($total_mora_cobrada)];
}
if ($total_mora_pend > 0) {
    $resumen[] = ['Mora Pendiente (cuota pura)', fmt($total_mora_pend)];
}
$resumen[] = ['TOTAL RENDIDO', fmt($total_general)];

foreach ($resumen as $i => [$label, $valor]) {
    $es_total = ($i === count($resumen) - 1);
    $pdf->SetFont('Helvetica', $es_total ? 'B' : '', 11);
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 8, lat($label), 1, 0, 'L', false);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell($bw2, 8, lat($valor), 1, 1, 'R', false);
}

// ── Nota al pie sobre mora pendiente ─────────────────────────
if ($total_mora_pend > 0) {
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(10);
    $ancho_total = array_sum($COLS);
    $pdf->Cell($ancho_total, 5, lat('(Pend.) = Mora pendiente de cobro (cuota pura). Estos montos NO estan incluidos en los subtotales ni en el Total Rendido.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$nombre = 'rendicion_historica_' . $origen_sel . '_' . str_replace('-', '', $fecha_sel) . '_' . $cobrador_id . '.pdf';
$pdf->Output('I', $nombre);
