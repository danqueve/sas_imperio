<?php
// ============================================================
// admin/rendicion_pdf.php — Exportación PDF con FPDF
// A4 horizontal (landscape), blanco y negro, sin rellenos
// Soporta una jornada (?fecha=X) o todas las pendientes (sin fecha)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo         = obtener_conexion();
$fecha_sel   = trim($_GET['fecha'] ?? '');
$cobrador_id = (int)($_GET['cobrador_id'] ?? 0);
$multi_jornada = ($fecha_sel === '' || $fecha_sel === 'all');

if (!$cobrador_id) die('Cobrador no especificado.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido, usuario FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

// Resumen semanal (Lun-Sáb): antes solo corría para 3 usuarios puntuales que se
// creía tenían un esquema de visita distinto (Lun-Mié); como dia_cobro ya fija
// el día de cada cliente semanal, una ventana uniforme Lun-Sáb da el mismo
// resultado para ellos y además funciona para el resto de los cobradores.
$es_cobrador_semanal = true;

$clientes_semanal_cobrados  = 0;
$clientes_semanal_faltan    = 0;
$clientes_semanal_criticos  = 0;
$clientes_quincenal         = 0;
$clientes_mensual           = 0;
$dinero_faltante_semanal    = 0.0;
$dinero_faltante_quincenal  = 0.0;
$dinero_faltante_mensual    = 0.0;
$monto_estimado_semanal     = 0.0;

// ── Query: una fecha o todas las pendientes ─────────────────
if ($multi_jornada) {
    $dstmt = $pdo->prepare("
        SELECT pt.*,
               cr.id AS credito_id,
               cl.nombres, cl.apellidos, cl.id AS cliente_id,
               cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
               cu.saldo_pagado, cu.estado AS cuota_estado,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
               (SELECT COUNT(*)
                FROM ic_cuotas cu2
                JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
                WHERE cr2.cliente_id = cl.id
                  AND cu2.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
                  AND cr2.estado IN ('EN_CURSO','MOROSO')
                  AND cu2.fecha_vencimiento < CURDATE()
                  AND (cu2.monto_cuota - cu2.saldo_pagado) > 0
               ) AS cuotas_atrasadas_cliente
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu   ON pt.cuota_id     = cu.id
        JOIN ic_creditos cr ON cu.credito_id   = cr.id
        JOIN ic_clientes cl ON cr.cliente_id   = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id  = a.id
        WHERE pt.cobrador_id = ? AND pt.estado = 'PENDIENTE'
        ORDER BY pt.fecha_jornada ASC, cl.apellidos ASC, cl.nombres ASC, cu.numero_cuota ASC
    ");
    $dstmt->execute([$cobrador_id]);
} else {
    $dstmt = $pdo->prepare("
        SELECT pt.*,
               cr.id AS credito_id,
               cl.nombres, cl.apellidos, cl.id AS cliente_id,
               cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
               cu.saldo_pagado, cu.estado AS cuota_estado,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
               (SELECT COUNT(*)
                FROM ic_cuotas cu2
                JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
                WHERE cr2.cliente_id = cl.id
                  AND cu2.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
                  AND cr2.estado IN ('EN_CURSO','MOROSO')
                  AND cu2.fecha_vencimiento < CURDATE()
                  AND (cu2.monto_cuota - cu2.saldo_pagado) > 0
               ) AS cuotas_atrasadas_cliente
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas cu   ON pt.cuota_id     = cu.id
        JOIN ic_creditos cr ON cu.credito_id   = cr.id
        JOIN ic_clientes cl ON cr.cliente_id   = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id  = a.id
        WHERE pt.cobrador_id = ? AND pt.fecha_jornada = ? AND pt.estado = 'PENDIENTE'
        ORDER BY cl.apellidos ASC, cl.nombres ASC, cu.numero_cuota ASC
    ");
    $dstmt->execute([$cobrador_id, $fecha_sel]);
}
$pagos_raw = $dstmt->fetchAll();

if (empty($pagos_raw)) die('No hay pagos pendientes para esta rendicion.');

if ($es_cobrador_semanal) {
    // Cuotas que ya tienen un pago pendiente de aprobar en ESTA rendición (todas las
    // jornadas que se están mostrando, unificadas) — cuentan como "cobradas" aunque
    // ic_cuotas.saldo_pagado todavía no lo refleje (recién se actualiza al aprobar).
    $cuotas_con_pago_pendiente = array_flip(array_column($pagos_raw, 'cuota_id'));

    // Ventana Lunes-Sábado del Resumen: anclada a la jornada que se está
    // imprimiendo (para que reimprimir el mismo PDF más tarde siga
    // mostrando el mismo Resumen Semanal, no el de la semana actual). En
    // modo "PDF Completo" (todas las jornadas pendientes juntas, sin una
    // fecha única) no hay una sola fecha a la cual anclarse, sigue usando
    // la semana actual.
    $fecha_ancla = $multi_jornada ? date('Y-m-d') : $fecha_sel;
    $ancla_dt    = new DateTime($fecha_ancla);
    $dow_ancla   = (int) $ancla_dt->format('N'); // 1=Lunes ... 7=Domingo
    $lunes_sem   = (clone $ancla_dt)->modify('-' . ($dow_ancla - 1) . ' days')->format('Y-m-d');
    $sabado_sem  = (clone $ancla_dt)->modify('-' . ($dow_ancla - 1) . ' days')->modify('+5 days')->format('Y-m-d');

    // Semanal: clientes con cuota venciendo esta semana, cobrados vs faltan
    $stmt_sem = $pdo->prepare("
        SELECT cu.id, cu.estado, cu.monto_cuota, cu.monto_mora, cu.saldo_pagado, cu.fecha_vencimiento,
               cr.interes_moratorio_pct, cr.cliente_id,
               (SELECT COUNT(*)
                FROM ic_cuotas cu2
                JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
                WHERE cr2.cliente_id = cr.cliente_id
                  AND cu2.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
                  AND cr2.estado IN ('EN_CURSO','MOROSO')
                  AND cu2.fecha_vencimiento < CURDATE()
                  AND (cu2.monto_cuota - cu2.saldo_pagado) > 0
               ) AS cuotas_atrasadas_cliente
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cr.cobrador_id = ? AND cr.estado IN ('EN_CURSO','MOROSO')
          AND cr.frecuencia = 'semanal'
          AND cu.fecha_vencimiento BETWEEN ? AND ?
          AND cu.estado != 'CANCELADA'
    ");
    $stmt_sem->execute([$cobrador_id, $lunes_sem, $sabado_sem]);

    $clientes_cobrados_set = [];
    $clientes_faltan_set   = [];
    $clientes_criticos_set = [];
    foreach ($stmt_sem->fetchAll() as $cu) {
        $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
        $mora = (float) $cu['monto_mora'] > 0
            ? (float) $cu['monto_mora']
            : calcular_mora((float) $cu['monto_cuota'], $dias_atraso, (float) $cu['interes_moratorio_pct']);
        $monto_estimado_semanal += (float) $cu['monto_cuota'] + $mora;

        $cobrado = ((float) $cu['saldo_pagado'] > 0)
            || in_array($cu['estado'], ['PAGADA', 'CAP_PAGADA'], true)
            || isset($cuotas_con_pago_pendiente[$cu['id']]);
        if ($cobrado) {
            $clientes_cobrados_set[$cu['cliente_id']] = true;
        } else {
            $clientes_faltan_set[$cu['cliente_id']] = true;
            if ((int) $cu['cuotas_atrasadas_cliente'] >= 5) {
                $clientes_criticos_set[$cu['cliente_id']] = true;
            }
            $dinero_faltante_semanal += max(0, (float) $cu['monto_cuota'] + $mora - (float) $cu['saldo_pagado']);
        }
    }
    $clientes_semanal_cobrados = count($clientes_cobrados_set);
    $clientes_semanal_faltan   = count($clientes_faltan_set);
    $clientes_semanal_criticos = count($clientes_criticos_set);

    // Quincenal/Mensual: solo lo ya vencido o venciendo hoy — NO las cuotas futuras
    // del mismo crédito (un mensual a 12 meses tiene ~11 cuotas en PENDIENTE en
    // cualquier momento; sumarlas todas mostraría el saldo total del préstamo,
    // no la deuda actual realmente exigible).
    $stmt_qm = $pdo->prepare("
        SELECT cr.frecuencia, cr.cliente_id, cr.interes_moratorio_pct,
               cu.monto_cuota, cu.monto_mora, cu.saldo_pagado, cu.fecha_vencimiento
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        WHERE cr.cobrador_id = ? AND cr.estado IN ('EN_CURSO','MOROSO')
          AND cr.frecuencia IN ('quincenal','mensual')
          AND cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
          AND cu.fecha_vencimiento <= CURDATE()
    ");
    $stmt_qm->execute([$cobrador_id]);

    $clientes_quincenal_set = [];
    $clientes_mensual_set   = [];
    foreach ($stmt_qm->fetchAll() as $cu) {
        $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
        $mora = (float) $cu['monto_mora'] > 0
            ? (float) $cu['monto_mora']
            : calcular_mora((float) $cu['monto_cuota'], $dias_atraso, (float) $cu['interes_moratorio_pct']);
        $faltante = max(0, (float) $cu['monto_cuota'] + $mora - (float) $cu['saldo_pagado']);

        if ($cu['frecuencia'] === 'quincenal') {
            $clientes_quincenal_set[$cu['cliente_id']] = true;
            $dinero_faltante_quincenal += $faltante;
        } else {
            $clientes_mensual_set[$cu['cliente_id']] = true;
            $dinero_faltante_mensual += $faltante;
        }
    }
    $clientes_quincenal = count($clientes_quincenal_set);
    $clientes_mensual   = count($clientes_mensual_set);
}

// ── Agrupar pagos multi-cuota por crédito + jornada ─────────
$agrupado = [];
foreach ($pagos_raw as $p) {
    $key = $p['fecha_jornada'] . '_' . (int) $p['credito_id'];
    if (!isset($agrupado[$key])) {
        $agrupado[$key] = $p;
        $agrupado[$key]['cuotas_nums']        = [(int) $p['numero_cuota']];
        $agrupado[$key]['monto_cuota_sum']    = (float) $p['monto_cuota'];
        $agrupado[$key]['saldo_pagado_sum']   = (float)($p['saldo_pagado'] ?? 0);
        $agrupado[$key]['monto_sobrante_sum'] = (float)($p['monto_sobrante'] ?? 0);
        // Marcar si alguna cuota del grupo era PARCIAL
        $agrupado[$key]['tiene_parcial']   = ($p['cuota_estado'] === 'PARCIAL' && (float)($p['saldo_pagado'] ?? 0) > 0);
    } else {
        $agrupado[$key]['cuotas_nums'][]        = (int) $p['numero_cuota'];
        $agrupado[$key]['monto_cuota_sum']      += (float) $p['monto_cuota'];
        $agrupado[$key]['saldo_pagado_sum']     += (float)($p['saldo_pagado'] ?? 0);
        $agrupado[$key]['monto_efectivo']        = (float)$agrupado[$key]['monto_efectivo'] + (float)$p['monto_efectivo'];
        $agrupado[$key]['monto_transferencia']   = (float)$agrupado[$key]['monto_transferencia'] + (float)$p['monto_transferencia'];
        $agrupado[$key]['monto_total']           = (float)$agrupado[$key]['monto_total'] + (float)$p['monto_total'];
        $agrupado[$key]['monto_mora_cobrada']    = (float)$agrupado[$key]['monto_mora_cobrada'] + (float)$p['monto_mora_cobrada'];
        $agrupado[$key]['monto_sobrante_sum']   += (float)($p['monto_sobrante'] ?? 0);
        if ((int)($p['es_cuota_pura'] ?? 0))  $agrupado[$key]['es_cuota_pura']  = 1;
        if ((int)($p['solicitud_baja'] ?? 0)) $agrupado[$key]['solicitud_baja'] = 1;
        if ($p['cuota_estado'] === 'PARCIAL' && (float)($p['saldo_pagado'] ?? 0) > 0) {
            $agrupado[$key]['tiene_parcial'] = true;
        }
    }
}
$pagos = array_values($agrupado);

// ── Agrupar por jornada ─────────────────────────────────────
$por_jornada = [];
foreach ($pagos as $p) {
    $por_jornada[$p['fecha_jornada']][] = $p;
}
ksort($por_jornada);
$fechas_jornada  = array_keys($por_jornada);
$cant_jornadas   = count($fechas_jornada);
$es_multi        = $cant_jornadas > 1;

// ── Totales globales ────────────────────────────────────────
$total_efectivo      = 0.0;
$total_transferencia = 0.0;
$total_general       = 0.0;
$total_mora_cobrada  = 0.0;
$total_mora_pend     = 0.0;
$total_sobrante      = 0.0;

foreach ($pagos as $p) {
    $total_efectivo      += (float) $p['monto_efectivo'];
    $total_transferencia += (float) $p['monto_transferencia'];
    $total_general       += (float) $p['monto_total'];
    $total_sobrante      += (float)($p['monto_sobrante_sum'] ?? 0);
    if ((int)($p['es_cuota_pura'] ?? 0)) {
        $total_mora_pend += (float) $p['monto_mora_cobrada'];
    } else {
        $total_mora_cobrada += (float) $p['monto_mora_cobrada'];
    }
}

require_once __DIR__ . '/../lib/PDFBase.php';

// Anchos columnas: suma = 190mm (portrait A4)
// Cliente(50) + Articulo(40) + Cuota(s)(16) + Vlr.Cuota(22) + Efectivo(22) + Transfer.(22) + Total(18)
$COLS   = [50, 40, 16, 22, 22, 22, 18];
$LABELS = ['Cliente', 'Articulo', 'Cuota(s)', 'Vlr. Cuota', 'Efectivo', 'Transfer.', 'Total'];
$ALIGNS = ['L', 'L', 'C', 'R', 'R', 'R', 'R'];
$ANCHO_TOTAL = array_sum($COLS); // 190

class RendicionPDF extends PDFBase
{
    public string $cobrador_nombre  = '';
    public string $fecha_label     = '';
    public string $fecha_impresion = '';
    public int    $num_pagos       = 0;
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    function Header()
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $aw = array_sum($this->cols); // ancho total

        // Título
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetXY(10, 8);
        $this->Cell($aw, 7, lat('Imperio Comercial - Rendicion de Cobranza'), 0, 1, 'L');

        // Subtítulo explicativo
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetX(10);
        $this->Cell($aw, 4, lat('Detalle de pagos registrados por el cobrador, pendientes de aprobacion'), 0, 1, 'L');

        // Datos del cobrador y fecha
        $this->SetFont('Helvetica', '', 8);
        $this->SetX(10);
        $hw = intval($aw / 2);
        $this->Cell($hw, 5, lat('Cobrador: ' . $this->cobrador_nombre), 0, 0, 'L');
        $this->Cell($aw - $hw, 5, lat($this->fecha_label . '  |  Pagos: ' . $this->num_pagos . '  |  ' . $this->fecha_impresion), 0, 1, 'R');

        // Línea separadora
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 2, 10 + $aw, $this->GetY() + 2);
        $this->Ln(5);

        // Encabezado de tabla
        $this->SetFont('Helvetica', 'B', 8);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], false);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 8);
    }

}

// ── Preparar label de fecha para el header ──────────────────
if ($es_multi) {
    $fecha_label = 'Jornadas: ' . date('d/m', strtotime($fechas_jornada[0])) . ' - ' . date('d/m/Y', strtotime(end($fechas_jornada)));
} else {
    $fecha_label = 'Fecha: ' . label_jornada($fechas_jornada[0]);
}

$pdf = new RendicionPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre  = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->fecha_label      = $fecha_label;
$pdf->fecha_impresion  = date('d/m/Y H:i');
$pdf->num_pagos        = count($pagos);
$pdf->cols            = $COLS;
$pdf->labels          = $LABELS;
$pdf->aligns          = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ── Renderizar jornadas ─────────────────────────────────────
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);

foreach ($por_jornada as $fecha_j => $pagos_j):

    // Sub-encabezado de jornada (solo si multi-jornada)
    if ($es_multi) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($ANCHO_TOTAL, 7, lat('Jornada: ' . label_jornada($fecha_j)), 1, 1, 'L', true);
        $pdf->SetFillColor(255, 255, 255);
    }

    // Organizar en 2 secciones
    $pagos_normal = [];
    $pagos_5plus  = [];
    foreach ($pagos_j as $p) {
        if ((int)$p['cuotas_atrasadas_cliente'] >= 5) {
            $pagos_5plus[] = $p;
        } else {
            $pagos_normal[] = $p;
        }
    }
    
    $secciones = [
        ['titulo' => 'Cobranza Normal (< 5 atrasadas)', 'datos' => $pagos_normal],
        ['titulo' => 'Morosos Criticos (5+ atrasadas)', 'datos' => $pagos_5plus]
    ];

    $j_efectivo = 0.0;
    $j_transfer = 0.0;
    $j_mora     = 0.0;
    $j_total    = 0.0;

    foreach ($secciones as $sec) {
        if (empty($sec['datos'])) continue;
        
        // Sub-encabezado de sección
        $pdf->SetFont('Helvetica', 'BI', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell($ANCHO_TOTAL, 6, lat($sec['titulo']), 1, 1, 'L', false);
        $pdf->SetTextColor(0, 0, 0);

        $sec_efectivo = 0.0;
        $sec_transfer = 0.0;
        $sec_mora     = 0.0;
        $sec_total    = 0.0;

        foreach ($sec['datos'] as $p) {
            $es_pura = (int)($p['es_cuota_pura'] ?? 0);
            $es_baja = (int)($p['solicitud_baja'] ?? 0);

            $cliente_raw = $p['apellidos'] . ', ' . $p['nombres'];
            if ((int)$p['cuotas_atrasadas_cliente'] >= 5) {
                $cliente_raw .= ' (At. ' . $p['cuotas_atrasadas_cliente'] . ')';
            }
            $articulo_raw = $p['articulo'];
            $cuotas_str = implode(', ', array_map(fn($n) => '#' . $n, $p['cuotas_nums']));
            $vlr_cuota  = (float) $p['monto_cuota_sum'];

            $mora_val = (float) $p['monto_mora_cobrada'];

            $ef = (float) $p['monto_efectivo'];
            $tr = (float) $p['monto_transferencia'];
            $tt = (float) $p['monto_total'] + (float)($p['monto_sobrante_sum'] ?? 0);

            $sec_efectivo += $ef;
            $sec_transfer += $tr;
            $sec_mora     += $es_pura ? 0.0 : $mora_val;
            $sec_total    += $tt;

            $pdf->SetFont('Helvetica', '', 8);
            $pdf->Cell($COLS[0], 6, $pdf->fitText($cliente_raw, $COLS[0] - 1), 1, 0, 'L', false);
            $pdf->Cell($COLS[1], 6, $pdf->fitText($articulo_raw, $COLS[1] - 1),1, 0, 'L', false);
            $pdf->Cell($COLS[2], 6, $pdf->fitText($cuotas_str, $COLS[2] - 1), 1, 0, 'C', false);
            $pdf->Cell($COLS[3], 6, $pdf->fitText(fmt($vlr_cuota), $COLS[3] - 1), 1, 0, 'R', false);
            $pdf->Cell($COLS[4], 6, $pdf->fitText(fmt($ef), $COLS[4] - 1),     1, 0, 'R', false);
            $pdf->Cell($COLS[5], 6, $pdf->fitText(fmt($tr), $COLS[5] - 1),     1, 0, 'R', false);
            $pdf->Cell($COLS[6], 6, $pdf->fitText(fmt($tt), $COLS[6] - 1),     1, 0, 'R', false);
            $pdf->Ln();

            // Nota: sobrante no aplicado a ninguna cuota
            $sobrante_p = (float)($p['monto_sobrante_sum'] ?? 0);
            if ($sobrante_p > 0.005) {
                $sobrante_txt = '** SOBRANTE: $' . fmt($sobrante_p) . ' ingresados por el cobrador no fueron aplicados a ninguna cuota.';
                $pdf->SetFont('Helvetica', 'BI', 7);
                $pdf->SetTextColor(150, 60, 0);
                $pdf->Cell(5, 4, '', 0, 0);
                $pdf->Cell($ANCHO_TOTAL - 5, 4, lat($sobrante_txt), 0, 1, 'L');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Helvetica', '', 8);
            }

            // Solicitud de baja inline
            if ($es_baja) {
                $motivo = trim($p['motivo_baja'] ?? '');
                $baja_txt = 'Solicitud de baja' . ($motivo ? ': ' . mb_strimwidth($motivo, 0, 60, '..') : '');
                $pdf->SetFont('Helvetica', 'I', 7);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->Cell(5, 4, '', 0, 0);
                $pdf->Cell($ANCHO_TOTAL - 5, 4, lat($baja_txt), 0, 1, 'L');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Helvetica', '', 8);
            }
        }

        // Fila subtotal sección
        $pdf->SetFont('Helvetica', 'B', 8);
        $ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3];
        $label_total = 'SUBTOTAL ' . mb_strtoupper($sec['titulo'], 'UTF-8');
        $pdf->Cell($ancho_label, 6, lat($label_total), 1, 0, 'R', false);
        $pdf->Cell($COLS[4], 6, $pdf->fitText(fmt($sec_efectivo), $COLS[4] - 1),  1, 0, 'R', false);
        $pdf->Cell($COLS[5], 6, $pdf->fitText(fmt($sec_transfer), $COLS[5] - 1),  1, 0, 'R', false);
        $pdf->Cell($COLS[6], 6, $pdf->fitText(fmt($sec_total), $COLS[6] - 1),     1, 0, 'R', false);
        $pdf->Ln();
        
        $j_efectivo += $sec_efectivo;
        $j_transfer += $sec_transfer;
        $j_mora     += $sec_mora;
        $j_total    += $sec_total;
    }

    // Fila total de jornada
    $pdf->SetFont('Helvetica', 'B', 8);
    $ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3];
    $label_total = $es_multi ? 'TOTAL JORNADA' : 'TOTALES';
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($ancho_label, 7, lat($label_total), 1, 0, 'R', true);
    $pdf->Cell($COLS[4], 7, $pdf->fitText(fmt($j_efectivo), $COLS[4] - 1),  1, 0, 'R', true);
    $pdf->Cell($COLS[5], 7, $pdf->fitText(fmt($j_transfer), $COLS[5] - 1),  1, 0, 'R', true);
    $pdf->Cell($COLS[6], 7, $pdf->fitText(fmt($j_total), $COLS[6] - 1),     1, 0, 'R', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Ln();

    // Espacio entre jornadas
    if ($es_multi) {
        $pdf->Ln(4);
    }

endforeach;

// ── Fila TOTAL GLOBAL (solo si multi-jornada) ───────────────
if ($es_multi) {
    $pdf->SetFont('Helvetica', 'B', 9);
    $ancho_label = $COLS[0] + $COLS[1] + $COLS[2] + $COLS[3];
    $pdf->Cell($ancho_label, 8, lat('TOTAL GENERAL'), 1, 0, 'R', false);
    $pdf->Cell($COLS[4], 8, $pdf->fitText(fmt($total_efectivo), $COLS[4] - 1),      1, 0, 'R', false);
    $pdf->Cell($COLS[5], 8, $pdf->fitText(fmt($total_transferencia), $COLS[5] - 1), 1, 0, 'R', false);
    $pdf->Cell($COLS[6], 8, $pdf->fitText(fmt($total_general + $total_sobrante), $COLS[6] - 1), 1, 0, 'R', false);
    $pdf->Ln();
}

// ── Resumen al pie ──────────────────────────────────────────
// Armar antes de dibujar nada: hace falta la cantidad de filas de ambas cajas
// para calcular la altura total y decidir si hace falta un salto de página
// manual (si no, FPDF puede cortar una caja a la mitad con el salto automático).
$bx  = 95;
$bw1 = 55;
$bw2 = 40;

$resumen = [
    ['Total Efectivo',               fmt($total_efectivo)],
    ['Total Transferencias',         fmt($total_transferencia)],
];
if ($total_mora_cobrada > 0) {
    $resumen[] = ['Mora Cobrada', fmt($total_mora_cobrada)];
}
if ($total_mora_pend > 0) {
    $resumen[] = ['Mora Pendiente (cuota pura)', fmt($total_mora_pend)];
}
if ($total_sobrante > 0.005) {
    $resumen[] = ['!! Sobrante no aplicado', fmt($total_sobrante)];
}
$resumen[] = ['TOTAL RENDIDO', fmt($total_general + $total_sobrante)];

$alto_caja_resumen = count($resumen) * 7;
$alto_caja_semanal = $es_cobrador_semanal ? (6 + 3 * 7 + 4 * 7) : 0; // header + 3 filas KPI + 4 filas dinero
$alto_necesario     = 10 + max($alto_caja_resumen, $alto_caja_semanal); // + Ln(10) previo

if ($pdf->GetY() + $alto_necesario > $pdf->GetPageHeight() - 16) {
    $pdf->AddPage();
} else {
    $pdf->Ln(10);
}
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$y_inicio_resumen = $pdf->GetY();

// ── Resumen semanal (Lun-Mié) — solo enzoteceira/jpbicego/sebadelga ──
// Layout tipo "fila de KPIs": pares label/valor en 2 columnas para los conteos,
// filas completas para los montos — evita una lista larga de una sola columna.
if ($es_cobrador_semanal) {
    $bx_s = 10;
    $bw_s = 85; // ancho total de la caja (hasta x=95, donde arranca la caja de Total Rendido)
    $bwq  = $bw_s / 4; // 4 cuartos: label|valor|label|valor por fila de 2 KPIs

    $pct_cobrado = ($clientes_semanal_cobrados + $clientes_semanal_faltan) > 0
        ? round($clientes_semanal_cobrados / ($clientes_semanal_cobrados + $clientes_semanal_faltan) * 100)
        : 0;

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetX($bx_s);
    $pdf->Cell($bw_s, 6, lat('Resumen Semanal (Lun-Sab) ' . date('d/m', strtotime($lunes_sem)) . '-' . date('d/m', strtotime($sabado_sem))), 1, 1, 'L', true);
    $pdf->SetFillColor(255, 255, 255);

    // Filas de 2 KPIs (label + valor, label + valor)
    $kpis_pares = [
        ['Cobrados',            (string) $clientes_semanal_cobrados,  'Faltan cobrar',   (string) $clientes_semanal_faltan],
        ['Criticos (5+ atr.)',  (string) $clientes_semanal_criticos,  '% Cobrado',       $pct_cobrado . '%'],
        ['Quincenal (cli.)',    (string) $clientes_quincenal,          'Mensual (cli.)',  (string) $clientes_mensual],
    ];
    foreach ($kpis_pares as [$l1, $v1, $l2, $v2]) {
        $pdf->SetX($bx_s);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($bwq, 7, $pdf->fitText($l1, $bwq - 1), 1, 0, 'L', false);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell($bwq, 7, $pdf->fitText($v1, $bwq - 1), 1, 0, 'R', false);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($bwq, 7, $pdf->fitText($l2, $bwq - 1), 1, 0, 'L', false);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell($bwq, 7, $pdf->fitText($v2, $bwq - 1), 1, 1, 'R', false);
    }

    // Filas de dinero faltante, ancho completo (label con cantidad de clientes + monto)
    $bw1_m = $bw_s * 0.6;
    $bw2_m = $bw_s * 0.4;
    $dinero_faltan = [
        ['Falta cobrar Semanal (' . $clientes_semanal_faltan . ' cli.)', fmt($dinero_faltante_semanal)],
        ['Falta cobrar Quincenal (' . $clientes_quincenal . ' cli.)',    fmt($dinero_faltante_quincenal)],
        ['Falta cobrar Mensual (' . $clientes_mensual . ' cli.)',       fmt($dinero_faltante_mensual)],
        ['Monto Estimado Semana',                                       fmt($monto_estimado_semanal)],
    ];
    foreach ($dinero_faltan as [$label, $valor]) {
        $pdf->SetX($bx_s);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($bw1_m, 7, $pdf->fitText($label, $bw1_m - 1), 1, 0, 'L', false);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell($bw2_m, 7, $pdf->fitText($valor, $bw2_m - 1), 1, 1, 'R', false);
    }

    $pdf->SetY($y_inicio_resumen);
}

foreach ($resumen as $i => [$label, $valor]) {
    $es_total = ($i === count($resumen) - 1);
    $pdf->SetFont('Helvetica', $es_total ? 'B' : '', 9);
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 7, lat($label), 1, 0, 'L', false);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($bw2, 7, $pdf->fitText($valor, $bw2 - 1), 1, 1, 'R', false);
}

// La caja de la izquierda (Resumen Semanal) puede terminar más abajo que esta;
// las notas al pie deben arrancar después de la más alta de las dos, si no se
// solapan con las últimas filas de la caja que quedó más larga.
if ($es_cobrador_semanal) {
    $y_fin_semanal = $y_inicio_resumen + $alto_caja_semanal;
    if ($y_fin_semanal > $pdf->GetY()) {
        $pdf->SetY($y_fin_semanal);
    }
}

// ── Nota aclaratoria: alcance del Resumen Semanal ────────────
if ($es_cobrador_semanal) {
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(10);
    $pdf->Cell($ANCHO_TOTAL, 5, lat('Nota: incluye TODOS los clientes de la semana (tambien Criticos) - puede diferir del "Subtotal Semanales" de la Agenda de Cobro.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}
// ── Nota aclaratoria: el PDF refleja el momento de generacion ────
$pdf->Ln(6);
$pdf->SetFont('Helvetica', 'I', 8);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell($ANCHO_TOTAL, 5, lat('Nota: este PDF refleja los pagos pendientes de aprobar en el momento de generarlo (ver hora de Emision arriba). Si despues se aprueba, rechaza o edita un pago, un PDF nuevo puede mostrar otro total.'), 0, 'L');
$pdf->SetTextColor(0, 0, 0);
// ── Nota al pie sobre mora pendiente ─────────────────────────
if ($total_mora_pend > 0) {
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(10);
    $pdf->Cell($ANCHO_TOTAL, 5, lat('(Pend.) = Mora pendiente de cobro (cuota pura). Estos montos NO estan incluidos en los subtotales ni en el Total Rendido.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}
// ── Nota al pie sobre sobrante no aplicado ───────────────────
if ($total_sobrante > 0.005) {
    $pdf->Ln($total_mora_pend > 0 ? 2 : 6);
    $pdf->SetFont('Helvetica', 'BI', 8);
    $pdf->SetTextColor(150, 60, 0);
    $pdf->SetX(10);
    $pdf->Cell($ANCHO_TOTAL, 5, lat('ATENCION — El cobrador ingreso $' . fmt($total_sobrante) . ' que no fueron aplicados a ninguna cuota (sobrante). Verificar con el cobrador.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$nombre = $multi_jornada
    ? 'rendicion_completa_' . $cobrador_id . '_' . date('Ymd') . '.pdf'
    : 'rendicion_jornada_' . str_replace('-', '', $fecha_sel) . '_' . $cobrador_id . '.pdf';
$pdf->Output('I', $nombre);
