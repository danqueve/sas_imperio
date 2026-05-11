<?php
// ============================================================
// vendedores/estadisticas_pdf.php — Listado de clientes PDF
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$vendedor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vendedor_id === 0) { header('Location: estadisticas'); exit; }

if ($vendedor_id === -1) {
    $vendedor = ['id' => -1, 'nombre' => 'Asignado', 'apellido' => 'Sin Vendedor'];
} else {
    $vend = $pdo->prepare("SELECT * FROM ic_vendedores WHERE id = ?");
    $vend->execute([$vendedor_id]);
    $vendedor = $vend->fetch();
    if (!$vendedor) { header('Location: estadisticas'); exit; }
}

// ── Filtro temporal ──────────────────────────────────────────
$preset  = $_GET['periodo'] ?? 'historico';
$validos = ['mes_actual','mes_ant','trim','sem','anio','historico','custom'];
if (!in_array($preset, $validos)) $preset = 'historico';

$hoy = date('Y-m-d');
switch ($preset) {
    case 'mes_actual': $f_desde = date('Y-m-01'); $f_hasta = $hoy; break;
    case 'mes_ant':
        $f_desde = date('Y-m-01', strtotime('first day of last month'));
        $f_hasta = date('Y-m-t',  strtotime('last month')); break;
    case 'trim':  $f_desde = date('Y-m-d', strtotime('-3 months')); $f_hasta = $hoy; break;
    case 'sem':   $f_desde = date('Y-m-d', strtotime('-6 months')); $f_hasta = $hoy; break;
    case 'anio':  $f_desde = date('Y-01-01'); $f_hasta = $hoy; break;
    case 'custom':
        $f_desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-01-01');
        $f_hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy; break;
    default: $f_desde = null; $f_hasta = null; break;
}

$tiene_filtro = ($f_desde && $f_hasta);
$where_fecha  = $tiene_filtro ? "AND cr.fecha_alta BETWEEN ? AND ?" : "";
$params_fecha = $tiene_filtro ? [$f_desde, $f_hasta] : [];

$where_vend  = $vendedor_id === -1 ? "cr.vendedor_id IS NULL" : "cr.vendedor_id = ?";
$params_vend = $vendedor_id === -1 ? [] : [$vendedor_id];

$presets_labels = [
    'mes_actual' => 'Mes actual',
    'mes_ant'    => 'Mes anterior',
    'trim'       => 'Ultimos 3 meses',
    'sem'        => 'Ultimos 6 meses',
    'anio'       => date('Y') . ' (ano actual)',
    'historico'  => 'Historico completo',
    'custom'     => 'Personalizado',
];
$label_periodo = $presets_labels[$preset] ?? 'Historico completo';
if ($preset === 'custom' && $tiene_filtro) {
    $label_periodo .= ' (' . date('d/m/Y', strtotime($f_desde)) . ' - ' . date('d/m/Y', strtotime($f_hasta)) . ')';
}

// ── Query créditos ───────────────────────────────────────────
$cred_sql = "
    SELECT
        cr.id, cr.estado, cr.motivo_finalizacion,
        cr.monto_total, cr.monto_cuota, cr.cant_cuotas, cr.frecuencia,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
        (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas,
        (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id
            AND estado IN ('VENCIDA','PENDIENTE') AND fecha_vencimiento < CURDATE()) AS cuotas_vencidas,
        (SELECT MAX(pc2.fecha_pago) FROM ic_pagos_confirmados pc2
            JOIN ic_cuotas cu2 ON pc2.cuota_id=cu2.id
            WHERE cu2.credito_id=cr.id AND pc2.revertido=0) AS ultimo_pago
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE $where_vend $where_fecha
    ORDER BY cuotas_vencidas DESC, cl.apellidos ASC, cl.nombres ASC
";
$stmt = $pdo->prepare($cred_sql);
$stmt->execute(array_merge($params_vend, $params_fecha));
$creditos = $stmt->fetchAll();

// ── PDF ──────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/PDFBase.php';

class VendedorClientesPDF extends PDFBase
{
    public string $vendedor_nombre = '';
    public string $periodo_lbl     = '';
    public string $gen_fecha       = '';

    function Header(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 6, lat('Imperio Comercial'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Listado de Clientes — Vendedor: ' . $this->vendedor_nombre), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(130, 5, lat('Periodo: ' . $this->periodo_lbl), 0, 0, 'L');
        $this->Cell(60, 5, lat('Generado: ' . $this->gen_fecha), 0, 1, 'R');

        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);
    }
}

$pdf = new VendedorClientesPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->vendedor_nombre = $vendedor['apellido'] . ', ' . $vendedor['nombre'];
$pdf->periodo_lbl     = $label_periodo;
$pdf->gen_fecha       = date('d/m/Y H:i');
$pdf->AddPage();

// Anchos columnas (suma = 190mm)
// #(8) | Cliente(58) | Artículo(46) | Avance(22) | Último pago(24) | Cond.(32)
$cols   = [8, 58, 46, 22, 24, 32];
$labels = ['#', 'Cliente', 'Articulo', 'Avance', 'Ultimo pago', 'Estado'];
$aligns = ['C', 'L', 'L', 'C', 'C', 'L'];

// Encabezado tabla
$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetFillColor(220, 220, 230);
$pdf->SetX(10);
foreach ($cols as $i => $w) {
    $pdf->Cell($w, 6, lat($labels[$i]), 1, 0, $aligns[$i], true);
}
$pdf->Ln();

if (empty($creditos)) {
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetX(10);
    $pdf->Cell(190, 8, lat('Sin creditos en el periodo seleccionado.'), 1, 1, 'C');
} else {
    $fill = false;
    $num  = 0;
    foreach ($creditos as $cr) {
        $num++;
        $activo = in_array($cr['estado'], ['EN_CURSO', 'MOROSO']);
        if ($cr['motivo_finalizacion'] === 'RETIRO_PRODUCTO') {
            $cond = 'Retiro Art.';
            $cr_color = [180, 140, 0];
        } elseif ($cr['motivo_finalizacion'] === 'PAGO_COMPLETO' || $cr['estado'] === 'FINALIZADO') {
            $cond = 'Pagado';
            $cr_color = [30, 130, 60];
        } elseif ($activo && $cr['cuotas_vencidas'] > 0) {
            $cond = 'Atrasado (' . $cr['cuotas_vencidas'] . ')';
            $cr_color = [180, 40, 50];
        } else {
            $cond = 'Al dia';
            $cr_color = [30, 130, 60];
        }

        $pct          = $cr['cant_cuotas'] > 0 ? round($cr['cuotas_pagadas'] / $cr['cant_cuotas'] * 100) : 0;
        $avance_txt   = $cr['cuotas_pagadas'] . '/' . $cr['cant_cuotas'] . ' (' . $pct . '%)';
        $ultimo_pago  = $cr['ultimo_pago'] ? date('d/m/Y', strtotime($cr['ultimo_pago'])) : '—';

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 250 : 255);
        $pdf->SetX(10);

        $pdf->Cell($cols[0], 5.5, $num, 1, 0, 'C', true);
        $pdf->Cell($cols[1], 5.5, $pdf->fitText($cr['cliente'], $cols[1] - 2), 1, 0, 'L', true);
        $pdf->Cell($cols[2], 5.5, $pdf->fitText($cr['articulo'], $cols[2] - 2), 1, 0, 'L', true);
        $pdf->Cell($cols[3], 5.5, lat($avance_txt), 1, 0, 'C', true);
        $pdf->Cell($cols[4], 5.5, lat($ultimo_pago), 1, 0, 'C', true);

        // Celda estado con color
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->SetTextColor($cr_color[0], $cr_color[1], $cr_color[2]);
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($cols[5], 5.5, lat($cond), 1, 0, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln();
        $fill = !$fill;
    }

    // Totales
    $total = count($creditos);
    $al_dia    = count(array_filter($creditos, fn($c) => !$c['motivo_finalizacion'] && $c['cuotas_vencidas'] == 0));
    $atrasados = count(array_filter($creditos, fn($c) => !$c['motivo_finalizacion'] && $c['cuotas_vencidas'] > 0));
    $pagados   = count(array_filter($creditos, fn($c) => $c['motivo_finalizacion'] === 'PAGO_COMPLETO'));
    $retirados = count(array_filter($creditos, fn($c) => $c['motivo_finalizacion'] === 'RETIRO_PRODUCTO'));

    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetFillColor(220, 220, 230);
    $pdf->SetX(10);
    $pdf->Cell(190, 6, lat("Total: $total creditos   Al dia: $al_dia   Atrasados: $atrasados   Pagados: $pagados   Retiros: $retirados"), 1, 1, 'C', true);
}

$nombre_vend = preg_replace('/[^a-z0-9]/i', '_', $vendedor['apellido'] . '_' . $vendedor['nombre']);
$pdf->Output('I', "clientes_{$nombre_vend}.pdf");
