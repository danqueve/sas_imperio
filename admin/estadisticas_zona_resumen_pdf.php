<?php
// ============================================================
// admin/estadisticas_zona_resumen_pdf.php — Resumen PDF de una
// zona puntual del cobrador (mismos criterios que estadisticas_zona.php)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);
$desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
    ? $_GET['desde'] : date('Y-m-01');
$hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
    ? $_GET['hasta'] : date('Y-m-d');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$zona = trim($_GET['zona'] ?? '');

if (!$cobrador_id || $zona === '') {
    die('Faltan parametros (cobrador y zona).');
}

$cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cs->execute([$cobrador_id]);
$cob = $cs->fetch();
if (!$cob) die('Cobrador no encontrado.');
$cob_label = $cob['apellido'] . ', ' . $cob['nombre'];

// Misma fuente que admin/estadisticas_zona.php (obtener_estadisticas_zona()
// en config/funciones.php) — se pide todo el cobrador y se toma solo la
// zona pedida, para que pantalla y PDF nunca puedan divergir en la regla
// de mora/PARCIAL.
$zonas_cob = obtener_estadisticas_zona($pdo, $cobrador_id, $desde, $hasta);
$dz = $zonas_cob[$zona] ?? ['clientes' => 0, 'cuotas_cobradas' => 0, 'cobrado' => 0.0,
                            'estimado' => 0.0, 'faltante' => 0.0, 'cobrado_periodo' => 0.0, 'pct_periodo' => null];
$clientes         = $dz['clientes'];
$cuotas_cobradas  = $dz['cuotas_cobradas'];
$cobrado          = $dz['cobrado'];
$estimado         = $dz['estimado'];
$faltante         = $dz['faltante'];
$cobrado_periodo  = $dz['cobrado_periodo'];
$pct_exito        = $dz['pct_periodo'];

// ── PDF ──────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

$pdf = new PDFBase('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell(190, 7, lat('Imperio Comercial - Resumen de Cobranza por Zona'), 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(190, 6, lat('Cobrador: ' . $cob_label . '   |   Zona: ' . $zona), 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(190, 5, lat('Periodo: ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)) . '   |   Emision: ' . date('d/m/Y H:i')), 0, 1, 'C');
$pdf->Ln(4);

// ── Fila de KPIs coloreados (plantilla riesgo_cartera_pdf.php) ──
$pct_color = $pct_exito === null ? [148, 163, 184] : ($pct_exito >= 80 ? [34, 197, 94] : ($pct_exito >= 50 ? [245, 158, 11] : [239, 68, 68]));
$gris_caja = [140, 145, 155];
$kpis = [
    ['Clientes',              (string)$clientes,        [96, 102, 112]],
    ['Cuotas Cobradas',       (string)$cuotas_cobradas, [96, 102, 112]],
    ['Estimado',              fmt($estimado),            [96, 102, 112]],
    ['Cobrado (del periodo)', fmt($cobrado_periodo),     [34, 197, 94]],
    ['Faltante',               fmt($faltante),            [239, 68, 68]],
    ['% Exito',               $pct_exito === null ? '-' : $pct_exito . '%', $pct_color],
    ['Caja Total',            fmt($cobrado),             $gris_caja],
];
$kpi_w = 190 / count($kpis);
$kpi_y0 = $pdf->GetY();
foreach ($kpis as $i => [$label, $val, $rgb]) {
    $x = 10 + $i * $kpi_w;
    $pdf->SetXY($x, $kpi_y0);
    $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    $pdf->Cell($kpi_w, 16, '', 1, 0, 'C', true);

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetXY($x, $kpi_y0 + 3);
    $pdf->Cell($kpi_w, 4, lat($label), 0, 0, 'C');

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetXY($x, $kpi_y0 + 8);
    $pdf->Cell($kpi_w, 5, lat($val), 0, 0, 'C');
}
$pdf->SetTextColor(0, 0, 0);
$pdf->SetXY(10, $kpi_y0 + 20);

$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->MultiCell(190, 4, lat(
    'Estimado = cuota nominal + mora de las cuotas con vencimiento dentro del periodo elegido. ' .
    'Cobrado (del periodo) y Faltante se calculan sobre ese mismo Estimado y siempre suman exacto entre si ' .
    '(el % Exito nunca supera el 100%). Caja Total es la plata real que entro en el periodo (solo pagos ' .
    'cargados por el cobrador) y puede no coincidir con "Cobrado (del periodo)": puede incluir cobros de ' .
    'cuotas vencidas en otros periodos, o no incluir cuotas de este periodo pagadas manualmente por un ' .
    'admin/supervisor.'
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$zona_slug = preg_replace('/\W+/', '', $zona) ?: 'zona';
$nombre = 'zona_resumen_cob' . $cobrador_id . '_' . $zona_slug . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
