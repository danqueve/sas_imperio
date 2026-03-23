<?php
// ============================================================
// creditos/libre_deuda_pdf.php — Certificado de Libre Deuda
// Solo para créditos con estado = FINALIZADO
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

// FPDF — buscar en rutas comunes
$fpdf_paths = [
    __DIR__ . '/../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf182/fpdf.php',
    __DIR__ . '/../../vendor/fpdf/fpdf.php',
];
$fpdf_found = false;
foreach ($fpdf_paths as $p) {
    if (file_exists($p)) { require_once $p; $fpdf_found = true; break; }
}
if (!$fpdf_found) die('Error: FPDF no encontrado.');

$pdo = obtener_conexion();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) die('ID inválido.');

// ── Crédito + cliente + cobrador ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT cr.*,
           cl.nombres, cl.apellidos, cl.dni, cl.telefono, cl.direccion,
           cl.tiene_garante,
           COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    JOIN ic_usuarios u ON cr.cobrador_id = u.id
    WHERE cr.id = ?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();

if (!$cr) die('Crédito no encontrado.');
if ($cr['estado'] !== 'FINALIZADO') die('El crédito no está finalizado. Solo se emite libre deuda para créditos FINALIZADO.');

// ── Garante (si aplica) ───────────────────────────────────────
$garante = null;
if ((int)$cr['tiene_garante']) {
    $g = $pdo->prepare("SELECT nombres, apellidos, dni, direccion FROM ic_garantes WHERE cliente_id = ? LIMIT 1");
    $g->execute([$cr['cliente_id']]);
    $garante = $g->fetch() ?: null;
}

// ── Tipo de documento y texto por motivo ─────────────────────
$motivo = $cr['motivo_finalizacion'] ?? 'PAGO_COMPLETO';

$es_libre_deuda = in_array($motivo, ['PAGO_COMPLETO', 'PAGO_COMPLETO_CON_MORA']);

$titulo_doc = $es_libre_deuda ? 'CERTIFICADO DE LIBRE DEUDA' : 'CONSTANCIA DE CANCELACION DE DEUDA';

$textos = [
    'PAGO_COMPLETO' =>
        'Por medio del presente, IMPERIO COMERCIAL certifica que el/la Sr./Sra. ' .
        strtoupper($cr['apellidos'] . ', ' . $cr['nombres']) .
        ', identificado/a con DNI N.° ' . ($cr['dni'] ?: '—') .
        ', ha abonado la totalidad del capital correspondiente al credito N.° ' . $id .
        ' sin intereses moratorios, quedando LIBRE DE TODA DEUDA con nuestra empresa' .
        ' a partir de la fecha de finalizacion indicada en el presente.',

    'PAGO_COMPLETO_CON_MORA' =>
        'Por medio del presente, IMPERIO COMERCIAL certifica que el/la Sr./Sra. ' .
        strtoupper($cr['apellidos'] . ', ' . $cr['nombres']) .
        ', identificado/a con DNI N.° ' . ($cr['dni'] ?: '—') .
        ', ha abonado la totalidad del capital e intereses moratorios correspondientes al credito N.° ' . $id .
        ', quedando LIBRE DE TODA DEUDA con nuestra empresa' .
        ' a partir de la fecha de finalizacion indicada en el presente.',

    'RETIRO_PRODUCTO' =>
        'Por medio del presente, IMPERIO COMERCIAL certifica que la deuda correspondiente al credito N.° ' . $id .
        ' a nombre de ' . strtoupper($cr['apellidos'] . ', ' . $cr['nombres']) .
        ' ha sido cancelada en virtud del retiro / devolucion del bien financiado,' .
        ' quedando extinguida toda obligacion entre las partes a partir de la fecha de cierre indicada.',

    'ACUERDO_EXTRAJUDICIAL' =>
        'Por medio del presente, IMPERIO COMERCIAL certifica que la deuda correspondiente al credito N.° ' . $id .
        ' a nombre de ' . strtoupper($cr['apellidos'] . ', ' . $cr['nombres']) .
        ' ha sido extinguida mediante acuerdo extrajudicial de partes,' .
        ' no subsistiendo ninguna obligacion pendiente a partir de la fecha de cierre indicada.',

    'INCOBRABILIDAD' =>
        'Por medio del presente, IMPERIO COMERCIAL certifica que el credito N.° ' . $id .
        ' a nombre de ' . strtoupper($cr['apellidos'] . ', ' . $cr['nombres']) .
        ' fue dado de baja por declaracion de incobrabilidad en la fecha indicada.' .
        ' La presente constancia tiene caracter informativo y no implica liberacion de obligaciones legales.',
];

$texto_declaracion = $textos[$motivo] ?? $textos['PAGO_COMPLETO'];

// ── Helpers ───────────────────────────────────────────────────
function lat(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
}
function pesos(float $v): string {
    return '$ ' . number_format($v, 2, ',', '.');
}
function fdate(?string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

// ── Generar PDF ───────────────────────────────────────────────
ob_clean();
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetTitle(lat($titulo_doc . ' - ' . $cr['apellidos'] . ' ' . $cr['nombres']));
$pdf->SetMargins(20, 20, 20);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$ancho = 170; // 210 - 20*2

// ── ENCABEZADO ────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 18);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell($ancho, 10, 'IMPERIO COMERCIAL', 0, 1, 'C');

$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell($ancho, 6, lat('Fecha de emisión: ') . date('d/m/Y'), 0, 1, 'C');
$pdf->Ln(4);

// Título del documento (con fondo diferenciado)
$pdf->SetFillColor($es_libre_deuda ? 16 : 245, $es_libre_deuda ? 185 : 158, $es_libre_deuda ? 129 : 11);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell($ancho, 12, lat($titulo_doc), 0, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell($ancho, 6, lat('Crédito N.° ') . $id . '   |   ' . lat('Emitido el ') . date('d/m/Y \a \l\a\s H:i'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(6);

// ── SECCIÓN 1 — DATOS DEL TITULAR ────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell($ancho, 7, lat('  DATOS DEL TITULAR'), 'LTR', 1, 'L', true);
$pdf->SetFont('Arial', '', 9);

$filas_titular = [
    ['Apellido y Nombre', lat($cr['apellidos'] . ', ' . $cr['nombres'])],
    ['DNI',              $cr['dni'] ?: '—'],
    ['Domicilio',        lat($cr['direccion'] ?: '—')],
    ['Teléfono',         $cr['telefono'] ?: '—'],
];
foreach ($filas_titular as [$lbl, $val]) {
    $pdf->Cell(50, 6, lat($lbl . ':'), 'L', 0, 'L');
    $pdf->Cell($ancho - 50, 6, $val, 'R', 1, 'L');
}
$pdf->Cell($ancho, 1, '', 'LBR', 1); // borde inferior
$pdf->Ln(4);

// ── SECCIÓN 2 — DATOS DEL GARANTE (si aplica) ────────────────
if ($garante) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($ancho, 7, lat('  DATOS DEL GARANTE'), 'LTR', 1, 'L', true);
    $pdf->SetFont('Arial', '', 9);

    $filas_g = [
        ['Apellido y Nombre', lat($garante['apellidos'] . ', ' . $garante['nombres'])],
        ['DNI',              $garante['dni'] ?: '—'],
        ['Domicilio',        lat($garante['direccion'] ?: '—')],
    ];
    foreach ($filas_g as [$lbl, $val]) {
        $pdf->Cell(50, 6, lat($lbl . ':'), 'L', 0, 'L');
        $pdf->Cell($ancho - 50, 6, $val, 'R', 1, 'L');
    }
    $pdf->Cell($ancho, 1, '', 'LBR', 1);
    $pdf->Ln(4);
}

// ── SECCIÓN 3 — DATOS DEL CRÉDITO ────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell($ancho, 7, lat('  DATOS DEL CRÉDITO'), 'LTR', 1, 'L', true);
$pdf->SetFont('Arial', '', 9);

$frec_map = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];
$filas_cr = [
    ['Artículo financiado',  lat($cr['articulo'])],
    ['Monto total financiado', pesos((float)$cr['monto_total'])],
    ['Cuotas',               $cr['cant_cuotas'] . ' cuotas de ' . pesos((float)$cr['monto_cuota']) . ' (' . ($frec_map[$cr['frecuencia']] ?? $cr['frecuencia']) . ')'],
    ['Fecha de alta',        fdate($cr['fecha_alta'])],
    ['Fecha de finalización', fdate($cr['fecha_finalizacion'])],
];
if ((int)$cr['veces_refinanciado'] > 0) {
    $filas_cr[] = ['Refinanciaciones',
        $cr['veces_refinanciado'] . ' vez' . ($cr['veces_refinanciado'] > 1 ? 'ces' : '') .
        ' (última: ' . fdate($cr['fecha_ultima_refinanciacion']) . ')'];
}
foreach ($filas_cr as [$lbl, $val]) {
    $pdf->Cell(60, 6, lat($lbl . ':'), 'L', 0, 'L');
    $pdf->Cell($ancho - 60, 6, lat($val), 'R', 1, 'L');
}
$pdf->Cell($ancho, 1, '', 'LBR', 1);
$pdf->Ln(4);

// ── SECCIÓN 4 — DECLARACIÓN ───────────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell($ancho, 7, lat('  DECLARACIÓN'), 'LTR', 1, 'L', true);

$pdf->SetFont('Arial', '', 9);
$pdf->SetLeftMargin(20);
$pdf->SetRightMargin(20);
$pdf->MultiCell($ancho, 6, lat($texto_declaracion), 'LR', 'J');
$pdf->Cell($ancho, 1, '', 'LBR', 1);
$pdf->Ln(4);

// ── SECCIÓN 5 — FIRMA ─────────────────────────────────────────
$mitad = $ancho / 2 - 10;
// Firma empresa
$pdf->SetX(20);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell($mitad, 20, '', 'B', 0, 'C'); // línea firma
$pdf->SetX(20 + $mitad + 20);
$pdf->Cell($mitad, 20, '', 'B', 1, 'C'); // línea firma

$pdf->SetX(20);
$pdf->Cell($mitad, 5, lat('Firma y Sello — Imperio Comercial'), 0, 0, 'C');
$pdf->SetX(20 + $mitad + 20);
$pdf->Cell($mitad, 5, lat('Firma — Titular / Representante'), 0, 1, 'C');

$pdf->Ln(8);

// ── PIE ───────────────────────────────────────────────────────
$pdf->SetY(-18);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell($ancho, 5, lat('Documento generado el ') . date('d/m/Y H:i') . lat(' — Imperio Comercial — Crédito #') . $id, 0, 1, 'C');

// Registrar emisión en log
registrar_log($pdo, $_SESSION['user_id'], 'LIBRE_DEUDA_EMITIDO', 'credito', $id,
    $titulo_doc . ' — Motivo: ' . $motivo);

$nombre_archivo = 'libre_deuda_credito_' . $id . '_' . str_replace([' ', ','], '_', $cr['apellidos']) . '.pdf';
ob_clean();
$pdf->Output('I', $nombre_archivo);
exit;
