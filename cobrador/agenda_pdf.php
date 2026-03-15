<?php
// ============================================================
// cobrador/agenda_pdf.php — Ficha semanal de cobros por cobrador
// Selección: cobrador + días de semana (Lun–Sáb)
// A4 vertical, blanco y negro, sin rellenos
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo         = obtener_conexion();
$is_cobrador = es_cobrador();
$user_id     = $_SESSION['user_id'];

// ── Parámetros GET ─────────────────────────────────────────────
// cobrador_id: si es cobrador, forzar el propio
$cobrador_id = $is_cobrador ? $user_id : (int)($_GET['cobrador_id'] ?? 0);
// dias[]: array de enteros 1-6 (Lunes=1 … Sábado=6)
$dias_sel    = array_map('intval', (array)($_GET['dias'] ?? [1,2,3,4,5,6]));
$dias_sel    = array_filter($dias_sel, fn($d) => $d >= 1 && $d <= 6);
sort($dias_sel);

if (!$cobrador_id) die('Seleccioná un cobrador.');

// Datos del cobrador
$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

// Nombres de días
$nombres_dia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles',
                4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado'];

// ── Consulta: clientes activos del cobrador con cuotas pendientes
// agrupados por dia_cobro de ic_clientes ─────────────────────────
if (empty($dias_sel)) die('Seleccioná al menos un día.');

$placeholders = implode(',', array_fill(0, count($dias_sel), '?'));
$params = array_merge([$cobrador_id], $dias_sel);

$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.dia_cobro,
           cr.id AS credito_id, cr.interes_moratorio_pct,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado, cu.monto_mora,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_clientes cl
    JOIN ic_creditos cr  ON cr.cliente_id  = cl.id  AND cr.cobrador_id = ? AND cr.estado = 'EN_CURSO'
                        AND cr.frecuencia = 'semanal'
    JOIN ic_cuotas  cu   ON cu.credito_id  = cr.id  AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA')
    LEFT JOIN ic_articulos a  ON a.id            = cr.articulo_id
    WHERE cl.dia_cobro IN ($placeholders)
    ORDER BY cl.dia_cobro ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupar por dia_cobro — un registro por cliente (la cuota más atrasada)
// El SQL ya ordena por fecha_vencimiento ASC, por lo que el primer registro
// encontrado para cada cliente en cada día es el de mayor atraso.
$por_dia = [];
foreach ($dias_sel as $d) $por_dia[$d] = [];
$visto = [];
foreach ($rows as $r) {
    $clave = $r['dia_cobro'] . '-' . $r['cliente_id'];
    if (isset($visto[$clave])) continue;
    $visto[$clave] = true;
    $por_dia[$r['dia_cobro']][] = $r;
}

// ── Helpers ─────────────────────────────────────────────────────
function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 2, ',', '.');
}

// ── FPDF ────────────────────────────────────────────────────────
require_once __DIR__ . '/../fpdf/fpdf.php';

// Anchos columnas = 190mm total (A4 210mm − 10mm izq − 10mm der)
// Cliente(65) + Articulo(45) + Cuota(15) + Vencim.(22) + Monto(43)
$COLS   = [65, 45, 15, 22, 43];
$LABELS = ['Cliente', 'Articulo', 'Cuota', 'Vencim.', 'Monto'];
$ALIGNS = ['L', 'L', 'C', 'C', 'R'];

class AgendaPDF extends FPDF
{
    public string $cobrador_nombre = '';
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    // Encabezado de tabla reutilizable
    function encabezadoTabla()
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], false);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 7);
    }
}

$pdf = new AgendaPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->cols   = $COLS;
$pdf->labels = $LABELS;
$pdf->aligns = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

// ── Encabezado del documento ─────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell(190, 7, lat('Imperio Comercial - Ficha Semanal de Cobros'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(95, 5, lat('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 0, 'L');
$dias_label = implode(', ', array_map(fn($d) => [1=>'Lun',2=>'Mar',3=>'Mie',4=>'Jue',5=>'Vie',6=>'Sab'][$d], $dias_sel));
$pdf->Cell(95, 5, lat('Dias: ' . $dias_label . '   |   Emision: ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetLineWidth(0.4);
$pdf->Line(10, $pdf->GetY() + 1, 200, $pdf->GetY() + 1);
$pdf->Ln(5);

// ── Una sección por día ──────────────────────────────────────────
foreach ($dias_sel as $dia) {
    $clientes_dia = $por_dia[$dia] ?? [];
    $nombre_dia   = [1=>'Lunes',2=>'Martes',3=>'Miercoles',4=>'Jueves',5=>'Viernes',6=>'Sabado'][$dia];

    // Título del día
    $pdf->SetFont('Helvetica', 'B', 10);
    $total_dia = 0;
    foreach ($clientes_dia as $r) {
        $mora = ($r['cuota_estado'] === 'CAP_PAGADA')
            ? (float)$r['monto_mora']
            : calcular_mora((float)$r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float)$r['interes_moratorio_pct']);
        $total_cobrar = ($r['cuota_estado'] === 'CAP_PAGADA') ? $mora : (float)$r['monto_cuota'] + $mora;
        $total_dia += $total_cobrar;
    }
    $cant      = count($clientes_dia);
    $pdf->Cell(100, 7, lat($nombre_dia . ' — ' . $cant . ' cuota(s)'), 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(90, 7, lat('Total del dia: ' . fmt($total_dia)), 0, 1, 'R');

    if (empty($clientes_dia)) {
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->Cell(190, 6, lat('Sin clientes para este dia.'), 1, 1, 'C', false);
        $pdf->Ln(3);
        continue;
    }

    // Encabezado tabla
    $pdf->encabezadoTabla();

    // Filas
    $pdf->SetFont('Helvetica', '', 7);
    foreach ($clientes_dia as $r) {
        // Salto de página si hace falta
        if ($pdf->GetY() + 6 > $pdf->GetPageHeight() - 18) {
            $pdf->AddPage();
            // Retomar título del día
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(190, 6, lat($nombre_dia . ' (continuacion)'), 0, 1, 'L');
            $pdf->encabezadoTabla();
            $pdf->SetFont('Helvetica', '', 7);
        }

        $cliente  = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 40, '..');
        $articulo = mb_strimwidth($r['articulo'], 0, 35, '..');
        $venc     = date('d/m', strtotime($r['fecha_vencimiento']));

        $mora = ($r['cuota_estado'] === 'CAP_PAGADA')
            ? (float)$r['monto_mora']
            : calcular_mora((float)$r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float)$r['interes_moratorio_pct']);
        $total_cobrar = ($r['cuota_estado'] === 'CAP_PAGADA') ? $mora : (float)$r['monto_cuota'] + $mora;
        $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);

        $monto_str = fmt($total_cobrar);
        if ($dias_atraso > 0) {
            $monto_str .= ' (' . $dias_atraso . ' d. atraso)';
        }

        $pdf->Cell($COLS[0], 6, lat($cliente),        1, 0, 'L', false);
        $pdf->Cell($COLS[1], 6, lat($articulo),       1, 0, 'L', false);
        $pdf->Cell($COLS[2], 6, '#' . $r['numero_cuota'], 1, 0, 'C', false);
        $pdf->Cell($COLS[3], 6, $venc,                1, 0, 'C', false);
        $pdf->Cell($COLS[4], 6, lat($monto_str),      1, 0, 'R', false);
        $pdf->Ln();
    }

    // Fila total del día
    $pdf->SetFont('Helvetica', 'B', 7);
    $ancho = array_sum(array_slice($COLS, 0, 4));
    $pdf->Cell($ancho, 6, lat('TOTAL ' . strtoupper($nombre_dia)), 1, 0, 'R', false);
    $pdf->Cell($COLS[4], 6, fmt($total_dia), 1, 0, 'R', false);
    $pdf->Ln();
    $pdf->Ln(5);
}

// ── Sección: Quincenales y Mensuales ────────────────────────────
$stmt_qm = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.zona,
           cr.frecuencia,
           cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota, cu.estado AS cuota_estado,
           cu.monto_mora,
           cr.interes_moratorio_pct,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    WHERE cr.cobrador_id = ?
      AND cr.estado = 'EN_CURSO'
      AND cr.frecuencia IN ('quincenal', 'mensual')
      AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'CAP_PAGADA')
    ORDER BY cr.frecuencia ASC, cu.fecha_vencimiento ASC, cl.apellidos ASC
");
$stmt_qm->execute([$cobrador_id]);
$rows_qm = $stmt_qm->fetchAll();

if (!empty($rows_qm)) {
    // Separar por frecuencia y agrupar por cliente
    $qm_aux = ['quincenal' => [], 'mensual' => []];
    foreach ($rows_qm as $r) {
        $mora = ($r['cuota_estado'] === 'CAP_PAGADA')
            ? (float) $r['monto_mora']
            : calcular_mora((float) $r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float) $r['interes_moratorio_pct']);
        $total_cobrar = ($r['cuota_estado'] === 'CAP_PAGADA') ? $mora : (float) $r['monto_cuota'] + $mora;
        
        $key = $r['cliente_id'];
        if (!isset($qm_aux[$r['frecuencia']][$key])) {
            $r['total_final'] = $total_cobrar;
            $r['cant_ven']    = 1;
            $r['cuotas_list'] = '#' . $r['numero_cuota'];
            $qm_aux[$r['frecuencia']][$key] = $r;
        } else {
            $qm_aux[$r['frecuencia']][$key]['total_final'] += $total_cobrar;
            $qm_aux[$r['frecuencia']][$key]['cant_ven']++;
            $qm_aux[$r['frecuencia']][$key]['cuotas_list'] .= ', #' . $r['numero_cuota'];
        }
    }
    $qm_grupos = ['quincenal' => array_values($qm_aux['quincenal']), 'mensual' => array_values($qm_aux['mensual'])];

    foreach ($qm_grupos as $frec => $lista) {
        if (empty($lista)) continue;

        $titulo = $frec === 'quincenal' ? 'Quincenales' : 'Mensuales';
        $total_frec = array_sum(array_column($lista, 'total_final'));

        // Título de sección (continuación si corresponde)
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(100, 7, lat($titulo . ' — ' . count($lista) . ' cliente(s)'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(90, 7, lat('Total: ' . fmt($total_frec)), 0, 1, 'R');

        $pdf->encabezadoTabla();
        $pdf->SetFont('Helvetica', '', 7);

        foreach ($lista as $r) {
            if ($pdf->GetY() + 6 > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(190, 6, lat($titulo . ' (continuacion)'), 0, 1, 'L');
                $pdf->encabezadoTabla();
                $pdf->SetFont('Helvetica', '', 7);
            }

            $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);
            $cliente  = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 40, '..');
            $articulo = mb_strimwidth($r['articulo'] ?? '-', 0, 35, '..');
            $venc     = date('d/m/y', strtotime($r['fecha_vencimiento']));
            
            $monto_str = fmt($r['total_final']);
            if ($dias_atraso > 0) {
                // Si tiene mas de una cuota, indicamos la cantidad
                if ($r['cant_ven'] > 1) {
                    $monto_str .= ' (' . $r['cant_ven'] . ' cuotas atrasadas)';
                } else {
                    $monto_str .= ' (' . $dias_atraso . ' d. atraso)';
                }
            }

            $cuota_label = ($r['cant_ven'] > 1) ? $r['cant_ven'] . ' u.' : '#' . $r['numero_cuota'];

            $pdf->Cell($COLS[0], 6, lat($cliente),          1, 0, 'L', false);
            $pdf->Cell($COLS[1], 6, lat($articulo),         1, 0, 'L', false);
            $pdf->Cell($COLS[2], 6, lat($cuota_label),      1, 0, 'C', false);
            $pdf->Cell($COLS[3], 6, $venc,                  1, 0, 'C', false);
            $pdf->Cell($COLS[4], 6, lat($monto_str),        1, 0, 'R', false);
            $pdf->Ln();
        }

        // Fila total
        $pdf->SetFont('Helvetica', 'B', 7);
        $ancho = array_sum(array_slice($COLS, 0, 4));
        $pdf->Cell($ancho, 6, lat('TOTAL ' . strtoupper($titulo)), 1, 0, 'R', false);
        $pdf->Cell($COLS[4], 6, fmt($total_frec), 1, 0, 'R', false);
        $pdf->Ln();
        $pdf->Ln(5);
    }
}

$nombre = 'agenda_semanal_' . $cobrador_id . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
