<?php
// ============================================================
// admin/riesgo_cartera_pdf.php — PDF Reporte de Riesgo de Cartera
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros (mismos que la vista) ──────────────────────────
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$riesgo_sel  = (int) ($_GET['riesgo'] ?? 0);

$where  = ["cr.estado IN ('EN_CURSO','MOROSO')"];
$params = [];

if ($cobrador_id > 0) {
    $where[]  = 'cr.cobrador_id = ?';
    $params[] = $cobrador_id;
}
$whereStr = implode(' AND ', $where);

// ── Query ──────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        cr.id AS credito_id,
        cl.apellidos, cl.nombres, cl.zona,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        CONCAT(u.nombre, ' ', u.apellido)              AS cobrador,
        COALESCE(CONCAT(v.nombre, ' ', v.apellido), '—') AS vendedor,
        COALESCE(cr.veces_refinanciado, 0)              AS refinanciado,
        COUNT(CASE WHEN cu.estado IN ('VENCIDA','PARCIAL') AND cu.fecha_vencimiento < CURDATE() THEN 1 END) AS cuotas_vencidas,
        COALESCE(AVG(CASE WHEN cu.fecha_vencimiento < CURDATE() AND cu.estado IN ('VENCIDA','PARCIAL')
                          THEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) END), 0) AS avg_atraso,
        COALESCE(MAX(CASE WHEN cu.fecha_vencimiento < CURDATE() AND cu.estado IN ('VENCIDA','PARCIAL')
                          THEN DATEDIFF(CURDATE(), cu.fecha_vencimiento) END), 0) AS max_dias,
        COUNT(CASE WHEN cu.monto_mora > 0
                    AND cu.estado NOT IN ('PAGADA','CAP_PAGADA','CANCELADA') THEN 1 END) AS con_mora,
        SUM(CASE WHEN cu.estado != 'PAGADA' THEN cu.monto_cuota - cu.saldo_pagado ELSE 0 END) AS saldo_pendiente
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_cuotas cu   ON cu.credito_id = cr.id
    JOIN ic_usuarios u  ON cr.cobrador_id = u.id
    LEFT JOIN ic_vendedores v ON cr.vendedor_id = v.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE $whereStr
    GROUP BY cr.id, cl.apellidos, cl.nombres, cl.zona, cr.articulo_desc, a.descripcion,
             u.nombre, u.apellido, v.nombre, v.apellido, cr.veces_refinanciado
    ORDER BY saldo_pendiente DESC, cl.apellidos ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Calcular riesgo + conteos ──────────────────────────────
$conteo_riesgo = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
$saldo_riesgo  = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];

foreach ($rows as &$r) {
    $r['riesgo'] = calcular_nivel_riesgo(
        (int)$r['cuotas_vencidas'], (float)$r['avg_atraso'],
        (int)$r['con_mora'],        (int)$r['refinanciado']
    );
    $conteo_riesgo[$r['riesgo']]++;
    $saldo_riesgo[$r['riesgo']] += (float)$r['saldo_pendiente'];
}
unset($r);

if ($riesgo_sel > 0) {
    $rows = array_values(array_filter($rows, fn($r) => $r['riesgo'] === $riesgo_sel));
}

// ── Cobrador seleccionado (para encabezado) ────────────────
$label_cobrador = 'Todos los cobradores';
if ($cobrador_id > 0) {
    $s = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id=?");
    $s->execute([$cobrador_id]);
    $cv = $s->fetch();
    if ($cv) $label_cobrador = $cv['nombre'] . ' ' . $cv['apellido'];
}

$niveles_label = [1 => 'Bajo', 2 => 'Moderado', 3 => 'Alto', 4 => 'Critico'];
$label_riesgo  = $riesgo_sel > 0 ? 'Nivel: ' . $niveles_label[$riesgo_sel] : 'Todos los niveles';

// ── PDF ────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

class RiesgoCarteraPDF extends PDFBase
{
    public string $cobrador_lbl = '';
    public string $riesgo_lbl  = '';
    public string $gen_fecha   = '';

    function Header(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 6, lat('Imperio Comercial'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Reporte de Riesgo de Cartera'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_lbl), 0, 0, 'L');
        $this->Cell(95, 5, lat('Nivel: ' . $this->riesgo_lbl), 0, 1, 'L');

        $this->SetX(10);
        $this->Cell(130, 5, lat('Generado: ' . $this->gen_fecha), 0, 1, 'L');

        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);
    }
}

$pdf = new RiesgoCarteraPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->cobrador_lbl = $label_cobrador;
$pdf->riesgo_lbl   = $label_riesgo;
$pdf->gen_fecha    = date('d/m/Y H:i');
$pdf->AddPage();

// ── Resumen KPI (solo el nivel seleccionado si hay filtro) ─
$niveles_meta = [
    1 => ['label' => 'Bajo',     'r' => 34,  'g' => 197, 'b' => 94],
    2 => ['label' => 'Moderado', 'r' => 14,  'g' => 165, 'b' => 233],
    3 => ['label' => 'Alto',     'r' => 249, 'g' => 115, 'b' => 22],
    4 => ['label' => 'Critico',  'r' => 239, 'g' => 68,  'b' => 68],
];

$niveles_a_mostrar = $riesgo_sel > 0 ? [$riesgo_sel => $niveles_meta[$riesgo_sel]] : $niveles_meta;
$n_kpi  = count($niveles_a_mostrar);
$kpi_w  = 190 / $n_kpi; // portrait: 190mm útiles

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetX(10);
foreach ($niveles_a_mostrar as $nv => $m) {
    $pdf->SetFillColor($m['r'], $m['g'], $m['b']);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($kpi_w, 7, lat($m['label'] . ': ' . $conteo_riesgo[$nv] . ' cred. — ' . fmt((float)$saldo_riesgo[$nv])), 1, 0, 'C', true);
}
$pdf->Ln(9);
$pdf->SetTextColor(0, 0, 0);

// ── Encabezado tabla ───────────────────────────────────────
// Portrait A4: 210mm - 20 márgenes = 190mm útiles
// #(6) | Cliente(45) | Artículo(35) | Vendedor(30) | Zona(16) | C.Venc.(16) | Max.Días(14) | Saldo(24) | Riesgo(20) = 206 → ajustado a 190
$cols   = [6,  44, 34, 28, 15, 15, 14, 22, 18];
$labels = ['#', 'Cliente', 'Articulo', 'Vendedor', 'Zona', 'C.Venc.', 'Max Dias', 'Saldo Pend.', 'Riesgo'];
$aligns = ['C', 'L',  'L',  'L',  'C',  'C',       'C',       'R',          'C'];

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetFillColor(220, 220, 230);
$pdf->SetX(10);
foreach ($cols as $i => $w) {
    $pdf->Cell($w, 6, lat($labels[$i]), 1, 0, $aligns[$i], true);
}
$pdf->Ln();

// ── Filas ──────────────────────────────────────────────────
$riesgo_colors = [
    1 => [34,  197, 94],
    2 => [14,  165, 233],
    3 => [249, 115, 22],
    4 => [239, 68,  68],
];

if (empty($rows)) {
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetX(10);
    $pdf->Cell(array_sum($cols), 8, lat('Sin creditos con los filtros aplicados.'), 1, 1, 'C');
} else {
    $fill = false;
    $num  = 0;
    foreach ($rows as $r) {
        $num++;
        $rc = $riesgo_colors[$r['riesgo']];

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 250 : 255);
        $pdf->SetX(10);

        $pdf->Cell($cols[0], 5.5, $num, 1, 0, 'C', true);
        $pdf->Cell($cols[1], 5.5, $pdf->fitText($r['apellidos'] . ', ' . $r['nombres'], $cols[1] - 2), 1, 0, 'L', true);
        $pdf->Cell($cols[2], 5.5, $pdf->fitText($r['articulo'],  $cols[2] - 2), 1, 0, 'L', true);
        $pdf->Cell($cols[3], 5.5, $pdf->fitText($r['vendedor'],  $cols[3] - 2), 1, 0, 'L', true);
        $pdf->Cell($cols[4], 5.5, $pdf->fitText($r['zona'] ?: '—', $cols[4] - 2), 1, 0, 'C', true);
        $pdf->Cell($cols[5], 5.5, lat((int)$r['cuotas_vencidas'] > 0 ? (string)(int)$r['cuotas_vencidas'] : '—'), 1, 0, 'C', true);
        $pdf->Cell($cols[6], 5.5, lat((int)$r['max_dias'] > 0 ? $r['max_dias'] . ' d.' : '—'), 1, 0, 'C', true);
        $pdf->Cell($cols[7], 5.5, lat(fmt((float)$r['saldo_pendiente'])), 1, 0, 'R', true);

        // Celda Riesgo con color del nivel
        $pdf->SetTextColor($rc[0], $rc[1], $rc[2]);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($cols[8], 5.5, lat($niveles_meta[$r['riesgo']]['label']), 1, 0, 'C', true);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln();
        $fill = !$fill;
    }

    // ── Totales ────────────────────────────────────────────
    $total_saldo = array_sum(array_column($rows, 'saldo_pendiente'));
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetFillColor(220, 220, 230);
    $pdf->SetX(10);
    $pdf->Cell(array_sum($cols), 6,
        lat('Total: ' . count($rows) . ' creditos   Saldo total pendiente: ' . fmt($total_saldo)),
        1, 1, 'C', true);
}

$pdf->Output('I', 'riesgo_cartera_' . date('Ymd') . '.pdf');
