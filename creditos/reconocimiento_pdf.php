<?php
// ============================================================
// creditos/reconocimiento_pdf.php — PDF Reconocimiento de Deuda
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

// ── FPDF ──────────────────────────────────────────────────
$fpdf_paths = [
    __DIR__ . '/../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf/fpdf.php',
    __DIR__ . '/../../fpdf182/fpdf.php',
];
$fpdf_found = false;
foreach ($fpdf_paths as $p) {
    if (file_exists($p)) { require_once $p; $fpdf_found = true; break; }
}
if (!$fpdf_found) die('Error: FPDF no encontrado.');

$pdo = obtener_conexion();
$credito_id = (int) ($_GET['credito_id'] ?? 0);
if (!$credito_id) die('ID inválido.');

// ── Datos ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*,
           cr.monto_total,
           cl.nombres, cl.apellidos, cl.dni, cl.direccion,
           g.nombres AS g_nombres, g.apellidos AS g_apellidos,
           g.dni AS g_dni, g.direccion AS g_direccion
    FROM ic_reconocimientos r
    JOIN ic_creditos cr ON cr.id = r.credito_id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_garantes g ON g.cliente_id = cl.id
    WHERE r.credito_id = ?
");
$stmt->execute([$credito_id]);
$r = $stmt->fetch();
if (!$r) die('No existe reconocimiento para este crédito. <a href="reconocimiento_nuevo.php?credito_id=' . $credito_id . '">Crear</a>');

// ── Número a letras ────────────────────────────────────────
function numero_a_letras_base(int $n): string {
    $u = ['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve'];
    $e = ['diez','once','doce','trece','catorce','quince','dieciseis','diecisiete','dieciocho','diecinueve'];
    $d = ['','','veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
    $c = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
    if ($n == 0) return '';
    $res = '';
    if ($n >= 1000000) { $m = (int)($n / 1000000); $res .= numero_a_letras_base($m) . ($m==1?' millon ':' millones '); $n %= 1000000; }
    if ($n >= 1000)    { $m = (int)($n / 1000);    $res .= ($m==1?'mil ':numero_a_letras_base($m).' mil '); $n %= 1000; }
    if ($n >= 100)     { $res .= $c[(int)($n/100)] . ' '; $n %= 100; }
    if ($n >= 20)      { $res .= $d[(int)($n/10)]; $n %= 10; if ($n>0) $res .= ' y ' . $u[$n]; }
    elseif ($n >= 10)  { $res .= $e[$n-10]; }
    elseif ($n > 0)    { $res .= $u[$n]; }
    return trim($res);
}
function numero_a_letras(float $numero): string {
    $ent  = (int)$numero;
    $cents = (int)round(($numero - $ent) * 100);
    if ($ent == 0) return 'pesos cero';
    $s = 'pesos ' . numero_a_letras_base($ent);
    if ($cents > 0) $s .= ' con ' . $cents . ' centavos';
    return trim($s);
}

// suma_letras: usar guardado o auto-generar
$suma_letras = !empty($r['suma_letras'])
    ? $r['suma_letras']
    : numero_a_letras((float)$r['monto_total']);

$monto_fmt = '$ ' . number_format((float)$r['monto_total'], 2, ',', '.');

// ── Clase PDF ─────────────────────────────────────────────
class PDFRecon extends FPDF {
    private float $mIzq = 18;
    private float $mDer = 18;

    function conv(string $s): string {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    }

    function Header() {
        $logo = __DIR__ . '/../assets/logo.png';
        if (file_exists($logo)) $this->Image($logo, 15, 10, 35);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(26, 35, 126);
        $this->Cell(0, 10, 'RECONOCIMIENTO DE DEUDA', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 6, $this->conv('Imperio Comercial SAS — CUIT 30-71907246-8'), 0, 1, 'C');
        $this->SetDrawColor(26, 35, 126);
        $this->SetLineWidth(0.8);
        $this->Line(15, $this->GetY() + 2, 195, $this->GetY() + 2);
        $this->Ln(8);
        $this->SetTextColor(0, 0, 0);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(0, 0, 0);
    }

    function Footer() {
        // Sin pie de página (espacio para sellos)
    }

    function Parrafo(string $texto, int $espacio = 5) {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->MultiCell(0, 6, $this->conv($texto), 0, 'J');
        $this->Ln($espacio);
    }

    function TablaFirmas(
        string $nombreDeudor, string $dniDeudor,
        string $nombreGarante, string $dniGarante,
        bool $conGarante
    ) {
        $ancho   = 210 - $this->mIzq - $this->mDer;
        $colW    = $ancho / 2;
        $xIzq    = $this->mIzq;
        $xDer    = $xIzq + $colW;
        $hTit    = 11;
        $hCampo  = 13;
        $total   = $hTit + $hCampo * 3;

        if (($this->GetY() + $total) > (297 - 36)) $this->AddPage();
        $this->SetAutoPageBreak(false);
        $y0 = $this->GetY();

        // Fila títulos
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(240, 240, 240);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);
        $this->SetXY($xIzq, $y0);
        $this->Cell($colW, $hTit, $this->conv('Firma del que Suscribe'), 1, 0, 'L', true);
        $this->SetXY($xDer, $y0);
        $this->Cell($colW, $hTit, $this->conv('Firma del Garante'), 1, 0, 'L', true);

        // Filas: Firma / Aclaraciones / DNI
        $labels = ['Firma:', 'Aclaraciones:', 'DNI:'];
        foreach ($labels as $i => $lbl) {
            $yF = $y0 + $hTit + ($i * $hCampo);
            // izquierda
            $this->SetXY($xIzq, $yF); $this->Cell($colW, $hCampo, '', 1, 0);
            $this->SetFont('Arial','B',9); $this->SetXY($xIzq+3, $yF+3); $this->Cell(20, 6, $lbl, 0, 0, 'L');
            // derecha
            $this->SetXY($xDer, $yF); $this->Cell($colW, $hCampo, '', 1, 0);
            if ($conGarante) {
                $this->SetFont('Arial','B',9); $this->SetXY($xDer+3, $yF+3); $this->Cell(20, 6, $lbl, 0, 0, 'L');
            }
        }

        $this->SetXY($xIzq, $y0 + $total + 4);
        $this->SetAutoPageBreak(true, 25);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFont('Arial', '', 11);
    }
}

// ── Generar ────────────────────────────────────────────────
ob_clean();
$pdf = new PDFRecon('P', 'mm', 'A4');
$pdf->SetMargins(18, 28, 18);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$deudor_nombre = $r['apellidos'] . ', ' . $r['nombres'];
$garante_nombre = $r['tiene_garante'] ? (($r['g_apellidos'] ?? '') . ', ' . ($r['g_nombres'] ?? '')) : '';

// ── Párrafo 1: Deudor ─────────────────────────────────────
$p1 = sprintf(
    '%s que suscribe %s, D.N.I. N %s, CUIL/CUIT %s, con domicilio real sito en %s ' .
    'entre calles %s y %s, constituyendo domicilio especial a todos los efectos judiciales, ' .
    'extrajudiciales y administrativos en el mismo, en mi caracter de principal ' .
    'pagador/a de la deuda por la suma total de %s (%s), a favor de Imperio Comercial SAS, ' .
    'CUIT 30-71907246-8.',
    $r['deudor_genero'],
    $deudor_nombre,
    $r['dni'] ?? '-',
    $r['deudor_cuil'] ?: '-',
    $r['direccion'] ?? '-',
    $r['deudor_calle1'] ?: '-',
    $r['deudor_calle2'] ?: '-',
    $monto_fmt,
    $suma_letras
);
$pdf->Parrafo($p1, 4);

// ── Párrafo 2: Garante (condicional) ─────────────────────
if ($r['tiene_garante'] && !empty($r['g_nombres'])) {
    $p2 = sprintf(
        'Reconozco en este acto, que adeudo en concepto de compra en articulos del hogar y/o ' .
        'electrodomestico el pago unico como responsable principal de la misma y/o en forma ' .
        'solidaria con %s %s, D.N.I. N %s, CUIL/CUIT %s, con domicilio real sito en %s, ' .
        'en la localidad %s.',
        $r['garante_genero'],
        $garante_nombre,
        $r['g_dni'] ?? '-',
        $r['garante_cuil'] ?: '-',
        $r['g_direccion'] ?? '-',
        $r['garante_localidad'] ?: '-'
    );
    $pdf->Parrafo($p2, 4);
} else {
    $p2_sin = sprintf(
        'Reconozco en este acto, que adeudo en concepto de compra en articulos del hogar y/o ' .
        'electrodomestico el pago unico como responsable principal de la misma, ' .
        'asumiendo la totalidad de la deuda en forma personal e individual.',
        ''
    );
    $pdf->Parrafo($p2_sin, 4);
}

// ── Párrafos 3 y 4 ────────────────────────────────────────
$p3 = sprintf(
    'Asimismo, me allano en forma total e incondicionada a la demanda de cobro judicial de la ' .
    'deuda por la suma de %s, cualquiera fuere la etapa procesal en la que se encuentre, ' .
    'y desisto de toda accion administrativa o judicial iniciada en contra de ' .
    'Imperio Comercial SAS, CUIT 30-71907246-8.',
    $monto_fmt
);
$pdf->Parrafo($p3, 4);

$p4 = 'El acogimiento a un plan de pago sobre el valor total de la deuda no implica transaccion, ' .
      'novacion de deuda, ni conciliacion. ' .
      'Autorizo expresamente la presentacion de este instrumento en las causas ' .
      'administrativas o judiciales que correspondan, asumiendo en forma total e incondicionada ' .
      'el pago de las costas y gastos causidicos que correspondan.';
$pdf->Parrafo($p4, 4);

// ── Párrafo 5: Fecha ──────────────────────────────────────
$p5 = sprintf(
    'Se firma la presente, en la Ciudad de %s, provincia de %s, a los %d dias del mes de %s del ano %d.-',
    $r['ciudad_firma'], $r['provincia_firma'],
    $r['dia_firma'], $r['mes_firma'], $r['anio_firma']
);
$pdf->Parrafo($p5, 8);

// ── Tabla de firmas ───────────────────────────────────────
$pdf->TablaFirmas(
    $deudor_nombre, $r['dni'] ?? '',
    $garante_nombre, $r['g_dni'] ?? '',
    (bool)$r['tiene_garante']
);

// ── Actualizar flag y log ─────────────────────────────────
$pdo->prepare("UPDATE ic_reconocimientos SET pdf_generado=1 WHERE credito_id=?")
    ->execute([$credito_id]);

registrar_log($pdo, $_SESSION['user_id'], 'RECONOCIMIENTO_EMITIDO', 'credito', $credito_id,
    'PDF Reconocimiento de Deuda emitido');

$nombre_pdf = 'reconocimiento_credito_' . $credito_id . '_' . str_replace([' ',','], '_', $r['apellidos']) . '.pdf';
ob_clean();
$pdf->Output('I', $nombre_pdf);
exit;
