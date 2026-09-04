<?php
// ============================================================
// lib/CuponPagoPDF.php — Cupón de pago (comprobante de cobro)
// Generado por cobrador/registrar_pago.php al registrar un pago,
// guardado en cupones_pago/AAAA/MM/DD/cupon_{id}.pdf e indexado en
// ic_cupones_pago para poder buscarlo después (cobrador/cupones.php).
// ============================================================

require_once __DIR__ . '/PDFBase.php';

class CuponPagoPDF extends PDFBase
{
    // Ticket angosto (80mm, ancho típico de impresora térmica portátil) —
    // sin numeración de página, no aporta nada en un comprobante de 1 sola hoja.
    public function Footer(): void
    {
    }

    // Línea punteada — misma estética de "perforación" que un ticket real,
    // en vez de las líneas sólidas usadas en los PDF A4 del resto del sistema.
    public function dashedLine(float $x1, float $x2, ?float $y = null): void
    {
        $y = $y ?? $this->GetY();
        $dash = 1;
        $gap  = 0.8;
        $this->SetLineWidth(0.2);
        for ($x = $x1; $x < $x2; $x += $dash + $gap) {
            $this->Line($x, $y, min($x + $dash, $x2), $y);
        }
    }
}

/**
 * Genera el PDF del cupón para los pagos temporales indicados, lo guarda
 * en disco (cupones_pago/AAAA/MM/DD/) y lo indexa en ic_cupones_pago.
 * Devuelve el id del cupón, o null si no se pudo generar (no interrumpe
 * el flujo de pago — el pago ya quedó registrado igual).
 */
function generar_cupon_pago(
    PDO $pdo,
    int $credito_id,
    int $cliente_id,
    int $cobrador_id,
    string $fecha_jornada,
    array $pt_ids,
    float $monto_total
): ?int {
    $pt_ids = array_values(array_filter(array_map('intval', $pt_ids)));
    if (empty($pt_ids)) return null;

    $placeholders = implode(',', array_fill(0, count($pt_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT pt.id AS pt_id, pt.monto_efectivo, pt.monto_transferencia, pt.monto_total AS pt_monto,
               pt.monto_sobrante,
               cu.numero_cuota, cu.monto_cuota,
               COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
               cl.nombres, cl.apellidos,
               u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido
        FROM ic_pagos_temporales pt
        JOIN ic_cuotas   cu ON pt.cuota_id   = cu.id
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        JOIN ic_usuarios u  ON cr.cobrador_id = u.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE pt.id IN ($placeholders)
        ORDER BY cu.numero_cuota ASC
    ");
    $stmt->execute($pt_ids);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return null;

    $cant_cuotas = count($rows);

    // Indexar primero (sin ruta_archivo todavía) para tener el id del cupón
    $ins = $pdo->prepare("
        INSERT INTO ic_cupones_pago
          (credito_id, cliente_id, cobrador_id, fecha_jornada, pago_temp_ids, monto_total, cant_cuotas)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$credito_id, $cliente_id, $cobrador_id, $fecha_jornada, implode(',', $pt_ids), $monto_total, $cant_cuotas]);
    $cupon_id = (int) $pdo->lastInsertId();

    // Carpeta por fecha: cupones_pago/AAAA/MM/DD/
    $fecha_dt = new DateTime($fecha_jornada);
    $rel_dir  = 'cupones_pago/' . $fecha_dt->format('Y/m/d');
    $abs_dir  = __DIR__ . '/../' . $rel_dir;
    if (!is_dir($abs_dir) && !mkdir($abs_dir, 0755, true) && !is_dir($abs_dir)) {
        return null;
    }
    $rel_path = $rel_dir . '/cupon_' . $cupon_id . '.pdf';
    $abs_path = __DIR__ . '/../' . $rel_path;

    // ── Construcción del PDF ────────────────────────────────
    $primero      = $rows[0];
    $cliente_nom  = trim($primero['apellidos'] . ', ' . $primero['nombres']);
    $cobrador_nom = trim($primero['cobrador_nombre'] . ' ' . $primero['cobrador_apellido']);
    $fecha_fmt    = $fecha_dt->format('d/m/Y');
    $nro_cupon    = str_pad((string) $cupon_id, 6, '0', STR_PAD_LEFT);

    $total_ef  = array_sum(array_column($rows, 'monto_efectivo'));
    $total_tr  = array_sum(array_column($rows, 'monto_transferencia'));
    // $monto_total (parámetro) es el monto real entregado por el cliente — puede
    // ser mayor que la suma de los pt.monto_total si sobró efectivo/transferencia
    // sin aplicar a ninguna cuota (queda registrado aparte en monto_sobrante, sin
    // reflejarse en ningún pt.monto_total individual). Usar $monto_total para el
    // TOTAL impreso, no la suma de las cuotas, para que el ticket coincida con lo
    // que el cliente realmente entregó y con lo que muestra cobrador/cupones.php.
    $total_sobrante = array_sum(array_column($rows, 'monto_sobrante'));

    // Ticket angosto de 80mm (ancho estándar de impresora térmica portátil),
    // con alto calculado según la cantidad de cuotas para que no sobre ni
    // falte papel — no es un documento A4, es un cupón chico tipo comprobante
    // de transferencia.
    $PAGE_W = 80;
    $X      = 4;
    $W      = $PAGE_W - ($X * 2); // 72mm útiles
    $alto_mm = 92 + ($cant_cuotas * 8);

    $pdf = new CuponPagoPDF('P', 'mm', [$PAGE_W, $alto_mm]);
    $pdf->SetMargins($X, 4, $X);
    $pdf->SetAutoPageBreak(true, 4); // red de seguridad si el calculo se queda corto
    $pdf->AddPage();

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    // Empresa
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell($W, 5, lat(EMP_RAZON), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 6.5);
    $pdf->Cell($W, 3.5, lat('CUIT: ' . EMP_CUIT), 0, 1, 'C');
    $pdf->Ln(1.5);
    $pdf->dashedLine($X, $X + $W);
    $pdf->Ln(2);

    // N° de cupón + fecha
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell($W, 4, lat('CUPON DE PAGO N° ' . $nro_cupon), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Cell($W, 4, lat($fecha_fmt), 0, 1, 'C');
    $pdf->Ln(1.5);
    $pdf->dashedLine($X, $X + $W);
    $pdf->Ln(2);

    // Cliente / crédito / cobrador
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($W, 3.8, lat('Cliente'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($W, 4.2, $pdf->fitText($cliente_nom, $W), 0, 1, 'L');
    $pdf->Ln(0.5);
    $pdf->SetFont('Helvetica', '', 6.5);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell($W * 0.5, 3.5, lat('Credito #' . $credito_id), 0, 0, 'L');
    $pdf->Cell($W * 0.5, 3.5, $pdf->fitText($cobrador_nom, $W * 0.5), 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(1.5);
    $pdf->dashedLine($X, $X + $W);
    $pdf->Ln(2);

    // Cuotas cubiertas — # + monto en una linea, articulo debajo en gris chico
    foreach ($rows as $r) {
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->Cell($W * 0.5, 4, lat('Cuota #' . $r['numero_cuota']), 0, 0, 'L');
        $pdf->Cell($W * 0.5, 4, lat(fmt((float) $r['pt_monto'], 2)), 0, 1, 'R');
        $pdf->SetFont('Helvetica', 'I', 6.5);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell($W, 3.3, $pdf->fitText($r['articulo'] ?? '-', $W), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(0.5);
    }

    $pdf->Ln(0.5);
    $pdf->dashedLine($X, $X + $W);
    $pdf->Ln(2);

    // Total
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($W * 0.5, 5, lat('TOTAL'), 0, 0, 'L');
    $pdf->Cell($W * 0.5, 5, lat(fmt($monto_total, 2)), 0, 1, 'R');
    $pdf->Ln(1.5);
    $pdf->dashedLine($X, $X + $W);
    $pdf->Ln(2);

    // Forma de pago
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Cell($W, 4, lat('Efectivo: ' . fmt($total_ef, 2)), 0, 1, 'L');
    $pdf->Cell($W, 4, lat('Transferencia: ' . fmt($total_tr, 2)), 0, 1, 'L');
    if ($total_sobrante > 0.5) {
        $pdf->Cell($W, 4, lat('Sobrante: ' . fmt($total_sobrante, 2)), 0, 1, 'L');
    }
    $pdf->Ln(2);
    $pdf->dashedLine($X, $X + $W);
    $pdf->Ln(2);

    // Nota de transparencia — el pago aún no fue aprobado por el admin
    $pdf->SetFont('Helvetica', 'I', 6.3);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell($W, 3, lat('Pago sujeto a aprobacion: este comprobante certifica la entrega del dinero al cobrador, no una confirmacion definitiva del sistema.'), 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->Cell($W, 4, lat('¡Gracias por su pago!'), 0, 1, 'C');

    $pdf->Output('F', $abs_path);

    $upd = $pdo->prepare("UPDATE ic_cupones_pago SET ruta_archivo = ? WHERE id = ?");
    $upd->execute([$rel_path, $cupon_id]);

    return $cupon_id;
}
