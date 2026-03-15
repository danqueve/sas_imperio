<?php
// ============================================================
// admin/estadisticas_pdf.php — Exportación PDF de Estadísticas
// A4 Portrait, una página por cobrador
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_estadisticas');

$pdo = obtener_conexion();

// ── Rango de la semana ────────────────────────────────────────
$hoy = new DateTimeImmutable('today');
$dow = (int) $hoy->format('N');
$lunes_actual = $hoy->modify('-' . ($dow - 1) . ' days');

if (!empty($_GET['semana'])) {
    try {
        $lunes_sel = new DateTimeImmutable($_GET['semana']);
        $dow_sel   = (int) $lunes_sel->format('N');
        $lunes_sel = $lunes_sel->modify('-' . ($dow_sel - 1) . ' days');
    } catch (Exception $e) {
        $lunes_sel = $lunes_actual;
    }
} else {
    $lunes_sel = $lunes_actual;
}

$sabado_sel = $lunes_sel->modify('+5 days');
$inicio_str = $lunes_sel->format('Y-m-d');
$fin_str    = $sabado_sel->format('Y-m-d');

$frecuencias = ['semanal', 'quincenal', 'mensual'];
$nombres_dia = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado'];

$dias_semana = [];
for ($i = 0; $i < 6; $i++) {
    $d = $lunes_sel->modify("+{$i} days");
    $dias_semana[$d->format('Y-m-d')] = (int) $d->format('N');
}

// ── Queries (idénticas a estadisticas_cobranza.php) ───────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios
     WHERE rol = 'cobrador' AND activo = 1
     ORDER BY apellido, nombre"
)->fetchAll();

$stmt_cobros = $pdo->prepare("
    SELECT pt.cobrador_id, pt.fecha_jornada, cr.frecuencia,
           COUNT(DISTINCT pt.cuota_id)  AS cuotas_cobradas,
           SUM(pt.monto_total)          AS monto_cobrado,
           SUM(pt.monto_efectivo)       AS efectivo,
           SUM(pt.monto_transferencia)  AS transferencia,
           SUM(pt.monto_mora_cobrada)   AS mora_cobrada
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas   cu ON pt.cuota_id   = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE pt.fecha_jornada BETWEEN ? AND ?
      AND pt.estado IN ('PENDIENTE', 'APROBADO')
    GROUP BY pt.cobrador_id, pt.fecha_jornada, cr.frecuencia
");
$stmt_cobros->execute([$inicio_str, $fin_str]);
$cobros_raw = $stmt_cobros->fetchAll();

$stmt_agenda = $pdo->prepare("
    SELECT cr.cobrador_id, cu.fecha_vencimiento, cr.frecuencia,
           COUNT(*)             AS cuotas_agendadas,
           SUM(cu.monto_cuota)  AS monto_estimado
    FROM ic_cuotas   cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    WHERE cu.fecha_vencimiento BETWEEN ? AND ?
      AND cr.estado = 'EN_CURSO'
    GROUP BY cr.cobrador_id, cu.fecha_vencimiento, cr.frecuencia
");
$stmt_agenda->execute([$inicio_str, $fin_str]);
$agenda_raw = $stmt_agenda->fetchAll();

// ── Construir $data (idéntico a estadisticas_cobranza.php) ────
function _dia_vacio(array $freqs): array
{
    $pt = array_fill_keys($freqs, ['agendados' => 0, 'cobrados' => 0, 'monto_estimado' => 0.0, 'monto_cobrado' => 0.0]);
    return ['agendados' => 0, 'cobrados' => 0, 'monto_estimado' => 0.0, 'monto_cobrado' => 0.0,
            'efectivo' => 0.0, 'transferencia' => 0.0, 'mora' => 0.0, 'por_tipo' => $pt];
}

$data = [];
foreach ($cobradores as $cob) {
    $dias = [];
    foreach (array_keys($dias_semana) as $fecha) {
        $dias[$fecha] = _dia_vacio($frecuencias);
    }
    $data[$cob['id']] = ['nombre' => $cob['nombre'], 'apellido' => $cob['apellido'],
                         'dias' => $dias, 'totales' => _dia_vacio($frecuencias)];
}

foreach ($agenda_raw as $row) {
    $cid = (int) $row['cobrador_id'];
    $fecha = $row['fecha_vencimiento'];
    $freq  = $row['frecuencia'];
    if (!isset($data[$cid]['dias'][$fecha])) continue;
    $ag = (int) $row['cuotas_agendadas'];
    $me = (float) $row['monto_estimado'];
    $data[$cid]['dias'][$fecha]['agendados']                          += $ag;
    $data[$cid]['dias'][$fecha]['monto_estimado']                     += $me;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['agendados']       += $ag;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['monto_estimado']  += $me;
    $data[$cid]['totales']['agendados']                               += $ag;
    $data[$cid]['totales']['monto_estimado']                          += $me;
    $data[$cid]['totales']['por_tipo'][$freq]['agendados']            += $ag;
    $data[$cid]['totales']['por_tipo'][$freq]['monto_estimado']       += $me;
}

foreach ($cobros_raw as $row) {
    $cid  = (int) $row['cobrador_id'];
    $fecha = $row['fecha_jornada'];
    $freq  = $row['frecuencia'];
    if (!isset($data[$cid]['dias'][$fecha])) continue;
    $co  = (int)   $row['cuotas_cobradas'];
    $mc  = (float) $row['monto_cobrado'];
    $ef  = (float) $row['efectivo'];
    $tr  = (float) $row['transferencia'];
    $mor = (float) $row['mora_cobrada'];
    $data[$cid]['dias'][$fecha]['cobrados']                           += $co;
    $data[$cid]['dias'][$fecha]['monto_cobrado']                      += $mc;
    $data[$cid]['dias'][$fecha]['efectivo']                           += $ef;
    $data[$cid]['dias'][$fecha]['transferencia']                      += $tr;
    $data[$cid]['dias'][$fecha]['mora']                               += $mor;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['cobrados']        += $co;
    $data[$cid]['dias'][$fecha]['por_tipo'][$freq]['monto_cobrado']   += $mc;
    $data[$cid]['totales']['cobrados']                                += $co;
    $data[$cid]['totales']['monto_cobrado']                           += $mc;
    $data[$cid]['totales']['efectivo']                                += $ef;
    $data[$cid]['totales']['transferencia']                           += $tr;
    $data[$cid]['totales']['mora']                                    += $mor;
    $data[$cid]['totales']['por_tipo'][$freq]['cobrados']             += $co;
    $data[$cid]['totales']['por_tipo'][$freq]['monto_cobrado']        += $mc;
}

// ── Helpers ───────────────────────────────────────────────────
function lat(string $s): string
{
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string
{
    return '$ ' . number_format($v, 0, ',', '.');
}
function pct_color(int $pct): array // [r, g, b]
{
    if ($pct >= 80) return [40, 167, 69];
    if ($pct >= 50) return [204, 140, 0];
    return [200, 50, 50];
}

// ── FPDF ──────────────────────────────────────────────────────
require_once __DIR__ . '/../fpdf/fpdf.php';

class EstadisticasPDF extends FPDF
{
    public string $cobrador    = '';
    public string $semana_lbl  = '';
    public string $gen_fecha   = '';

    function Header(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);

        // Empresa
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetXY(10, 8);
        $this->Cell(190, 7, lat('Imperio Comercial'), 0, 1, 'C');

        // Subtítulo
        $this->SetFont('Helvetica', '', 9);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Estadisticas de Cobranza - Semana: ' . $this->semana_lbl), 0, 1, 'C');

        // Cobrador
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetX(10);
        $this->Cell(130, 6, lat('Cobrador: ' . $this->cobrador), 0, 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(60, 6, lat('Generado: ' . $this->gen_fecha), 0, 1, 'R');

        // Línea divisora
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(4);
        $this->SetLineWidth(0.2);
    }

    function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' de {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Celda con barra de progreso de fondo + texto encima.
     */
    function CellProgreso(float $w, float $h, int $pct, string $texto): void
    {
        $x = $this->GetX();
        $y = $this->GetY();

        // Fondo de progreso (color suave)
        if ($pct > 0) {
            [$r, $g, $b] = pct_color($pct);
            // Usar versión más clara del color de fondo
            $this->SetFillColor(
                min(255, $r + (255 - $r) * 70 / 100),
                min(255, $g + (255 - $g) * 70 / 100),
                min(255, $b + (255 - $b) * 70 / 100)
            );
            $fill_w = $w * min($pct, 100) / 100;
            $this->Rect($x, $y, $fill_w, $h, 'F');
        }

        // Borde
        $this->SetDrawColor(0, 0, 0);
        $this->Rect($x, $y, $w, $h, 'D');

        // Texto de porcentaje (bold, color del progreso)
        if ($pct > 0) {
            [$r, $g, $b] = pct_color($pct);
            $this->SetTextColor($r, $g, $b);
        } else {
            $this->SetTextColor(150, 150, 150);
        }
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetXY($x, $y);
        $this->Cell($w, $h, $texto, 0, 0, 'C', false);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x + $w, $y);
    }

    /** Título de sección */
    function TituloSeccion(string $titulo): void
    {
        $this->Ln(3);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetX(10);
        $this->Cell(190, 6, lat($titulo), 0, 1, 'L');
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetLineWidth(0.2);
    }

    /** Encabezado de tabla */
    function CabecerTabla(array $cols, array $labels, array $aligns): void
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(230, 230, 230);
        $this->SetX(10);
        foreach ($cols as $i => $w) {
            $this->Cell($w, 6, lat($labels[$i]), 1, 0, $aligns[$i], true);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 7);
        $this->SetFillColor(255, 255, 255);
    }
}

// ── Generar PDF ───────────────────────────────────────────────
$pdf = new EstadisticasPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->semana_lbl = $lunes_sel->format('d/m/Y') . ' al ' . $sabado_sel->format('d/m/Y');
$pdf->gen_fecha  = date('d/m/Y H:i');

// Anchos de columnas (suma = 190mm)
// Tabla 1 — Efectividad diaria
$c1 = [28, 22, 22, 30, 44, 44]; // Dia|Agenda|Cobradas|Efectiv.|Estimado|Cobrado
$l1 = ['Dia', 'Agendadas', 'Cobradas', 'Efectividad', 'Est. a Cobrar', 'Real Cobrado'];
$a1 = ['L', 'C', 'C', 'C', 'R', 'R'];

// Tabla 2 — Detalle financiero diario
$c2 = [28, 41, 41, 40, 40]; // Dia|Efectivo|Transf.|Mora|Total
$l2 = ['Dia', 'Efectivo', 'Transferencia', 'Mora', 'Total Cobrado'];
$a2 = ['L', 'R', 'R', 'R', 'R'];

// Tabla 3 — Desglose por tipo
$c3 = [35, 22, 22, 30, 41, 40]; // Tipo|Agenda|Cobradas|Efectiv.|Estimado|Cobrado
$l3 = ['Tipo de Cuota', 'Agendadas', 'Cobradas', 'Efectividad', 'Est. a Cobrar', 'Real Cobrado'];
$a3 = ['L', 'C', 'C', 'C', 'R', 'R'];

$cobradores_con_datos = array_filter($data, fn($d) => $d['totales']['cobrados'] > 0 || $d['totales']['agendados'] > 0);

if (empty($cobradores_con_datos)) {
    // Página única indicando que no hay datos
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetXY(10, 80);
    $pdf->Cell(190, 10, lat('No hay datos de cobranza para la semana seleccionada.'), 0, 1, 'C');
    $pdf->Output('I', 'estadisticas_cobranza.pdf');
    exit;
}

foreach ($cobradores_con_datos as $cob_id => $cob) {
    $tot = $cob['totales'];
    $pdf->cobrador = $cob['apellido'] . ', ' . $cob['nombre'];
    $pdf->AddPage();

    // ── TABLA 1: Efectividad de visitas diaria ────────────────
    $pdf->TituloSeccion('1. Efectividad de Visitas Diaria');
    $pdf->CabecerTabla($c1, $l1, $a1);
    $pdf->SetX(10);

    foreach ($dias_semana as $fecha => $dow_num) {
        $d   = $cob['dias'][$fecha];
        $pct = $d['agendados'] > 0 ? (int) round($d['cobrados'] / $d['agendados'] * 100) : 0;
        $pdf->SetX(10);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->Cell($c1[0], 6, lat($nombres_dia[$dow_num] . ' ' . date('d/m', strtotime($fecha))), 1, 0, 'L', false);
        $pdf->Cell($c1[1], 6, $d['agendados'] ?: '-', 1, 0, 'C', false);
        $pdf->Cell($c1[2], 6, $d['cobrados']  ?: '-', 1, 0, 'C', false);
        // Celda con progreso visual
        if ($d['agendados'] > 0) {
            $pdf->CellProgreso($c1[3], 6, $pct, $pct . '%');
        } else {
            $pdf->Cell($c1[3], 6, '-', 1, 0, 'C', false);
        }
        $pdf->Cell($c1[4], 6, $d['monto_estimado'] > 0 ? fmt($d['monto_estimado']) : '-', 1, 0, 'R', false);
        $pdf->Cell($c1[5], 6, $d['monto_cobrado']  > 0 ? fmt($d['monto_cobrado'])  : '-', 1, 0, 'R', false);
        $pdf->Ln();
    }

    // Fila totales tabla 1
    $pct_tot = $tot['agendados'] > 0 ? (int) round($tot['cobrados'] / $tot['agendados'] * 100) : 0;
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetX(10);
    $pdf->Cell($c1[0], 7, lat('TOTALES'), 1, 0, 'L', false);
    $pdf->Cell($c1[1], 7, $tot['agendados'], 1, 0, 'C', false);
    $pdf->Cell($c1[2], 7, $tot['cobrados'],  1, 0, 'C', false);
    $pdf->CellProgreso($c1[3], 7, $pct_tot, $pct_tot . '%');
    $pdf->Cell($c1[4], 7, fmt($tot['monto_estimado']), 1, 0, 'R', false);
    $pdf->Cell($c1[5], 7, fmt($tot['monto_cobrado']),  1, 0, 'R', false);
    $pdf->Ln();

    // ── TABLA 2: Detalle financiero diario ───────────────────
    $pdf->TituloSeccion('2. Detalle Financiero Diario');
    $pdf->CabecerTabla($c2, $l2, $a2);

    $tf_ef = 0.0; $tf_tr = 0.0; $tf_mor = 0.0; $tf_tot = 0.0;
    foreach ($dias_semana as $fecha => $dow_num) {
        $d = $cob['dias'][$fecha];
        $tiene = ($d['efectivo'] + $d['transferencia'] + $d['mora']) > 0;
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetX(10);
        $pdf->Cell($c2[0], 6, lat($nombres_dia[$dow_num] . ' ' . date('d/m', strtotime($fecha))), 1, 0, 'L', false);
        $pdf->Cell($c2[1], 6, $d['efectivo']      > 0 ? fmt($d['efectivo'])      : '-', 1, 0, 'R', false);
        $pdf->Cell($c2[2], 6, $d['transferencia'] > 0 ? fmt($d['transferencia']) : '-', 1, 0, 'R', false);
        $pdf->Cell($c2[3], 6, $d['mora']          > 0 ? fmt($d['mora'])          : '-', 1, 0, 'R', false);
        $pdf->Cell($c2[4], 6, $d['monto_cobrado'] > 0 ? fmt($d['monto_cobrado']) : '-', 1, 0, 'R', false);
        $pdf->Ln();
        $tf_ef  += $d['efectivo'];
        $tf_tr  += $d['transferencia'];
        $tf_mor += $d['mora'];
        $tf_tot += $d['monto_cobrado'];
    }

    // Fila totales tabla 2
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetX(10);
    $pdf->Cell($c2[0], 7, lat('TOTALES'), 1, 0, 'L', false);
    $pdf->Cell($c2[1], 7, fmt($tf_ef),  1, 0, 'R', false);
    $pdf->Cell($c2[2], 7, fmt($tf_tr),  1, 0, 'R', false);
    $pdf->Cell($c2[3], 7, fmt($tf_mor), 1, 0, 'R', false);
    $pdf->Cell($c2[4], 7, fmt($tf_tot), 1, 0, 'R', false);
    $pdf->Ln();

    // ── TABLA 3: Desglose por tipo de cuota ──────────────────
    $pdf->TituloSeccion('3. Desglose por Tipo de Cuota');
    $pdf->CabecerTabla($c3, $l3, $a3);

    $etiquetas_tipo = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];
    foreach ($frecuencias as $freq) {
        $tf   = $tot['por_tipo'][$freq];
        $pctf = $tf['agendados'] > 0 ? (int) round($tf['cobrados'] / $tf['agendados'] * 100) : 0;
        $vacia = ($tf['agendados'] === 0 && $tf['cobrados'] === 0);
        $pdf->SetFont('Helvetica', $vacia ? 'I' : '', 7);
        $pdf->SetX(10);
        $pdf->Cell($c3[0], 6, lat($etiquetas_tipo[$freq]), 1, 0, 'L', false);
        $pdf->Cell($c3[1], 6, $tf['agendados'] ?: '-', 1, 0, 'C', false);
        $pdf->Cell($c3[2], 6, $tf['cobrados']  ?: '-', 1, 0, 'C', false);
        if ($tf['agendados'] > 0) {
            $pdf->CellProgreso($c3[3], 6, $pctf, $pctf . '%');
        } else {
            $pdf->Cell($c3[3], 6, '-', 1, 0, 'C', false);
        }
        $pdf->Cell($c3[4], 6, $tf['monto_estimado'] > 0 ? fmt($tf['monto_estimado']) : '-', 1, 0, 'R', false);
        $pdf->Cell($c3[5], 6, $tf['monto_cobrado']  > 0 ? fmt($tf['monto_cobrado'])  : '-', 1, 0, 'R', false);
        $pdf->Ln();
    }

    // ── CAJA RESUMEN ─────────────────────────────────────────
    $pdf->Ln(4);
    $bx  = 110; // columna derecha
    $bw1 = 55;
    $bw2 = 35;

    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetX($bx);
    $pdf->Cell($bw1 + $bw2, 6, lat('Resumen de la Semana'), 1, 1, 'C', false);

    $resumen = [
        ['Cuotas agendadas',  $tot['agendados']],
        ['Cuotas cobradas',   $tot['cobrados']],
        ['Efectividad',       ($pct_tot . '%')],
        ['Total Estimado',    fmt($tot['monto_estimado'])],
        ['Total Cobrado',     fmt($tot['monto_cobrado'])],
        ['  Efectivo',        fmt($tot['efectivo'])],
        ['  Transferencia',   fmt($tot['transferencia'])],
        ['  Mora cobrada',    fmt($tot['mora'])],
    ];

    foreach ($resumen as $i => [$label, $valor]) {
        $is_total = ($label === 'Total Cobrado');
        $pdf->SetFont('Helvetica', $is_total ? 'B' : '', 8);
        $pdf->SetX($bx);
        $pdf->Cell($bw1, 6, lat($label), 1, 0, 'L', false);
        if ($is_total) {
            $pdf->SetTextColor(40, 100, 40);
        }
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell($bw2, 6, lat((string) $valor), 1, 1, 'R', false);
        $pdf->SetTextColor(0, 0, 0);
    }
}

$semana_clean = str_replace('-', '', $inicio_str);
$pdf->Output('I', "estadisticas_cobranza_{$semana_clean}.pdf");
