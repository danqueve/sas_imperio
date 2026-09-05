<?php
// ============================================================
// vendedores/estadisticas_ranking_pdf.php — PDF de la tabla
// "Rendimiento por Vendedor" de vendedores/estadisticas.php
// (Vendedor + Monto Vendido, filtrable por período y zona)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Mismo parseo de período que estadisticas.php ─────────────
$preset  = $_GET['periodo'] ?? 'historico';
$validos = ['mes_actual', 'mes_ant', 'trim', 'sem', 'anio', 'historico', 'custom'];
if (!in_array($preset, $validos)) $preset = 'historico';

$hoy = date('Y-m-d');
switch ($preset) {
    case 'mes_actual':
        $f_desde = date('Y-m-01'); $f_hasta = $hoy; break;
    case 'mes_ant':
        $f_desde = date('Y-m-01', strtotime('first day of last month'));
        $f_hasta = date('Y-m-t',  strtotime('last month')); break;
    case 'trim':
        $f_desde = date('Y-m-d', strtotime('-3 months')); $f_hasta = $hoy; break;
    case 'sem':
        $f_desde = date('Y-m-d', strtotime('-6 months')); $f_hasta = $hoy; break;
    case 'anio':
        $f_desde = date('Y-01-01'); $f_hasta = $hoy; break;
    case 'custom':
        $f_desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-01-01');
        $f_hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy; break;
    default:
        $f_desde = null; $f_hasta = null; break;
}
$tiene_filtro = ($f_desde && $f_hasta);

$presets_labels = [
    'mes_actual' => 'Mes actual', 'mes_ant' => 'Mes anterior', 'trim' => 'Últimos 3 meses',
    'sem' => 'Últimos 6 meses', 'anio' => date('Y') . ' (año actual)',
    'historico' => 'Histórico completo', 'custom' => 'Personalizado',
];
$label_periodo = $presets_labels[$preset] ?? 'Histórico completo';

$zonas   = array_values(array_filter(array_map('trim', (array) ($_GET['zonas'] ?? []))));
$zona_on = '';
if (!empty($zonas)) {
    $zona_on = "AND cr.cliente_id IN (SELECT id FROM ic_clientes WHERE zona IN (" .
        implode(',', array_fill(0, count($zonas), '?')) . "))";
}

// ── Subquery de aging (mismo criterio que estadisticas.php, no se usa acá
// mas que para reutilizar la misma forma de query) — no aplica, se omite ──

// ── Ranking de vendedores (mismo criterio que $rank_sql de estadisticas.php) ──
$params_ranking = $tiene_filtro ? [$f_desde, $f_hasta] : [];
$params_ranking = array_merge($params_ranking, $zonas);

// credito_origen_id no nulo = resultado de una refinanciacion, no una venta
// nueva (misma deuda reestructurada) -- se excluye solo de "vendido".
$rank_sql = "
    SELECT
        v.nombre, v.apellido,
        COUNT(cr.id)                               AS total_creditos,
        COUNT(DISTINCT cr.cliente_id)               AS total_clientes,
        COALESCE(SUM(CASE WHEN cr.credito_origen_id IS NULL THEN cr.monto_total ELSE 0 END), 0) AS monto_vendido,
        COALESCE(SUM(COALESCE(pag.cobrado, 0)), 0)  AS total_cobrado
    FROM ic_vendedores v
    LEFT JOIN ic_creditos cr
        ON cr.vendedor_id = v.id " . ($tiene_filtro ? "AND cr.fecha_alta BETWEEN ? AND ?" : "") . " $zona_on
    LEFT JOIN (
        SELECT cu.credito_id, SUM(pc.monto_total) AS cobrado
        FROM ic_pagos_confirmados pc
        JOIN ic_cuotas cu ON pc.cuota_id = cu.id
        GROUP BY cu.credito_id
    ) pag ON pag.credito_id = cr.id
    GROUP BY v.id, v.nombre, v.apellido
";
$rank_stmt = $pdo->prepare($rank_sql);
$rank_stmt->execute($params_ranking);
$filas_raw = $rank_stmt->fetchAll();

// Créditos sin vendedor asignado (mismo criterio que $sv_sql de estadisticas.php)
$sv_where = $tiene_filtro ? "AND cr.fecha_alta BETWEEN ? AND ?" : "";
$sv_sql = "
    SELECT
        COUNT(cr.id)                               AS total_creditos,
        COUNT(DISTINCT cr.cliente_id)               AS total_clientes,
        COALESCE(SUM(CASE WHEN cr.credito_origen_id IS NULL THEN cr.monto_total ELSE 0 END), 0) AS monto_vendido,
        COALESCE(SUM(COALESCE(pag.cobrado, 0)), 0)  AS total_cobrado
    FROM ic_creditos cr
    LEFT JOIN (
        SELECT cu.credito_id, SUM(pc.monto_total) AS cobrado
        FROM ic_pagos_confirmados pc
        JOIN ic_cuotas cu ON pc.cuota_id = cu.id
        GROUP BY cu.credito_id
    ) pag ON pag.credito_id = cr.id
    WHERE cr.vendedor_id IS NULL $sv_where $zona_on
";
$sv_stmt = $pdo->prepare($sv_sql);
$sv_stmt->execute(array_merge($tiene_filtro ? [$f_desde, $f_hasta] : [], $zonas));
$sin_vendedor = $sv_stmt->fetch();
if ($sin_vendedor && (float) $sin_vendedor['monto_vendido'] > 0) {
    $sin_vendedor['vendedor_label'] = 'Sin vendedor asignado';
    $filas_raw[] = $sin_vendedor;
}

// Solo vendedores con ventas en el período+zona, de mayor a menor vendido
// (mismo criterio que estadisticas_objetivos_pdf.php — evita paginas de ceros)
$filas = [];
foreach ($filas_raw as $v) {
    if ((float) $v['monto_vendido'] <= 0) continue;
    $filas[] = [
        'vendedor'       => $v['vendedor_label'] ?? ($v['apellido'] . ', ' . $v['nombre']),
        'total_creditos' => (int) $v['total_creditos'],
        'total_clientes' => (int) $v['total_clientes'],
        'vendido'        => (float) $v['monto_vendido'],
        'cobrado'        => (float) $v['total_cobrado'],
    ];
}
usort($filas, fn($a, $b) => $b['vendido'] <=> $a['vendido']);

if (empty($filas)) {
    die('No hay ventas para el período y la zona seleccionados.');
}

$total_creditos = array_sum(array_column($filas, 'total_creditos'));
$total_clientes = array_sum(array_column($filas, 'total_clientes'));
$total_vendido  = array_sum(array_column($filas, 'vendido'));
$total_cobrado  = array_sum(array_column($filas, 'cobrado'));
$pct_cobrado_total = $total_vendido > 0 ? round($total_cobrado / $total_vendido * 100) : 0;

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: #(8) + Vendedor(45) + Creditos(20) + Clientes(20) + Vendido(32) + Cobrado(32) + %Cobrado(33) = 190
$COLS   = [8, 45, 20, 20, 32, 32, 33];
$LABELS = ['#', 'Vendedor', 'Creditos', 'Clientes', 'Vendido', 'Cobrado', '% Cobrado'];
$ALIGNS = ['C', 'L', 'C', 'C', 'R', 'R', 'R'];

class RankingVentaPDF extends PDFBase
{
    public string $periodo_lbl = '';
    public string $zona_lbl    = '';
    public array  $cols        = [];
    public array  $labels      = [];
    public array  $aligns      = [];

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
        $this->Cell(190, 5, lat('Rendimiento por Vendedor'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Periodo: ' . $this->periodo_lbl . '   |   Zona: ' . $this->zona_lbl . '   |   Emision: ' . date('d/m/Y H:i')), 0, 1, 'C');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(230, 230, 245);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new RankingVentaPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->periodo_lbl = $tiene_filtro
    ? $label_periodo . ' (' . date('d/m/Y', strtotime($f_desde)) . ' - ' . date('d/m/Y', strtotime($f_hasta)) . ')'
    : $label_periodo;
$pdf->zona_lbl = !empty($zonas) ? implode(', ', $zonas) : 'Todas';
$pdf->cols     = $COLS;
$pdf->labels   = $LABELS;
$pdf->aligns   = $ALIGNS;
$pdf->AddPage();

$index = 1;
foreach ($filas as $f) {
    $pct_cobr  = $f['vendido'] > 0 ? round($f['cobrado'] / $f['vendido'] * 100) : 0;
    $color_pct = $pct_cobr >= 70 ? [16, 185, 129] : ($pct_cobr >= 40 ? [245, 158, 11] : [211, 64, 83]);

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, (string) $index, 1, 0, 'C');
    $pdf->Cell($COLS[1], 5.5, $pdf->fitText($f['vendedor'], $COLS[1] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[2], 5.5, (string) $f['total_creditos'], 1, 0, 'C');
    $pdf->Cell($COLS[3], 5.5, (string) $f['total_clientes'], 1, 0, 'C');
    $pdf->Cell($COLS[4], 5.5, lat(fmt($f['vendido'])), 1, 0, 'R');
    $pdf->Cell($COLS[5], 5.5, lat(fmt($f['cobrado'])), 1, 0, 'R');
    $pdf->SetTextColor($color_pct[0], $color_pct[1], $color_pct[2]);
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($COLS[6], 5.5, $pct_cobr . '%', 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln();

    $index++;
}

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetX(10);
$pdf->Cell($COLS[0] + $COLS[1], 6, lat('TOTALES (' . count($filas) . ' vendedores)'), 1, 0, 'R');
$pdf->Cell($COLS[2], 6, (string) $total_creditos, 1, 0, 'C');
$pdf->Cell($COLS[3], 6, (string) $total_clientes, 1, 0, 'C');
$pdf->Cell($COLS[4], 6, lat(fmt($total_vendido)), 1, 0, 'R');
$pdf->Cell($COLS[5], 6, lat(fmt($total_cobrado)), 1, 0, 'R');
$pdf->Cell($COLS[6], 6, $pct_cobrado_total . '%', 1, 0, 'R');
$pdf->Ln();

$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell(190, 4, lat(
    'Vendido = suma de monto_total de creditos con fecha de alta en el periodo elegido, del vendedor ' .
    'correspondiente, cuyo cliente esta en alguna de las zonas seleccionadas (si se eligio mas de una, ' .
    'se suman todas juntas). No incluye creditos generados por una refinanciacion (no son una venta ' .
    'nueva, es la misma deuda reestructurada). Cobrado = pagos confirmados de esos mismos creditos. ' .
    'Solo se listan vendedores con ventas en el periodo y zonas elegidos.'
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$nombre = 'ranking_ventas_' . ($f_desde ?? 'historico') .
    (!empty($zonas) ? '_' . preg_replace('/[^A-Za-z0-9]+/', '_', implode('_', $zonas)) : '') . '.pdf';
$pdf->Output('I', $nombre);
