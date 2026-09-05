<?php
// ============================================================
// cobrador/faltantes_pdf.php — Clientes que faltaron cobrar en la semana
// (Lunes a Sábado en curso; quincenales/mensuales con vencimiento en la semana)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo         = obtener_conexion();
$is_cobrador = es_cobrador();
$user_id     = $_SESSION['user_id'];

$cobrador_id = $is_cobrador ? $user_id : (int) ($_GET['cobrador_id'] ?? 0);
if (!$cobrador_id) die('Seleccioná un cobrador.');

// ── Semana en curso: Lunes a Sábado ───────────────────────────
$hoy_dt     = new DateTime();
$dow_hoy    = (int) $hoy_dt->format('N'); // 1=Lunes ... 7=Domingo
$lunes_str  = (clone $hoy_dt)->modify('-' . ($dow_hoy - 1) . ' days')->format('Y-m-d');
$sabado_str = (clone $hoy_dt)->modify('-' . ($dow_hoy - 1) . ' days')->modify('+5 days')->format('Y-m-d');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

// ── Semanales: cuotas que vencen esta semana (Lun-Sáb) ────────
$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cl.zona,'') AS zona, cl.direccion,
           cr.id AS credito_id, cr.interes_moratorio_pct, cr.cant_cuotas,
           cr.estado AS credito_estado,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado, cu.monto_mora, cu.saldo_pagado,
           (SELECT MAX(pt.fecha_jornada)
            FROM ic_pagos_confirmados pc
            JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
            JOIN ic_cuotas cu2          ON cu2.id = pc.cuota_id
            WHERE cu2.credito_id = cr.id) AS ultimo_pago,
           (SELECT COUNT(*) FROM ic_cuotas cu3
            WHERE cu3.credito_id = cr.id
              AND cu3.fecha_vencimiento < CURDATE()
              AND cu3.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
           ) AS cuotas_atrasadas,
           'semanal' AS frecuencia
    FROM ic_clientes cl
    JOIN ic_creditos cr ON cr.cliente_id = cl.id
                        AND cr.cobrador_id = ?
                        AND cr.estado IN ('EN_CURSO','MOROSO')
                        AND cr.frecuencia = 'semanal'
    JOIN ic_cuotas cu ON cu.credito_id = cr.id
                      AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
                      AND cu.fecha_vencimiento <= ?
    WHERE NOT EXISTS (
        SELECT 1
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas   cu2 ON cu2.id = pt.cuota_id
        JOIN ic_creditos cr2 ON cr2.id = cu2.credito_id
        WHERE cr2.cliente_id = cl.id
          AND pt.fecha_jornada BETWEEN ? AND ?
          AND pt.estado IN ('PENDIENTE','APROBADO')
    )
    ORDER BY COALESCE(cl.zona,''), cl.apellidos, cl.nombres, cu.fecha_vencimiento ASC
");
$stmt->execute([$cobrador_id, $sabado_str, $lunes_str, $sabado_str]);
$rows = $stmt->fetchAll();

// ── Diario/Quincenal/Mensual: cuotas con vencimiento dentro de la semana ──
$stmt2 = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cl.zona,'') AS zona, cl.direccion,
           cr.id AS credito_id, cr.interes_moratorio_pct, cr.cant_cuotas,
           cr.estado AS credito_estado,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado, cu.monto_mora, cu.saldo_pagado,
           (SELECT MAX(pt.fecha_jornada)
            FROM ic_pagos_confirmados pc
            JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
            JOIN ic_cuotas cu2          ON cu2.id = pc.cuota_id
            WHERE cu2.credito_id = cr.id) AS ultimo_pago,
           (SELECT COUNT(*) FROM ic_cuotas cu3
            WHERE cu3.credito_id = cr.id
              AND cu3.fecha_vencimiento < CURDATE()
              AND cu3.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
           ) AS cuotas_atrasadas,
           cr.frecuencia
    FROM ic_clientes cl
    JOIN ic_creditos cr ON cr.cliente_id = cl.id
                        AND cr.cobrador_id = ?
                        AND cr.estado IN ('EN_CURSO','MOROSO')
                        AND cr.frecuencia IN ('diario','quincenal','mensual')
    JOIN ic_cuotas cu ON cu.credito_id = cr.id
                      AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
                      AND cu.fecha_vencimiento <= ?
    WHERE NOT EXISTS (
        SELECT 1
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas   cu2 ON cu2.id = pt.cuota_id
        JOIN ic_creditos cr2 ON cr2.id = cu2.credito_id
        WHERE cr2.cliente_id = cl.id
          AND pt.fecha_jornada BETWEEN ? AND ?
          AND pt.estado IN ('PENDIENTE','APROBADO')
    )
    ORDER BY COALESCE(cl.zona,''), cl.apellidos, cl.nombres, cu.fecha_vencimiento ASC
");
$stmt2->execute([$cobrador_id, $sabado_str, $lunes_str, $sabado_str]);
$rows = array_merge($rows, $stmt2->fetchAll());

// ── Clientes excluidos por tener un cobro de esta semana pendiente de aprobar ──
// (ya fueron visitados y pagaron, pero el admin todavia no aprobo el pago -
// por eso no figuran arriba como "faltante" aunque su cuota siga sin marcar PAGADA)
$stmt_pend = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono,
           SUM(pt.monto_total) AS monto_pendiente,
           MAX(pt.fecha_registro) AS fecha_registro
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas   cu ON cu.id = pt.cuota_id
    JOIN ic_creditos cr ON cr.id = cu.credito_id
    JOIN ic_clientes cl ON cl.id = cr.cliente_id
    WHERE cr.cobrador_id = ?
      AND pt.estado = 'PENDIENTE'
      AND pt.fecha_jornada BETWEEN ? AND ?
    GROUP BY cl.id, cl.nombres, cl.apellidos, cl.telefono
    ORDER BY cl.apellidos, cl.nombres
");
$stmt_pend->execute([$cobrador_id, $lunes_str, $sabado_str]);
$rows_pendientes = $stmt_pend->fetchAll();

if (empty($rows) && empty($rows_pendientes)) die('No hay clientes faltantes para esta semana.');

// ── Una fila por crédito, sumando TODAS sus cuotas impagas (evita duplicar el
// mismo cliente si tiene varias cuotas atrasadas, y evita subestimar el monto
// adeudado mostrando solo la cuota más vieja) ──
$grupos = [];
foreach ($rows as $r) {
    $grupos[$r['credito_id']][] = $r;
}
$rows_dedup = [];
foreach ($grupos as $cuotas_grupo) {
    $rep = $cuotas_grupo[0]; // la más vieja — el ORDER BY de ambas queries ya lo garantiza
    $rep['monto_total_grupo'] = calcular_grupo_cuotas($cuotas_grupo)['monto_total'];
    $rows_dedup[] = $rep;
}
$rows = $rows_dedup;
$hay_atraso_previo = false;

// ── Agrupar por frecuencia (Semanal/Quincenal/Mensual/Diario) ─
$ORDEN_FREC = ['semanal' => 'Semanales', 'quincenal' => 'Quincenales', 'mensual' => 'Mensuales', 'diario' => 'Diarios'];
$por_frecuencia = ['semanal' => [], 'quincenal' => [], 'mensual' => [], 'diario' => []];
foreach ($rows as $r) {
    $por_frecuencia[$r['frecuencia']][] = $r;
}

function fmt(float $v): string {
    return '$ ' . number_format($v, 2, ',', '.');
}

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas (total = 190mm)
$CA     = [8, 42, 62, 18, 32, 28];
$LA     = ['#', 'Cliente', 'Direccion', 'Vencim.', 'Monto Adeudado', 'Ult. Pago'];
$ALIGNS = ['C', 'L', 'L', 'C', 'R', 'L'];

class FaltantesPDF extends PDFBase
{
    public string $cobrador_nombre = '';
    public array  $ca  = [];
    public array  $la  = [];
    public array  $ali = [];

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

    function zonaHeader(string $zona, int $cant)
    {
        $this->SetFont('Helvetica', 'BI', 9);
        $this->SetFillColor(250, 225, 210);
        $zona_txt = $zona !== '' ? strtoupper($zona) : 'SIN ZONA';
        $this->Cell(190, 7, lat('  ' . $zona_txt . '   (' . $cant . ' cliente(s))'), 1, 1, 'L', true);
        $this->SetFillColor(255, 255, 255);
    }
}

$pdf = new FaltantesPDF('P', 'mm', 'A4');
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
$pdf->Cell(190, 7, lat('Imperio Comercial — Clientes que Faltaron Cobrar'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(95, 5, lat('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 0, 'L');
$pdf->Cell(95, 5, lat('Semana: ' . date('d/m/Y', strtotime($lunes_str)) . ' - ' . date('d/m/Y', strtotime($sabado_str)) . '   Total: ' . count($rows)), 0, 1, 'R');
$pdf->SetLineWidth(0.4);
$pdf->Line(10, $pdf->GetY() + 1, 200, $pdf->GetY() + 1);
$pdf->Ln(5);

// ── Una sección por frecuencia, subagrupada por zona ──────────
foreach ($ORDEN_FREC as $frec_key => $frec_label) {
    $lista_frec = $por_frecuencia[$frec_key];
    if (empty($lista_frec)) continue;

    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(190, 7, lat($frec_label . ' — ' . count($lista_frec) . ' cliente(s)'), 0, 1, 'L');
    $pdf->Ln(1);

    $por_zona = [];
    foreach ($lista_frec as $r) {
        $por_zona[$r['zona']][] = $r;
    }
    ksort($por_zona);

    $total_frec = 0.0;

foreach ($por_zona as $zona => $lista) {
    $pdf->zonaHeader($zona, count($lista));
    $pdf->encabezadoColumnas();

    $total_zona = 0.0;
    $num        = 0;

    foreach ($lista as $r) {
        if ($pdf->GetY() + 11 > $pdf->GetPageHeight() - 18) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(190, 6, lat($frec_label . ' — ' . ($zona !== '' ? strtoupper($zona) : 'SIN ZONA') . ' (continuacion)'), 0, 1, 'L');
            $pdf->encabezadoColumnas();
        }

        $num++;
        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();

        $es_moroso  = $r['credito_estado'] === 'MOROSO';
        $has_phone  = !empty(trim($r['telefono'] ?? ''));
        $row_h      = $has_phone ? 9 : 6;

        $total_cobrar = (float) $r['monto_total_grupo'];
        $total_zona  += $total_cobrar;

        $ult_pago_txt = !empty($r['ultimo_pago'])
            ? date('d/m/y', strtotime($r['ultimo_pago']))
            : 'Sin pagos';

        $cliente_name = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 24, '..');
        if ($es_moroso) $cliente_name = '[M] ' . $cliente_name;
        $direccion = mb_strimwidth(trim($r['direccion'] ?? '') ?: '-', 0, 36, '..');
        $venc      = date('d/m/y', strtotime($r['fecha_vencimiento']));
        $es_previo = $r['fecha_vencimiento'] < $lunes_str;
        if ($es_previo) $hay_atraso_previo = true;

        // Celdas con borde, todas vacías — el texto se superpone centrado verticalmente
        $pdf->Cell($CA[0], $row_h, (string) $num, 1, 0, 'C', false);
        $pdf->Cell($CA[1], $row_h, '', 1, 0, 'L', false);
        $pdf->Cell($CA[2], $row_h, '', 1, 0, 'L', false);
        $pdf->Cell($CA[3], $row_h, '', 1, 0, 'C', false);
        $pdf->Cell($CA[4], $row_h, '', 1, 0, 'R', false);
        $pdf->Cell($CA[5], $row_h, '', 1, 0, 'L', false);
        $pdf->Ln();

        $y_centro = $y0 + ($row_h - 4) / 2; // centrado vertical para texto de 1 línea

        // Texto cliente
        $pdf->SetFont('Helvetica', $es_moroso ? 'B' : '', 7);
        $pdf->SetXY($x0 + $CA[0] + 0.8, $has_phone ? $y0 + 0.8 : $y_centro);
        $pdf->Cell($CA[1] - 1, 4, lat($cliente_name), 0, 0, 'L', false);

        // Texto cliente — línea 2 (teléfono + cantidad de cuotas atrasadas)
        if ($has_phone) {
            $atrasadas = (int) ($r['cuotas_atrasadas'] ?? 0);
            $tel_txt   = 'Tel: ' . mb_strimwidth($r['telefono'], 0, 24, '');
            if ($atrasadas > 0) {
                $tel_txt .= '  ' . $atrasadas . '*';
            }
            $pdf->SetFont('Helvetica', 'I', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($x0 + $CA[0] + 0.8, $y0 + 4.5);
            $pdf->Cell($CA[1] - 1, 3.5, lat($tel_txt), 0, 0, 'L', false);
            $pdf->SetTextColor(0, 0, 0);
        }

        // Texto dirección
        $dx = $x0 + $CA[0] + $CA[1];
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY($dx + 0.8, $y_centro);
        $pdf->Cell($CA[2] - 1, 4, lat($direccion), 0, 0, 'L', false);

        // Texto vencimiento (naranja si viene de semanas anteriores)
        $vx = $dx + $CA[2];
        if ($es_previo) $pdf->SetTextColor(200, 80, 0);
        $pdf->SetXY($vx, $y_centro);
        $pdf->Cell($CA[3], 4, $venc, 0, 0, 'C', false);
        if ($es_previo) $pdf->SetTextColor(0, 0, 0);

        // Texto monto adeudado
        $mx = $vx + $CA[3];
        $pdf->SetXY($mx + 0.5, $y_centro);
        $pdf->Cell($CA[4] - 1, 4, lat(fmt($total_cobrar)), 0, 0, 'R', false);

        // Texto último pago (gris-rojizo itálica si nunca pagó)
        $sin_pagos = empty($r['ultimo_pago']);
        $ux = $mx + $CA[4];
        $pdf->SetFont('Helvetica', $sin_pagos ? 'I' : '', 7);
        if ($sin_pagos) $pdf->SetTextColor(150, 80, 80);
        $pdf->SetXY($ux + 0.5, $y_centro);
        $pdf->Cell($CA[5] - 1, 4, lat($ult_pago_txt), 0, 0, 'L', false);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(10, $y0 + $row_h);
        $pdf->SetFont('Helvetica', '', 7);
    }

    // Subtotal de zona
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($CA[0] + $CA[1] + $CA[2] + $CA[3], 6, lat('Subtotal — ' . count($lista) . ' cliente(s)'), 1, 0, 'R');
    $pdf->Cell($CA[4], 6, lat(fmt($total_zona)), 1, 0, 'R');
    $pdf->Cell($CA[5], 6, '', 1, 1, 'L');
    $pdf->Ln(4);

    $total_frec += $total_zona;
}

    // Subtotal de frecuencia
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell($CA[0] + $CA[1] + $CA[2] + $CA[3], 7, lat('TOTAL ' . strtoupper($frec_label) . ' — ' . count($lista_frec) . ' cliente(s)'), 1, 0, 'R');
    $pdf->Cell($CA[4], 7, lat(fmt($total_frec)), 1, 0, 'R');
    $pdf->Cell($CA[5], 7, '', 1, 1, 'L');
    $pdf->Ln(6);
}

// ── Total general ────────────────────────────────────────────
if (!empty($rows)) {
    $total_gral = 0.0;
    foreach ($rows as $r) {
        $total_gral += (float) $r['monto_total_grupo'];
    }
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($CA[0] + $CA[1] + $CA[2] + $CA[3], 7, lat('TOTAL GENERAL — ' . count($rows) . ' cliente(s)'), 1, 0, 'R');
    $pdf->Cell($CA[4], 7, lat(fmt($total_gral)), 1, 0, 'R');
    $pdf->Cell($CA[5], 7, '', 1, 1, 'L');
}

if ($hay_atraso_previo) {
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(200, 80, 0);
    $pdf->SetX(10);
    $pdf->Cell(190, 4, lat('Fecha en naranja = cuota vencida de semanas anteriores, todavia sin cobrar.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

// ── Cobros de esta semana pendientes de aprobar (no incluidos arriba) ──
if (!empty($rows_pendientes)) {
    if ($pdf->GetY() + 20 > $pdf->GetPageHeight() - 18) $pdf->AddPage();
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(190, 7, lat('Cobros de esta semana pendientes de aprobar (' . count($rows_pendientes) . ')'), 0, 1, 'L');

    $PCOLS = [65, 30, 35, 60];
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetFillColor(250, 225, 210);
    $pdf->Cell($PCOLS[0], 6, lat('Cliente'), 1, 0, 'L', true);
    $pdf->Cell($PCOLS[1], 6, lat('Telefono'), 1, 0, 'L', true);
    $pdf->Cell($PCOLS[2], 6, lat('Monto Cobrado'), 1, 0, 'R', true);
    $pdf->Cell($PCOLS[3], 6, lat('Cargado'), 1, 1, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('Helvetica', '', 7);

    $total_pend = 0.0;
    foreach ($rows_pendientes as $rp) {
        if ($pdf->GetY() + 6 > $pdf->GetPageHeight() - 18) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->SetFillColor(250, 225, 210);
            $pdf->Cell($PCOLS[0], 6, lat('Cliente'), 1, 0, 'L', true);
            $pdf->Cell($PCOLS[1], 6, lat('Telefono'), 1, 0, 'L', true);
            $pdf->Cell($PCOLS[2], 6, lat('Monto Cobrado'), 1, 0, 'R', true);
            $pdf->Cell($PCOLS[3], 6, lat('Cargado'), 1, 1, 'L', true);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetFont('Helvetica', '', 7);
        }
        $nombre_pend = mb_strimwidth($rp['apellidos'] . ', ' . $rp['nombres'], 0, 38, '..');
        $monto_pend  = (float) $rp['monto_pendiente'];
        $total_pend += $monto_pend;
        $pdf->Cell($PCOLS[0], 6, $pdf->fitText($nombre_pend, $PCOLS[0] - 2), 1, 0, 'L');
        $pdf->Cell($PCOLS[1], 6, lat($rp['telefono'] ?: '-'), 1, 0, 'L');
        $pdf->Cell($PCOLS[2], 6, lat(fmt($monto_pend)), 1, 0, 'R');
        $pdf->Cell($PCOLS[3], 6, lat(date('d/m/y H:i', strtotime($rp['fecha_registro']))), 1, 1, 'L');
    }
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($PCOLS[0] + $PCOLS[1], 6, lat('TOTAL'), 1, 0, 'R');
    $pdf->Cell($PCOLS[2], 6, lat(fmt($total_pend)), 1, 0, 'R');
    $pdf->Cell($PCOLS[3], 6, '', 1, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(10);
    $pdf->MultiCell(190, 4, lat(
        'Estos clientes ya fueron visitados y pagaron esta semana, pero el pago todavia no fue ' .
        'aprobado por un admin/supervisor — por eso no aparecen en la lista de "Faltaron Cobrar" ' .
        'de arriba, aunque su cuota todavia no figure como pagada.'
    ), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$nombre_pdf = 'faltantes_' . $cobrador_id . '_' . str_replace('-', '', $lunes_str) . '.pdf';
$pdf->Output('I', $nombre_pdf);
