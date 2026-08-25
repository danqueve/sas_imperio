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

// ── Monto cobrado (mismo criterio que estadisticas_zona.php) ────
$stmtCobrado = $pdo->prepare("
    SELECT COUNT(DISTINCT cl.id) AS clientes,
           COUNT(pc.id) AS cuotas_cobradas,
           COALESCE(SUM(pc.monto_total), 0) AS cobrado
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu   ON cu.id = pc.cuota_id
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE pc.cobrador_id = ? AND pc.fecha_jornada BETWEEN ? AND ?
      AND pc.origen = 'cobrador'
      AND COALESCE(NULLIF(TRIM(cl.zona), ''), 'Sin zona') = ?
");
$stmtCobrado->execute([$cobrador_id, $desde, $hasta, $zona]);
$rowC = $stmtCobrado->fetch(PDO::FETCH_ASSOC) ?: ['clientes' => 0, 'cuotas_cobradas' => 0, 'cobrado' => 0];
$clientes        = (int)$rowC['clientes'];
$cuotas_cobradas = (int)$rowC['cuotas_cobradas'];
$cobrado         = (float)$rowC['cobrado'];

// ── Estimado/Faltante (mismo criterio que estadisticas_zona.php) ──
$stmtEst = $pdo->prepare("
    SELECT cu.id, cu.estado, cu.monto_cuota, cu.monto_mora, cu.saldo_pagado,
           cu.fecha_vencimiento, cr.interes_moratorio_pct,
           EXISTS(
               SELECT 1 FROM ic_pagos_temporales pt2
               WHERE pt2.cuota_id = cu.id AND pt2.estado IN ('PENDIENTE','APROBADO')
                 AND pt2.origen = 'cobrador'
           ) AS tiene_pago
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
      AND cu.estado != 'CANCELADA'
      AND cu.fecha_vencimiento BETWEEN ? AND ?
      AND COALESCE(NULLIF(TRIM(cl.zona), ''), 'Sin zona') = ?
");
$stmtEst->execute([$cobrador_id, $desde, $hasta, $zona]);

$estimado = 0.0;
$faltante = 0.0;
foreach ($stmtEst->fetchAll(PDO::FETCH_ASSOC) as $cu) {
    $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
    $mora = (float)$cu['monto_mora'] > 0
        ? (float)$cu['monto_mora']
        : calcular_mora((float)$cu['monto_cuota'], $dias_atraso, (float)$cu['interes_moratorio_pct']);

    $estimado += (float)$cu['monto_cuota'] + $mora;

    $cobrado_cuota = ((float)$cu['saldo_pagado'] > 0)
        || in_array($cu['estado'], ['PAGADA', 'CAP_PAGADA'], true)
        || (bool)$cu['tiene_pago'];

    if (!$cobrado_cuota) {
        $faltante += max(0, (float)$cu['monto_cuota'] + $mora - (float)$cu['saldo_pagado']);
    }
}
// Cobrado (del período) y % Éxito se derivan de Estimado/Faltante (misma
// fuente, no de la caja real) para que siempre reconcilien matemáticamente:
// Cobrado_periodo + Faltante = Estimado, y el % nunca supera el 100%.
// "Cobrado" (caja real del período, puede incluir deuda de otros períodos)
// se muestra aparte.
$cobrado_periodo = max(0, $estimado - $faltante);
$pct_exito = $estimado > 0 ? round($cobrado_periodo / $estimado * 100) : null;

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
