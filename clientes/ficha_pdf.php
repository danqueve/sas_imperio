<?php
// clientes/ficha_pdf.php — PDF de ficha completa del cliente
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) die('ID inválido.');

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) die('Cliente no encontrado.');

$garante = $pdo->prepare("SELECT * FROM ic_garantes WHERE cliente_id=? LIMIT 1");
$garante->execute([$id]);
$g = $garante->fetch();

$creditos = $pdo->prepare("
    SELECT cr.id, cr.fecha_alta, cr.estado, cr.monto_total, cr.monto_cuota,
           cr.frecuencia, cr.cant_cuotas,
           COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS pagadas,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id) AS total_c
    FROM ic_creditos cr
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cr.cliente_id = ?
    ORDER BY cr.fecha_alta DESC
");
$creditos->execute([$id]);
$lista_cr = $creditos->fetchAll();

require_once __DIR__ . '/../fpdf/fpdf.php';

function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function fmt(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle(lat('Ficha - ' . $c['apellidos'] . ', ' . $c['nombres']));
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetMargins(14, 10, 14);

// ── Encabezado ───────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(60, 80, 224);
$pdf->Cell(0, 10, 'IMPERIO COMERCIAL', 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(0, 6, lat('Ficha de Cliente — emitida el ' . date('d/m/Y H:i')), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(60, 80, 224);
$pdf->SetLineWidth(0.4);
$pdf->Line(14, $pdf->GetY() + 1, 196, $pdf->GetY() + 1);
$pdf->Ln(5);

// ── Datos del cliente ────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 242, 255);
$pdf->Cell(0, 7, lat('DATOS DEL CLIENTE'), 'B', 1, 'L', true);
$pdf->Ln(2);

$pmap_lbl = [1=>'Excelente',2=>'Bueno',3=>'Regular',4=>'Malo'];
$punt_lbl = isset($c['puntaje_pago']) ? ($pmap_lbl[(int)$c['puntaje_pago']] ?? '—') : '—';

$datos_cl = [
    ['Nombre',       lat($c['apellidos'] . ', ' . $c['nombres'])],
    ['DNI',          $c['dni'] ?: '—'],
    ['CUIL',         $c['cuil'] ?: '—'],
    ['Nacimiento',   $c['fecha_nacimiento'] ? date('d/m/Y', strtotime($c['fecha_nacimiento'])) : '—'],
    ['Zona',         lat($c['zona'] ?: '—')],
    ['Cobrador',     lat($c['cobrador_nombre'] ? $c['cobrador_nombre'].' '.$c['cobrador_apellido'] : '—')],
    ['Teléfono',     $c['telefono'] ?: '—'],
    ['Tel. Alt.',    $c['telefono_alt'] ?: '—'],
    ['Dirección',    lat($c['direccion'] ?: '—')],
    ['Dir. Laboral', lat($c['direccion_laboral'] ?: '—')],
    ['Estado',       $c['estado']],
    ['Puntaje pago', $punt_lbl],
    ['Alta',         date('d/m/Y', strtotime($c['created_at']))],
];

$pdf->SetFont('Arial', '', 9);
$fill = false;
foreach ($datos_cl as [$lbl, $val]) {
    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 249 : 255, $fill ? 255 : 255);
    $pdf->Cell(45, 6, lat($lbl . ':'), 0, 0, 'R', $fill);
    $pdf->Cell(137, 6, $val, 0, 1, 'L', $fill);
    $fill = !$fill;
}

// ── Garante ──────────────────────────────────────────────────
if ($g) {
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(240, 255, 245);
    $pdf->Cell(0, 7, lat('GARANTE'), 'B', 1, 'L', true);
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 9);
    $datos_g = [
        ['Nombre',    lat($g['apellidos'] . ', ' . $g['nombres'])],
        ['DNI',       $g['dni'] ?: '—'],
        ['Teléfono',  $g['telefono'] ?: '—'],
        ['Dirección', lat($g['direccion'] ?: '—')],
    ];
    $fill = false;
    foreach ($datos_g as [$lbl, $val]) {
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 255 : 255, $fill ? 248 : 255);
        $pdf->Cell(45, 6, lat($lbl . ':'), 0, 0, 'R', $fill);
        $pdf->Cell(137, 6, $val, 0, 1, 'L', $fill);
        $fill = !$fill;
    }
}

// ── Créditos ─────────────────────────────────────────────────
if ($lista_cr) {
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(240, 242, 255);
    $pdf->Cell(0, 7, lat('HISTORIAL DE CRÉDITOS (' . count($lista_cr) . ')'), 'B', 1, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(230, 232, 250);
    $cols = ['#' => 10, 'Fecha' => 22, 'Artículo' => 55, 'Total' => 28, 'Cuota' => 28, 'Avance' => 22, 'Estado' => 17];
    foreach ($cols as $h => $w) {
        $pdf->Cell($w, 7, lat($h), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 7);
    $fill = false;
    foreach ($lista_cr as $cr) {
        $avance = $cr['total_c'] > 0 ? round($cr['pagadas'] / $cr['total_c'] * 100) . '%' : '—';
        $pdf->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 255 : 255);
        $pdf->Cell(10,  6, $cr['id'],                                                   1, 0, 'C', $fill);
        $pdf->Cell(22,  6, date('d/m/Y', strtotime($cr['fecha_alta'])),                 1, 0, 'C', $fill);
        $pdf->Cell(55,  6, lat(mb_strimwidth($cr['articulo'], 0, 35, '...')),           1, 0, 'L', $fill);
        $pdf->Cell(28,  6, fmt((float)$cr['monto_total']),                              1, 0, 'R', $fill);
        $pdf->Cell(28,  6, fmt((float)$cr['monto_cuota']) . ' ' . lat(ucfirst($cr['frecuencia'])), 1, 0, 'R', $fill);
        $pdf->Cell(22,  6, $cr['pagadas'] . '/' . $cr['total_c'] . ' (' . $avance . ')', 1, 0, 'C', $fill);
        $pdf->Cell(17,  6, lat($cr['estado']),                                          1, 1, 'C', $fill);
        $fill = !$fill;
    }
}

// ── Footer ────────────────────────────────────────────────────
$pdf->SetY(-15);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(107, 114, 128);
$pdf->Cell(0, 5, lat('Documento generado el ' . date('d/m/Y H:i') . ' — Imperio Comercial'), 0, 0, 'C');

$nombre_archivo = 'ficha_' . preg_replace('/[^a-z0-9]/i', '_', $c['apellidos']) . '_' . $id . '.pdf';
ob_clean();
$pdf->Output('I', lat($nombre_archivo));
