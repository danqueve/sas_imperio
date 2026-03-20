<?php
// ============================================================
// cobrador/agenda_pdf.php — Ficha semanal de cobros por cobrador
// v2: MOROSO, cuota X/Y, zona, teléfono, parcial, firma, resumen
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
$cobrador_id = $is_cobrador ? $user_id : (int)($_GET['cobrador_id'] ?? 0);
$dias_sel    = array_map('intval', (array)($_GET['dias'] ?? [1,2,3,4,5,6]));
$dias_sel    = array_filter($dias_sel, fn($d) => $d >= 1 && $d <= 6);
sort($dias_sel);

if (!$cobrador_id) die('Seleccioná un cobrador.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

if (empty($dias_sel)) die('Seleccioná al menos un día.');

// ── Consulta semanales (mejora 1: MOROSO; mejora 2: cant_cuotas; mejora 4: zona) ──
$placeholders = implode(',', array_fill(0, count($dias_sel), '?'));
$params = array_merge([$cobrador_id], $dias_sel);

$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cr.dia_cobro,
           cr.id AS credito_id, cr.interes_moratorio_pct, cr.cant_cuotas,
           cr.estado AS credito_estado,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado, cu.monto_mora, cu.saldo_pagado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_clientes cl
    JOIN ic_creditos cr  ON cr.cliente_id = cl.id
                        AND cr.cobrador_id = ?
                        AND cr.estado IN ('EN_CURSO','MOROSO')
                        AND cr.frecuencia = 'semanal'
    JOIN ic_cuotas  cu   ON cu.credito_id = cr.id
                        AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    JOIN (
        SELECT credito_id, COUNT(*) AS cuotas_atrasadas
        FROM ic_cuotas
        WHERE fecha_vencimiento < CURDATE()
          AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
        GROUP BY credito_id
        HAVING COUNT(*) < 5
    ) filtro ON filtro.credito_id = cr.id
    WHERE cr.dia_cobro IN ($placeholders)
    ORDER BY cr.dia_cobro ASC, COALESCE(cl.zona,'') ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupar por dia_cobro — un registro por cliente (cuota más atrasada)
$por_dia = [];
foreach ($dias_sel as $d) $por_dia[$d] = [];
$visto = [];
foreach ($rows as $r) {
    $clave = $r['dia_cobro'] . '-' . $r['credito_id'];
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

    // Mejora 4: encabezado de zona entre grupos
    function zonaHeader(string $zona)
    {
        $this->SetFont('Helvetica', 'BI', 7);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(array_sum($this->cols), 5, lat('  Zona: ' . strtoupper($zona)), 0, 1, 'L', false);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 7);
    }

    // Fila con segunda línea para teléfono (cliente) y cuota/adeudado (monto)
    // Retorna la altura de fila usada
    function drawRow(
        array  $r,
        string $cuota_label,
        string $venc,
        float  $monto_cuota,
        float  $total_cobrar,
        int    $dias_atraso
    ): float {
        $cols = $this->cols;
        $x0   = $this->GetX();
        $y0   = $this->GetY();

        $has_phone  = !empty(trim($r['telefono'] ?? ''));
        $has_monto2 = $dias_atraso > 0 || abs($total_cobrar - $monto_cuota) > 0.01;
        $row_h      = ($has_phone || $has_monto2) ? 9 : 6;
        $es_moroso  = ($r['credito_estado'] ?? '') === 'MOROSO';

        $cliente_name = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 36, '..');
        if ($es_moroso) $cliente_name = '[M] ' . $cliente_name;
        $articulo = mb_strimwidth($r['articulo'] ?? '-', 0, 35, '..');

        // Dibujar celdas con bordes
        $this->Cell($cols[0], $row_h, '', 1, 0, 'L', false);            // cliente (solo borde)
        $this->Cell($cols[1], $row_h, lat($articulo), 1, 0, 'L', false);
        $this->Cell($cols[2], $row_h, lat($cuota_label), 1, 0, 'C', false);
        $this->Cell($cols[3], $row_h, $venc, 1, 0, 'C', false);
        $this->Cell($cols[4], $row_h, '', 1, 0, 'R', false);            // monto (solo borde)
        $this->Ln();

        // Texto cliente — línea 1
        $this->SetFont('Helvetica', $es_moroso ? 'B' : '', 7);
        $this->SetXY($x0 + 0.8, $y0 + 0.8);
        $this->Cell($cols[0] - 1, 4, lat($cliente_name), 0, 0, 'L', false);

        // Texto cliente — línea 2 (teléfono)
        if ($has_phone) {
            $this->SetFont('Helvetica', 'I', 6);
            $this->SetTextColor(80, 80, 80);
            $this->SetXY($x0 + 0.8, $y0 + 4.5);
            $this->Cell($cols[0] - 1, 3.5, lat('Tel: ' . mb_strimwidth($r['telefono'], 0, 24, '')), 0, 0, 'L', false);
            $this->SetTextColor(0, 0, 0);
        }

        // Texto monto — línea 1: monto de la cuota
        $mx        = $x0 + $cols[0] + $cols[1] + $cols[2] + $cols[3];
        $y_monto1  = $has_monto2 ? $y0 + 0.8 : $y0 + 1.5;
        $this->SetFont('Helvetica', '', 7);
        $this->SetXY($mx + 0.5, $y_monto1);
        $this->Cell($cols[4] - 1, 4, lat(fmt($monto_cuota)), 0, 0, 'R', false);

        // Texto monto — línea 2: días atraso + monto adeudado
        if ($has_monto2) {
            $es_cap = ($r['cuota_estado'] ?? '') === 'CAP_PAGADA';
            if ($es_cap) {
                $detalle = 'Mora: ' . fmt($total_cobrar);
            } else {
                $detalle = ($dias_atraso > 0 ? $dias_atraso . ' d. | ' : '') . fmt($total_cobrar);
            }
            $this->SetFont('Helvetica', 'I', 6);
            $this->SetTextColor(80, 80, 80);
            $this->SetXY($mx + 0.5, $y0 + 4.5);
            $this->Cell($cols[4] - 1, 3.5, lat($detalle), 0, 0, 'R', false);
            $this->SetTextColor(0, 0, 0);
        }

        // Restablecer cursor al inicio de la siguiente fila
        $this->SetXY(10, $y0 + $row_h);
        $this->SetFont('Helvetica', '', 7);

        return $row_h;
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

// Mejora 5: coleccionar datos para resumen
$resumen = [];

// ── Una sección por día ──────────────────────────────────────────
foreach ($dias_sel as $dia) {
    $clientes_dia = $por_dia[$dia] ?? [];
    $nombre_dia   = [1=>'Lunes',2=>'Martes',3=>'Miercoles',4=>'Jueves',5=>'Viernes',6=>'Sabado'][$dia];

    // Pre-calcular total del día
    $total_dia = 0;
    foreach ($clientes_dia as $r) {
        $mora = ($r['cuota_estado'] === 'CAP_PAGADA')
            ? (float)$r['monto_mora']
            : calcular_mora((float)$r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float)$r['interes_moratorio_pct']);
        $saldo_p = (float)($r['saldo_pagado'] ?? 0);
        $total_dia += ($r['cuota_estado'] === 'CAP_PAGADA') ? $mora : max(0, (float)$r['monto_cuota'] + $mora - $saldo_p);
    }

    $cant = count($clientes_dia);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(100, 7, lat($nombre_dia . ' — ' . $cant . ' cuota(s)'), 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(90, 7, lat('Total del dia: ' . fmt($total_dia)), 0, 1, 'R');

    if (empty($clientes_dia)) {
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->Cell(190, 6, lat('Sin clientes para este dia.'), 1, 1, 'C', false);
        $pdf->Ln(3);
        $resumen[] = ['tipo' => 'dia', 'label' => $nombre_dia, 'cant' => 0, 'total' => 0.0];
        continue;
    }

    $pdf->encabezadoTabla();
    $pdf->SetFont('Helvetica', '', 7);

    $zona_actual = null;

    foreach ($clientes_dia as $r) {
        // Salto de página si hace falta (zona header ~5mm + fila máxima 9mm)
        if ($pdf->GetY() + 16 > $pdf->GetPageHeight() - 18) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(190, 6, lat($nombre_dia . ' (continuacion)'), 0, 1, 'L');
            $pdf->encabezadoTabla();
            $pdf->SetFont('Helvetica', '', 7);
            $zona_actual = null;
        }

        // Mejora 4: encabezado de zona cuando cambia
        $zona_fila = $r['zona'] ?? '';
        if ($zona_fila !== $zona_actual) {
            $zona_actual = $zona_fila;
            if (!empty($zona_fila)) {
                $pdf->zonaHeader($zona_fila);
            }
        }

        // Mejora 2: cuota X/Y
        $cuota_label = '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'];

        $mora = ($r['cuota_estado'] === 'CAP_PAGADA')
            ? (float)$r['monto_mora']
            : calcular_mora((float)$r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float)$r['interes_moratorio_pct']);
        $saldo_p      = (float)($r['saldo_pagado'] ?? 0);
        $total_cobrar = ($r['cuota_estado'] === 'CAP_PAGADA') ? $mora : max(0, (float)$r['monto_cuota'] + $mora - $saldo_p);
        $dias_atraso  = dias_atraso_habiles($r['fecha_vencimiento']);

        $venc = date('d/m', strtotime($r['fecha_vencimiento']));
        $pdf->drawRow($r, $cuota_label, $venc, (float)$r['monto_cuota'], $total_cobrar, $dias_atraso);
    }

    // Fila total del día
    $pdf->SetFont('Helvetica', 'B', 7);
    $ancho = array_sum(array_slice($COLS, 0, 4));
    $pdf->Cell($ancho, 6, lat('TOTAL ' . strtoupper($nombre_dia)), 1, 0, 'R', false);
    $pdf->Cell($COLS[4], 6, fmt($total_dia), 1, 0, 'R', false);
    $pdf->Ln();
    $pdf->Ln(3);

    $resumen[] = ['tipo' => 'dia', 'label' => $nombre_dia, 'cant' => $cant, 'total' => $total_dia];
}

// ── Sección: Quincenales y Mensuales ────────────────────────────
$stmt_qm = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.zona,
           cr.frecuencia, cr.cant_cuotas, cr.estado AS credito_estado,
           cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota, cu.estado AS cuota_estado,
           cu.monto_mora, cu.saldo_pagado,
           cr.interes_moratorio_pct,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    JOIN (
        SELECT credito_id, COUNT(*) AS cuotas_atrasadas
        FROM ic_cuotas
        WHERE fecha_vencimiento < CURDATE()
          AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
        GROUP BY credito_id
        HAVING COUNT(*) < 5
    ) filtro ON filtro.credito_id = cr.id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
      AND cr.frecuencia IN ('quincenal', 'mensual')
      AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'CAP_PAGADA', 'PARCIAL')
    ORDER BY cr.frecuencia ASC, COALESCE(cl.zona,'') ASC, cu.fecha_vencimiento ASC, cl.apellidos ASC
");
$stmt_qm->execute([$cobrador_id]);
$rows_qm = $stmt_qm->fetchAll();

if (!empty($rows_qm)) {
    $qm_aux = ['quincenal' => [], 'mensual' => []];
    foreach ($rows_qm as $r) {
        $mora = ($r['cuota_estado'] === 'CAP_PAGADA')
            ? (float) $r['monto_mora']
            : calcular_mora((float) $r['monto_cuota'], dias_atraso_habiles($r['fecha_vencimiento']), (float) $r['interes_moratorio_pct']);
        $saldo_p      = (float)($r['saldo_pagado'] ?? 0);
        $total_cobrar = ($r['cuota_estado'] === 'CAP_PAGADA') ? $mora : max(0, (float) $r['monto_cuota'] + $mora - $saldo_p);

        $key = $r['cliente_id'];
        if (!isset($qm_aux[$r['frecuencia']][$key])) {
            $r['total_final'] = $total_cobrar;
            $r['cant_ven']    = 1;
            $qm_aux[$r['frecuencia']][$key] = $r;
        } else {
            $qm_aux[$r['frecuencia']][$key]['total_final'] += $total_cobrar;
            $qm_aux[$r['frecuencia']][$key]['cant_ven']++;
        }
    }
    $qm_grupos = [
        'quincenal' => array_values($qm_aux['quincenal']),
        'mensual'   => array_values($qm_aux['mensual']),
    ];

    foreach ($qm_grupos as $frec => $lista) {
        if (empty($lista)) continue;

        $titulo     = $frec === 'quincenal' ? 'Quincenales' : 'Mensuales';
        $total_frec = array_sum(array_column($lista, 'total_final'));

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(100, 7, lat($titulo . ' — ' . count($lista) . ' cliente(s)'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(90, 7, lat('Total: ' . fmt($total_frec)), 0, 1, 'R');

        $pdf->encabezadoTabla();
        $pdf->SetFont('Helvetica', '', 7);

        $zona_actual = null;

        foreach ($lista as $r) {
            if ($pdf->GetY() + 16 > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(190, 6, lat($titulo . ' (continuacion)'), 0, 1, 'L');
                $pdf->encabezadoTabla();
                $pdf->SetFont('Helvetica', '', 7);
                $zona_actual = null;
            }

            // Mejora 4: encabezado de zona
            $zona_fila = $r['zona'] ?? '';
            if ($zona_fila !== $zona_actual) {
                $zona_actual = $zona_fila;
                if (!empty($zona_fila)) {
                    $pdf->zonaHeader($zona_fila);
                }
            }

            $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);

            // Mejora 2: cuota X/Y (o "N u." si tiene múltiples)
            $cuota_label = ($r['cant_ven'] > 1)
                ? $r['cant_ven'] . ' u.'
                : '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'];

            $venc = date('d/m/y', strtotime($r['fecha_vencimiento']));
            $pdf->drawRow($r, $cuota_label, $venc, (float)$r['monto_cuota'], (float)$r['total_final'], $dias_atraso);
        }

        // Fila total de frecuencia
        $pdf->SetFont('Helvetica', 'B', 7);
        $ancho = array_sum(array_slice($COLS, 0, 4));
        $pdf->Cell($ancho, 6, lat('TOTAL ' . strtoupper($titulo)), 1, 0, 'R', false);
        $pdf->Cell($COLS[4], 6, fmt($total_frec), 1, 0, 'R', false);
        $pdf->Ln();
        $pdf->Ln(3);

        $resumen[] = ['tipo' => 'frec', 'label' => $titulo, 'cant' => count($lista), 'total' => $total_frec];
    }
}

// ── Mejora 5: Resumen general al final ──────────────────────────
if (!empty($resumen)) {
    $pdf->Ln(2);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(190, 7, lat('Resumen General'), 0, 1, 'L');

    // Separar por tipo
    $dias_res = array_values(array_filter($resumen, fn($r) => $r['tipo'] === 'dia'));
    $frec_res = array_values(array_filter($resumen, fn($r) => $r['tipo'] === 'frec'));

    $total_gral_cant  = 0;
    $total_gral_monto = 0.0;

    // ── Grupo Semanales (Lun–Sáb) ──────────────────────────────
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(190, 6, lat('  Semanales'), 1, 1, 'L', true);
    $pdf->SetFillColor(255, 255, 255);

    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell(100, 5, lat('Dia'), 1, 0, 'L');
    $pdf->Cell(45,  5, lat('Cuotas'), 1, 0, 'C');
    $pdf->Cell(45,  5, lat('Monto'), 1, 1, 'R');

    $sub_cant_dias  = 0;
    $sub_monto_dias = 0.0;
    $pdf->SetFont('Helvetica', '', 7);
    foreach ($dias_res as $row) {
        $pdf->Cell(100, 5, lat($row['label']), 1, 0, 'L');
        $pdf->Cell(45,  5, (string)$row['cant'], 1, 0, 'C');
        $pdf->Cell(45,  5, fmt($row['total']), 1, 1, 'R');
        $sub_cant_dias  += $row['cant'];
        $sub_monto_dias += $row['total'];
    }
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell(100, 5, lat('Subtotal Semanales'), 1, 0, 'R');
    $pdf->Cell(45,  5, (string)$sub_cant_dias, 1, 0, 'C');
    $pdf->Cell(45,  5, fmt($sub_monto_dias), 1, 1, 'R');
    $total_gral_cant  += $sub_cant_dias;
    $total_gral_monto += $sub_monto_dias;

    // ── Grupo Quincenales y Mensuales ───────────────────────────
    if (!empty($frec_res)) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(190, 6, lat('  Quincenales / Mensuales'), 1, 1, 'L', true);
        $pdf->SetFillColor(255, 255, 255);

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell(100, 5, lat('Frecuencia'), 1, 0, 'L');
        $pdf->Cell(45,  5, lat('Clientes'), 1, 0, 'C');
        $pdf->Cell(45,  5, lat('Monto'), 1, 1, 'R');

        $sub_cant_frec  = 0;
        $sub_monto_frec = 0.0;
        $pdf->SetFont('Helvetica', '', 7);
        foreach ($frec_res as $row) {
            $pdf->Cell(100, 5, lat($row['label']), 1, 0, 'L');
            $pdf->Cell(45,  5, (string)$row['cant'], 1, 0, 'C');
            $pdf->Cell(45,  5, fmt($row['total']), 1, 1, 'R');
            $sub_cant_frec  += $row['cant'];
            $sub_monto_frec += $row['total'];
        }
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell(100, 5, lat('Subtotal Quinc./Mens.'), 1, 0, 'R');
        $pdf->Cell(45,  5, (string)$sub_cant_frec, 1, 0, 'C');
        $pdf->Cell(45,  5, fmt($sub_monto_frec), 1, 1, 'R');
        $total_gral_cant  += $sub_cant_frec;
        $total_gral_monto += $sub_monto_frec;
    }

    // ── Total General ───────────────────────────────────────────
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(100, 7, lat('TOTAL GENERAL'), 1, 0, 'R');
    $pdf->Cell(45,  7, (string)$total_gral_cant, 1, 0, 'C');
    $pdf->Cell(45,  7, fmt($total_gral_monto), 1, 1, 'R');
}

// ── Sección: Clientes con 5+ cuotas atrasadas ───────────────────
$stmt_atr = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cl.zona,'') AS zona,
           cr.id AS credito_id, cr.cant_cuotas, cr.frecuencia,
           cr.estado AS credito_estado, cr.interes_moratorio_pct,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           COUNT(cu.id)        AS cuotas_atrasadas,
           MIN(cu.monto_cuota) AS valor_cuota,
           SUM(cu.monto_cuota) AS monto_base,
           (SELECT MAX(pt.fecha_jornada)
            FROM ic_pagos_confirmados pc
            JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
            JOIN ic_cuotas cu2          ON cu2.id = pc.cuota_id
            WHERE cu2.credito_id = cr.id) AS ultimo_pago
    FROM ic_creditos cr
    JOIN ic_clientes cl  ON cl.id  = cr.cliente_id
    JOIN ic_cuotas   cu  ON cu.credito_id = cr.id
                        AND cu.fecha_vencimiento < CURDATE()
                        AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
    GROUP BY cr.id, cl.id, cl.nombres, cl.apellidos, cl.telefono, cl.zona,
             cr.cant_cuotas, cr.frecuencia, cr.estado, cr.interes_moratorio_pct, articulo
    HAVING COUNT(cu.id) >= 5
    ORDER BY COALESCE(cl.zona,'') ASC, cl.apellidos ASC
");
$stmt_atr->execute([$cobrador_id]);
$rows_atr = $stmt_atr->fetchAll();

if (!empty($rows_atr)) {
    // Agrupar por zona
    $atr_por_zona = [];
    foreach ($rows_atr as $r) {
        $atr_por_zona[$r['zona']][] = $r;
    }

    $pdf->AddPage();

    // Encabezado sección
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(190, 7, lat('Clientes con 5 o mas cuotas atrasadas'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(95, 5, lat('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 0, 'L');
    $pdf->Cell(95, 5, lat('Emision: ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->SetLineWidth(0.4);
    $pdf->Line(10, $pdf->GetY() + 1, 200, $pdf->GetY() + 1);
    $pdf->Ln(5);

    // Columnas: Cliente(48) + Artículo(40) + Adeud.(18) + Valor cuota(28) + Total(30) + Ult.Pago(26) = 190
    $CA = [48, 40, 18, 28, 30, 26];
    $LA = ['Cliente / Tel.', 'Articulo', 'Adeud.', 'Valor cuota', 'Total', 'Ult. Pago'];

    $zona_actual = null;

    foreach ($atr_por_zona as $zona => $lista) {
        // Encabezado de zona
        $pdf->SetFont('Helvetica', 'BI', 8);
        $pdf->SetFillColor(240, 240, 240);
        $zona_txt = !empty($zona) ? strtoupper($zona) : 'SIN ZONA';
        $pdf->Cell(190, 6, lat('  Zona: ' . $zona_txt . '  (' . count($lista) . ' credito(s))'), 1, 1, 'L', true);
        $pdf->SetFillColor(255, 255, 255);

        // Encabezado columnas
        $pdf->SetFont('Helvetica', 'B', 7);
        foreach ($CA as $i => $w) {
            $pdf->Cell($w, 5, lat($LA[$i]), 1, 0, 'L');
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 7);
        $total_zona = 0.0;

        foreach ($lista as $r) {
            // Salto de página si hace falta
            if ($pdf->GetY() + 11 > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(190, 6, lat('Clientes con 5+ cuotas atrasadas (continuacion)'), 0, 1, 'L');
                $pdf->SetFont('Helvetica', 'B', 7);
                foreach ($CA as $i => $w) {
                    $pdf->Cell($w, 5, lat($LA[$i]), 1, 0, 'L');
                }
                $pdf->Ln();
                $pdf->SetFont('Helvetica', '', 7);
            }

            $has_phone = !empty(trim($r['telefono'] ?? ''));
            $row_h     = 9; // siempre 9mm: dos líneas en cuotas y posible teléfono
            $x0 = $pdf->GetX();
            $y0 = $pdf->GetY();

            $es_moroso    = $r['credito_estado'] === 'MOROSO';
            $cliente_name = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 33, '..');
            if ($es_moroso) $cliente_name = '[M] ' . $cliente_name;
            $articulo    = mb_strimwidth($r['articulo'] ?? '-', 0, 30, '..');
            $valor_cuota = (float)$r['valor_cuota'];
            $monto_total = (float)$r['monto_base'];
            $total_zona += $monto_total;

            // Celdas con borde
            $pdf->Cell($CA[0], $row_h, '', 1, 0, 'L', false);              // cliente (borde)
            $pdf->Cell($CA[1], $row_h, lat($articulo), 1, 0, 'L', false);
            $pdf->Cell($CA[2], $row_h, '', 1, 0, 'C', false);              // cuotas (borde)
            $pdf->Cell($CA[3], $row_h, lat(fmt($valor_cuota)), 1, 0, 'R', false);
            $pdf->Cell($CA[4], $row_h, '', 1, 0, 'R', false);              // total (borde)
            $pdf->Cell($CA[5], $row_h, '', 1, 0, 'L', false);              // ult. pago (borde)
            $pdf->Ln();

            // Texto cliente — línea 1
            $pdf->SetFont('Helvetica', $es_moroso ? 'B' : '', 7);
            $pdf->SetXY($x0 + 0.8, $y0 + 0.8);
            $pdf->Cell($CA[0] - 1, 4, lat($cliente_name), 0, 0, 'L', false);

            // Texto cliente — línea 2 (teléfono)
            if ($has_phone) {
                $pdf->SetFont('Helvetica', 'I', 6);
                $pdf->SetTextColor(80, 80, 80);
                $pdf->SetXY($x0 + 0.8, $y0 + 4.5);
                $pdf->Cell($CA[0] - 1, 3.5, lat('Tel: ' . mb_strimwidth($r['telefono'], 0, 22, '')), 0, 0, 'L', false);
                $pdf->SetTextColor(0, 0, 0);
            }

            // Cuotas adeudadas — línea 1: número destacado
            $cx = $x0 + $CA[0] + $CA[1];
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetXY($cx + 0.5, $y0 + 0.8);
            $pdf->Cell($CA[2] - 1, 4, (string)$r['cuotas_atrasadas'], 0, 0, 'C', false);

            // Cuotas adeudadas — línea 2: "de N" en pequeño gris
            $pdf->SetFont('Helvetica', 'I', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($cx + 0.5, $y0 + 4.5);
            $pdf->Cell($CA[2] - 1, 3.5, lat('de ' . $r['cant_cuotas']), 0, 0, 'C', false);
            $pdf->SetTextColor(0, 0, 0);

            // Total — columna 5
            $pdf->SetFont('Helvetica', '', 7);
            $mx = $x0 + $CA[0] + $CA[1] + $CA[2] + $CA[3];
            $pdf->SetXY($mx + 0.5, $y0 + 1.5);
            $pdf->Cell($CA[4] - 1, 4, lat(fmt($monto_total)), 0, 0, 'R', false);

            // Último pago — columna 6
            $ult_txt = !empty($r['ultimo_pago'])
                ? date('d/m/y', strtotime($r['ultimo_pago']))
                : 'Sin pagos';
            $ux = $x0 + $CA[0] + $CA[1] + $CA[2] + $CA[3] + $CA[4];
            $pdf->SetFont('Helvetica', empty($r['ultimo_pago']) ? 'I' : '', 6.5);
            if (empty($r['ultimo_pago'])) $pdf->SetTextColor(150, 80, 80);
            $pdf->SetXY($ux + 0.5, $y0 + 2.5);
            $pdf->Cell($CA[5] - 1, 4, lat($ult_txt), 0, 0, 'C', false);
            $pdf->SetTextColor(0, 0, 0);

            $pdf->SetXY(10, $y0 + $row_h);
            $pdf->SetFont('Helvetica', '', 7);
        }

        // Total zona
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($CA[0] + $CA[1] + $CA[2] + $CA[3], 5, lat('Total zona'), 1, 0, 'R');
        $pdf->Cell($CA[4], 5, lat(fmt($total_zona)), 1, 0, 'R');
        $pdf->Cell($CA[5], 5, '', 1, 1, 'L');
        $pdf->Ln(3);
    }

    // Total general sección
    $total_atr = array_sum(array_map(fn($r) => (float)$r['monto_base'], $rows_atr));
    $pdf->SetFont('Helvetica', 'B', 8);
    $ancho_atr = $CA[0] + $CA[1] + $CA[2] + $CA[3];
    $pdf->Cell($ancho_atr, 6, lat('TOTAL GENERAL — ' . count($rows_atr) . ' credito(s)'), 1, 0, 'R');
    $pdf->Cell($CA[4], 6, lat(fmt($total_atr)), 1, 0, 'R');
    $pdf->Cell($CA[5], 6, '', 1, 1, 'L');
}

$nombre = 'agenda_semanal_' . $cobrador_id . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
