<?php
// ============================================================
// cobrador/clientes_zona_pdf.php — Listado de clientes por día de cobro
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo         = obtener_conexion();
$is_cobrador = es_cobrador();
$user_id     = $_SESSION['user_id'];

$cobrador_id = $is_cobrador ? $user_id : (int)($_GET['cobrador_id'] ?? 0);
if (!$cobrador_id) die('Seleccioná un cobrador.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

// ── Consulta principal ───────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        cl.id   AS cliente_id,
        cl.apellidos, cl.nombres, cl.telefono,
        COALESCE(cl.zona, '') AS zona,
        COALESCE(cr.dia_cobro, 0) AS dia_cobro,
        cr.id          AS credito_id,
        cr.cant_cuotas,
        cr.frecuencia,
        cr.estado      AS credito_estado,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id = cr.id AND estado = 'PAGADA')
            AS cuotas_pagadas,
        (SELECT MIN(cu2.fecha_vencimiento)
         FROM ic_cuotas cu2
         WHERE cu2.credito_id = cr.id
           AND cu2.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
        ) AS prox_vencimiento,
        (SELECT cu3.monto_cuota
         FROM ic_cuotas cu3
         WHERE cu3.credito_id = cr.id
           AND cu3.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
         ORDER BY cu3.fecha_vencimiento ASC
         LIMIT 1
        ) AS monto_cuota
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
    ORDER BY COALESCE(cr.dia_cobro, 0) ASC, cl.apellidos ASC, cl.nombres ASC
");
$stmt->execute([$cobrador_id]);
$rows = $stmt->fetchAll();

if (empty($rows)) die('Sin clientes activos para este cobrador.');

// ── Agrupar por día de cobro ─────────────────────────────────
$nombres_dia_map = [1=>'Lunes',2=>'Martes',3=>'Miercoles',4=>'Jueves',5=>'Viernes',6=>'Sabado',0=>'Sin dia fijo'];
$por_dia = [];
foreach ($rows as $r) {
    $por_dia[(int)$r['dia_cobro']][] = $r;
}
// Ordenar: 1-6 primero, luego 0
$por_dia_ord = [];
foreach ([1,2,3,4,5,6,0] as $d) {
    if (isset($por_dia[$d])) $por_dia_ord[$d] = $por_dia[$d];
}
$por_dia = $por_dia_ord;

// ── Helpers ──────────────────────────────────────────────────
function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 2, ',', '.');
}

// ── FPDF ─────────────────────────────────────────────────────
require_once __DIR__ . '/../fpdf/fpdf.php';

// Columnas (total = 190mm):
// Cliente/Tel(58) + Artículo(50) + Cuota(18) + Monto(30) + Prox.Venc.(34)
$CA     = [58, 50, 18, 30, 34];
$LA     = ['Cliente / Tel.', 'Articulo', 'Cuota', 'Monto', 'Prox. Venc.'];
$ALIGNS = ['L', 'L', 'C', 'R', 'C'];

class ClientesZonaPDF extends FPDF
{
    public string $cobrador_nombre = '';
    public array  $ca  = [];
    public array  $la  = [];
    public array  $ali = [];

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, lat('Pagina ' . $this->PageNo() . ' / {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function encabezadoColumnas()
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetDrawColor(0, 0, 0);
        foreach ($this->ca as $i => $w) {
            $this->Cell($w, 5, lat($this->la[$i]), 1, 0, $this->ali[$i]);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 7);
    }

    function diaHeader(string $dia_nombre, int $cant)
    {
        $this->SetFont('Helvetica', 'BI', 9);
        $this->SetFillColor(220, 245, 220);
        $this->SetTextColor(20, 100, 30);
        $this->Cell(190, 7,
            lat('  ' . strtoupper($dia_nombre) . '   (' . $cant . ' cliente(s))'),
            1, 1, 'L', true);
        $this->SetFillColor(255, 255, 255);
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new ClientesZonaPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->ca  = $CA;
$pdf->la  = $LA;
$pdf->ali = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

// ── Encabezado del documento ─────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell(190, 7, lat('Imperio Comercial — Clientes por Dia de Cobro'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(95, 5, lat('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 0, 'L');
$pdf->Cell(95, 5, lat('Emision: ' . date('d/m/Y') . '   Total clientes: ' . count($rows)), 0, 1, 'R');
$pdf->SetLineWidth(0.4);
$pdf->Line(10, $pdf->GetY() + 1, 200, $pdf->GetY() + 1);
$pdf->Ln(5);

// ── Una sección por día de cobro ─────────────────────────────
foreach ($por_dia as $dia_num => $lista) {
    $dia_nombre = $nombres_dia_map[$dia_num] ?? 'Sin dia fijo';

    // Encabezado de día
    $pdf->diaHeader($dia_nombre, count($lista));
    $pdf->encabezadoColumnas();

    foreach ($lista as $r) {
        // Salto de página anticipado
        if ($pdf->GetY() + 11 > $pdf->GetPageHeight() - 18) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(190, 6, lat(strtoupper($dia_nombre) . ' (continuacion)'), 0, 1, 'L');
            $pdf->encabezadoColumnas();
        }

        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();

        $es_moroso  = $r['credito_estado'] === 'MOROSO';
        $has_phone  = !empty(trim($r['telefono'] ?? ''));
        $row_h      = $has_phone ? 9 : 6;

        $cuotas_pag  = (int)$r['cuotas_pagadas'];
        $cant_cuotas = (int)$r['cant_cuotas'];
        $cuota_label = $cuotas_pag . '/' . $cant_cuotas;

        $monto     = !empty($r['monto_cuota']) ? (float)$r['monto_cuota'] : 0.0;
        $prox_venc = !empty($r['prox_vencimiento'])
            ? date('d/m/y', strtotime($r['prox_vencimiento']))
            : '—';

        $cliente_name = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 38, '..');
        if ($es_moroso) $cliente_name = '[M] ' . $cliente_name;
        $articulo = mb_strimwidth($r['articulo'] ?? '—', 0, 32, '..');

        // ── Celdas con borde ──────────────────────────────────
        $pdf->Cell($CA[0], $row_h, '', 1, 0, 'L', false);                       // cliente (borde)
        $pdf->Cell($CA[1], $row_h, lat($articulo), 1, 0, 'L', false);
        $pdf->Cell($CA[2], $row_h, lat($cuota_label), 1, 0, 'C', false);
        $pdf->Cell($CA[3], $row_h, $monto > 0 ? lat(fmt($monto)) : '—', 1, 0, 'R', false);
        $pdf->Cell($CA[4], $row_h, lat($prox_venc), 1, 0, 'C', false);
        $pdf->Ln();

        // Texto cliente — línea 1
        $pdf->SetFont('Helvetica', $es_moroso ? 'B' : '', 7);
        if ($es_moroso) $pdf->SetTextColor(160, 30, 30);
        $pdf->SetXY($x0 + 0.8, $y0 + 0.8);
        $pdf->Cell($CA[0] - 1, 4, lat($cliente_name), 0, 0, 'L', false);
        $pdf->SetTextColor(0, 0, 0);

        // Texto cliente — línea 2 (teléfono)
        if ($has_phone) {
            $pdf->SetFont('Helvetica', 'I', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($x0 + 0.8, $y0 + 4.5);
            $pdf->Cell($CA[0] - 1, 3.5, lat('Tel: ' . mb_strimwidth($r['telefono'], 0, 24, '')), 0, 0, 'L', false);
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->SetXY(10, $y0 + $row_h);
        $pdf->SetFont('Helvetica', '', 7);
    }

    // Subtotal del día
    $total_monto_dia = array_sum(array_filter(array_column($lista, 'monto_cuota')));
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($CA[0] + $CA[1] + $CA[2], 5, lat('Total ' . $dia_nombre . ': ' . count($lista) . ' cliente(s)'), 1, 0, 'R');
    $pdf->Cell($CA[3], 5, lat(fmt($total_monto_dia)), 1, 0, 'R');
    $pdf->Cell($CA[4], 5, '', 1, 1, 'L');
    $pdf->Ln(4);
}

// ── Total general ────────────────────────────────────────────
$total_monto_gral = array_sum(array_filter(array_column($rows, 'monto_cuota')));
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell($CA[0] + $CA[1] + $CA[2], 7,
    lat('TOTAL GENERAL — ' . count($rows) . ' cliente(s)'), 1, 0, 'R');
$pdf->Cell($CA[3], 7, lat(fmt($total_monto_gral)), 1, 0, 'R');
$pdf->Cell($CA[4], 7, '', 1, 1, 'L');

$nombre_pdf = 'clientes_dia_' . $cobrador_id . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre_pdf);
