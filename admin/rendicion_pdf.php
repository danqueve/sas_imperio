<?php
// ============================================================
// admin/rendicion_pdf.php — Exportación PDF con FPDF
// A4 horizontal (landscape), blanco y negro, sin rellenos
// Soporta una jornada (?fecha=X) o todas las pendientes (sin fecha)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo         = obtener_conexion();
$fecha_sel   = trim($_GET['fecha'] ?? '');
$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);
$multi_jornada = ($fecha_sel === '' || $fecha_sel === 'all');

if (!$cobrador_id) die('Cobrador no especificado.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

// ── Query: una fecha o todas las pendientes ─────────────────
if ($multi_jornada) {
    $dstmt = $pdo->prepare("
        SELECT pt.*,
               cr.id AS credito_id,
               cl.nombres, cl.apellidos,
               cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu   ON pt.cuota_id     = cu.id
        JOIN ic_creditos cr ON cu.credito_id   = cr.id
        JOIN ic_clientes cl ON cr.cliente_id   = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id  = a.id
        WHERE pt.cobrador_id = ? AND pt.estado = 'PENDIENTE'
        ORDER BY pt.fecha_jornada ASC, cl.apellidos ASC, cl.nombres ASC, cu.numero_cuota ASC
    ");
    $dstmt->execute([$cobrador_id]);
} else {
    $dstmt = $pdo->prepare("
        SELECT pt.*,
               cr.id AS credito_id,
               cl.nombres, cl.apellidos,
               cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu   ON pt.cuota_id     = cu.id
        JOIN ic_creditos cr ON cu.credito_id   = cr.id
        JOIN ic_clientes cl ON cr.cliente_id   = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id  = a.id
        WHERE pt.cobrador_id = ? AND pt.fecha_jornada = ? AND pt.estado = 'PENDIENTE'
        ORDER BY cl.apellidos ASC, cl.nombres ASC, cu.numero_cuota ASC
    ");
    $dstmt->execute([$cobrador_id, $fecha_sel]);
}
$pagos_raw = $dstmt->fetchAll();

if (empty($pagos_raw)) die('No hay pagos pendientes para esta rendicion.');

// ── Agrupar pagos multi-cuota por crédito + jornada ─────────
$agrupado = [];
foreach ($pagos_raw as $p) {
    $key = $p['fecha_jornada'] . '_' . (int) $p['credito_id'];
    if (!isset($agrupado[$key])) {
        $agrupado[$key] = $p;
        $agrupado[$key]['cuotas_nums'] = [(int) $p['numero_cuota']];
        $agrupado[$key]['monto_cuota_sum'] = (float) $p['monto_cuota'];
    } else {
        $agrupado[$key]['cuotas_nums'][]        = (int) $p['numero_cuota'];
        $agrupado[$key]['monto_cuota_sum']      += (float) $p['monto_cuota'];
        $agrupado[$key]['monto_efectivo']        = (float)$agrupado[$key]['monto_efectivo'] + (float)$p['monto_efectivo'];
        $agrupado[$key]['monto_transferencia']   = (float)$agrupado[$key]['monto_transferencia'] + (float)$p['monto_transferencia'];
        $agrupado[$key]['monto_total']           = (float)$agrupado[$key]['monto_total'] + (float)$p['monto_total'];
        $agrupado[$key]['monto_mora_cobrada']    = (float)$agrupado[$key]['monto_mora_cobrada'] + (float)$p['monto_mora_cobrada'];
        if ((int)($p['es_cuota_pura'] ?? 0))  $agrupado[$key]['es_cuota_pura']  = 1;
        if ((int)($p['solicitud_baja'] ?? 0)) $agrupado[$key]['solicitud_baja'] = 1;
    }
}
$pagos = array_values($agrupado);

// ── Agrupar por jornada ─────────────────────────────────────
$por_jornada = [];
foreach ($pagos as $p) {
    $por_jornada[$p['fecha_jornada']][] = $p;
}
ksort($por_jornada);
$fechas_jornada  = array_keys($por_jornada);
$cant_jornadas   = count($fechas_jornada);
$es_multi        = $cant_jornadas > 1;

// ── Totales globales ────────────────────────────────────────
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

function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}

require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas: suma = 257mm
$COLS   = [10, 55, 48, 18, 25, 25, 25, 30, 21];
$LABELS = ['#', 'Cliente', 'Articulo', 'Cuota(s)', 'Vlr. Cuota', 'Efectivo', 'Transfer.', 'Mora', 'Total'];
$ALIGNS = ['C', 'L', 'L', 'C', 'R', 'R', 'R', 'R', 'R'];
$ANCHO_TOTAL = array_sum($COLS); // 257

class RendicionPDF extends FPDF
{
    public string $cobrador_nombre  = '';
    public string $fecha_label     = '';
    public string $fecha_impresion = '';
    public int    $num_pagos       = 0;
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
        $this->Cell(257, 8, lat('Imperio Comercial - Rendicion de Cobranza'), 0, 1, 'L');

        // Subtítulo explicativo
        $this->SetFont('Helvetica', 'I', 9);
        $this->SetX(10);
        $this->Cell(257, 5, lat('Detalle de pagos registrados por el cobrador, pendientes de aprobacion'), 0, 1, 'L');

        // Datos del cobrador y fecha
        $this->SetFont('Helvetica', '', 9);
        $this->SetX(10);
        $this->Cell(128, 5, lat('Cobrador: ' . $this->cobrador_nombre), 0, 0, 'L');
        $this->Cell(129, 5, lat($this->fecha_label . '   |   Pagos: ' . $this->num_pagos . '   |   Impreso: ' . $this->fecha_impresion), 0, 1, 'R');

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
}

// ── Preparar label de fecha para el header ──────────────────
if ($es_multi) {
    $fecha_label = 'Jornadas: ' . date('d/m', strtotime($fechas_jornada[0])) . ' - ' . date('d/m/Y', strtotime(end($fechas_jornada)));
} else {
    $fecha_label = 'Fecha: ' . date('d/m/Y', strtotime($fechas_jornada[0]));
}

$pdf = new RendicionPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre  = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->fecha_label      = $fecha_label;
$pdf->fecha_impresion  = date('d/m/Y H:i');
$pdf->num_pagos        = count($pagos);
$pdf->cols            = $COLS;
$pdf->labels          = $LABELS;
$pdf->aligns          = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── Renderizar jornadas ─────────────────────────────────────
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

$dias_es = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];

foreach ($por_jornada as $fecha_j => $pagos_j):

    // Sub-encabezado de jornada (solo si multi-jornada)
    if ($es_multi) {
        $dow = (int) date('N', strtotime($fecha_j));
        $dia_nombre = $dias_es[$dow] ?? '';
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($ANCHO_TOTAL, 8, lat('Jornada: ' . $dia_nombre . ' ' . date('d/m/Y', strtotime($fecha_j))), 1, 1, 'L', true);
        $pdf->SetFillColor(255, 255, 255);
    }

    // Subtotales de esta jornada
    $j_efectivo = 0.0;
    $j_transfer = 0.0;
    $j_mora     = 0.0;
    $j_total    = 0.0;

    $index = 1;
    foreach ($pagos_j as $p) {
        $es_pura = (int)($p['es_cuota_pura'] ?? 0);
        $es_baja = (int)($p['solicitud_baja'] ?? 0);

        $cliente    = mb_strimwidth($p['apellidos'] . ', ' . $p['nombres'], 0, 36, '..');
        $articulo   = mb_strimwidth($p['articulo'], 0, 30, '..');
        $cuotas_str = implode(', ', array_map(fn($n) => '#' . $n, $p['cuotas_nums']));
        $vlr_cuota  = (float) $p['monto_cuota_sum'];

        $mora_val = (float) $p['monto_mora_cobrada'];
        if ($es_pura && $mora_val > 0) {
            $mora_str = fmt($mora_val) . ' (Pend.)';
        } elseif ($mora_val > 0) {
            $mora_str = fmt($mora_val);
        } else {
            $mora_str = '-';
        }

        $ef = (float) $p['monto_efectivo'];
        $tr = (float) $p['monto_transferencia'];
        $tt = (float) $p['monto_total'];

        $j_efectivo += $ef;
        $j_transfer += $tr;
        $j_mora     += $es_pura ? 0.0 : $mora_val;
        $j_total    += $tt;

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($COLS[0], 7, $index,              1, 0, 'C', false);
        $pdf->Cell($COLS[1], 7, lat($cliente),        1, 0, 'L', false);
        $pdf->Cell($COLS[2], 7, lat($articulo),       1, 0, 'L', false);
        $pdf->Cell($COLS[3], 7, lat($cuotas_str),     1, 0, 'C', false);
        $pdf->Cell($COLS[4], 7, fmt($vlr_cuota),      1, 0, 'R', false);
        $pdf->Cell($COLS[5], 7, fmt($ef),             1, 0, 'R', false);
        $pdf->Cell($COLS[6], 7, fmt($tr),             1, 0, 'R', false);

        if ($es_pura && $mora_val > 0) {
            $pdf->SetFont('Helvetica', 'I', 9);
        }
        $pdf->Cell($COLS[7], 7, lat($mora_str),       1, 0, 'R', false);
        $pdf->SetFont('Helvetica', '', 10);

        $pdf->Cell($COLS[8], 7, fmt($tt),             1, 0, 'R', false);
        $pdf->Ln();

        // Solicitud de baja inline
        if ($es_baja) {
            $motivo = trim($p['motivo_baja'] ?? '');
            $baja_txt = 'Solicitud de baja' . ($motivo ? ': ' . mb_strimwidth($motivo, 0, 80, '..') : '');
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell($COLS[0], 5, '', 0, 0);
            $pdf->Cell($ANCHO_TOTAL - $COLS[0], 5, lat($baja_txt), 0, 1, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Helvetica', '', 10);
        }

        $index++;
    }

    // Fila subtotal/total de jornada
    $pdf->SetFont('Helvetica', 'B', 10);
    $ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3] + $COLS[4];
    $label_total = $es_multi ? 'SUBTOTAL' : 'TOTALES';
    $pdf->Cell($ancho_label, 8, lat($label_total), 1, 0, 'R', false);
    $pdf->Cell($COLS[5], 8, fmt($j_efectivo),  1, 0, 'R', false);
    $pdf->Cell($COLS[6], 8, fmt($j_transfer),  1, 0, 'R', false);
    $pdf->Cell($COLS[7], 8, fmt($j_mora),      1, 0, 'R', false);
    $pdf->Cell($COLS[8], 8, fmt($j_total),     1, 0, 'R', false);
    $pdf->Ln();

    // Espacio entre jornadas
    if ($es_multi) {
        $pdf->Ln(4);
    }

endforeach;

// ── Fila TOTAL GLOBAL (solo si multi-jornada) ───────────────
if ($es_multi) {
    $pdf->SetFont('Helvetica', 'B', 11);
    $ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3] + $COLS[4];
    $pdf->Cell($ancho_label, 9, lat('TOTAL GENERAL'), 1, 0, 'R', false);
    $pdf->Cell($COLS[5], 9, fmt($total_efectivo),      1, 0, 'R', false);
    $pdf->Cell($COLS[6], 9, fmt($total_transferencia), 1, 0, 'R', false);
    $pdf->Cell($COLS[7], 9, fmt($total_mora_cobrada),  1, 0, 'R', false);
    $pdf->Cell($COLS[8], 9, fmt($total_general),       1, 0, 'R', false);
    $pdf->Ln();
}

// ── Resumen al pie ──────────────────────────────────────────
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
    $pdf->Cell($ANCHO_TOTAL, 5, lat('(Pend.) = Mora pendiente de cobro (cuota pura). Estos montos NO estan incluidos en los subtotales ni en el Total Rendido.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$nombre = $multi_jornada
    ? 'rendicion_completa_' . $cobrador_id . '_' . date('Ymd') . '.pdf'
    : 'rendicion_jornada_' . str_replace('-', '', $fecha_sel) . '_' . $cobrador_id . '.pdf';
$pdf->Output('I', $nombre);
