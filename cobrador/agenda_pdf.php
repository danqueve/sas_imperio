<?php
// ============================================================
// cobrador/agenda_pdf.php — Ficha semanal de cobros por cobrador
// v2: MOROSO, cuota X/Y, zona, teléfono, parcial, firma, resumen
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo         = obtener_conexion();
$is_cobrador = es_cobrador();
$user_id     = $_SESSION['user_id'];

// ── Parámetros GET ─────────────────────────────────────────────
$cobrador_id = $is_cobrador ? $user_id : (int)($_GET['cobrador_id'] ?? 0);
$dias_sel    = array_map('intval', (array)($_GET['dias'] ?? [1,2,3,4,5,6]));
$dias_sel    = array_filter($dias_sel, fn($d) => $d >= 1 && $d <= 6);
sort($dias_sel);

if (!$cobrador_id) die('Seleccioná un cobrador.');

$cob_stmt = $pdo->prepare("SELECT nombre, apellido, usuario FROM ic_usuarios WHERE id = ?");
$cob_stmt->execute([$cobrador_id]);
$cobrador = $cob_stmt->fetch();
if (!$cobrador) die('Cobrador no encontrado.');

if (empty($dias_sel)) die('Seleccioná al menos un día.');

// ── Consulta semanales (mejora 1: MOROSO; mejora 2: cant_cuotas; mejora 4: zona) ──
$placeholders = implode(',', array_fill(0, count($dias_sel), '?'));
$params = array_merge([$cobrador_id], $dias_sel);

$stmt = $pdo->prepare("
    SELECT cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.direccion, cl.localidad, cl.barrio, cr.dia_cobro,
           cr.id AS credito_id, cr.interes_moratorio_pct, cr.cant_cuotas,
           cr.estado AS credito_estado,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado, cu.monto_mora, cu.saldo_pagado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id = cu.id AND pt.estado IN ('PENDIENTE','APROBADO')) AS pago_pen,
           (SELECT pt3.fecha_jornada
            FROM ic_pagos_confirmados pc3
            JOIN ic_pagos_temporales pt3 ON pt3.id = pc3.pago_temp_id
            JOIN ic_cuotas cu3            ON cu3.id = pc3.cuota_id
            WHERE cu3.credito_id = cr.id
            ORDER BY pt3.fecha_jornada DESC, pc3.id DESC LIMIT 1) AS ultimo_pago_fecha,
           (SELECT pc3.monto_total
            FROM ic_pagos_confirmados pc3
            JOIN ic_pagos_temporales pt3 ON pt3.id = pc3.pago_temp_id
            JOIN ic_cuotas cu3            ON cu3.id = pc3.cuota_id
            WHERE cu3.credito_id = cr.id
            ORDER BY pt3.fecha_jornada DESC, pc3.id DESC LIMIT 1) AS ultimo_pago_monto
    FROM ic_clientes cl
    JOIN ic_creditos cr  ON cr.cliente_id = cl.id
                        AND cr.cobrador_id = ?
                        AND cr.estado IN ('EN_CURSO','MOROSO')
                        AND cr.frecuencia = 'semanal'
    JOIN ic_cuotas  cu   ON cu.credito_id = cr.id
                        AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    LEFT JOIN (
        SELECT credito_id
        FROM ic_cuotas
        WHERE fecha_vencimiento < CURDATE()
          AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
        GROUP BY credito_id
        HAVING COUNT(*) >= 5
    ) filtro ON filtro.credito_id = cr.id
    WHERE cr.dia_cobro IN ($placeholders)
      AND filtro.credito_id IS NULL
    ORDER BY cr.dia_cobro ASC, COALESCE(cl.zona,'') ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agrupar por dia_cobro + crédito — TODAS las cuotas impagas de cada
// cliente quedan juntas en '_cuotas' (antes se descartaba todo menos
// la más vieja). El primer registro visto de cada grupo ya es el más
// viejo gracias al ORDER BY ... cu.fecha_vencimiento ASC de la query.
$por_dia = [];
foreach ($dias_sel as $d) $por_dia[$d] = [];
$grupos = [];
foreach ($rows as $r) {
    $clave = $r['dia_cobro'] . '-' . $r['credito_id'];
    if (!isset($grupos[$clave])) {
        $grupos[$clave] = $r;
        $grupos[$clave]['_cuotas'] = [];
    }
    $grupos[$clave]['_cuotas'][] = $r;
}
foreach ($grupos as $g) {
    $por_dia[$g['dia_cobro']][] = $g;
}
// Ordenar cada día alfabéticamente por zona + apellidos. Normalizado a
// mayúsculas antes de comparar: la query SQL ya agrupa "Norte"/"norte"
// como la misma zona (collation case-insensitive), pero strcmp() es
// case-sensitive a nivel de byte y deshacía ese agrupamiento.
foreach ($dias_sel as $d) {
    usort($por_dia[$d], fn($a, $b) =>
        strcmp(
            mb_strtoupper(($a['zona'] ?? '') . $a['apellidos']),
            mb_strtoupper(($b['zona'] ?? '') . $b['apellidos'])
        )
    );
}

// ── Helpers ─────────────────────────────────────────────────────
function fmt(float $v): string {
    return '$ ' . number_format($v, 2, ',', '.');
}

// Agrega las cuotas impagas de un mismo cliente/crédito: TODAS las que
// ya están atrasadas ("atraso", con mora) + la PRÓXIMA a vencer que
// todavía está en fecha ("fijo", sin mora) — y se corta ahí. Un crédito
// quincenal/mensual genera todas sus cuotas por adelantado, así que sin
// este corte se sumarían también cuotas de meses futuros que todavía no
// corresponde cobrar. $cuotas ya viene ordenada por fecha_vencimiento
// ASC (mismo ORDER BY de las 2 queries que alimentan esta función), así
// que todas las atrasadas quedan siempre antes que la primera en fecha.
function calcularGrupo(array $cuotas): array
{
    $cuotas_atrasadas = 0;
    $monto_fijo   = 0.0;
    $monto_atraso = 0.0;
    foreach ($cuotas as $cu) {
        $saldo_p = (float) ($cu['saldo_pagado'] ?? 0);
        $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
        if ($dias_atraso > 0) {
            $cuotas_atrasadas++;
            $mora_db = (float) $cu['monto_mora'];
            $mora = $mora_db > 0
                ? $mora_db
                : calcular_mora((float) $cu['monto_cuota'], $dias_atraso, (float) $cu['interes_moratorio_pct']);
            $monto_atraso += ($cu['cuota_estado'] === 'CAP_PAGADA')
                ? $mora
                : max(0, (float) $cu['monto_cuota'] + $mora - $saldo_p);
        } else {
            $monto_fijo = max(0, (float) $cu['monto_cuota'] - $saldo_p);
            break;
        }
    }
    return [
        'cuotas_atrasadas' => $cuotas_atrasadas,
        'monto_fijo'       => $monto_fijo,
        'monto_atraso'     => $monto_atraso,
        'monto_total'      => $monto_fijo + $monto_atraso,
    ];
}

require_once __DIR__ . '/../lib/PDFBase.php';

// Anchos columnas = 277mm total (A4 landscape 297mm − 10mm izq − 10mm der).
// Ult. Pago se agrego junto a Vencim. (ambas son fechas) — se le hizo lugar
// recortando un poco a Direccion/Localidad/Barrio/Telefono/V.Cuota/Cobrar,
// que ya tenian margen de sobra tras el redimensionado anterior.
// #(6) + Cliente(42) + Telefono(18) + Vencim.(13) + Ult.Pago(27) + Direccion(40)
// + Localidad(16) + Barrio(18) + Articulo(22) + Cuota#(12) + V. Cuota(20)
// + C. A.(11) + Cobrar(32) = 277
$COLS   = [6, 42, 18, 13, 27, 40, 16, 18, 22, 12, 20, 11, 32];
$LABELS = ['#', 'Cliente', 'Telefono', 'Vencim.', 'Ult. Pago', 'Direccion', 'Localidad', 'Barrio',
           'Articulo', 'Cuota #', 'V. Cuota', 'C. A.', 'Cobrar'];
$ALIGNS = ['C', 'L', 'L', 'C', 'L', 'L', 'L', 'L', 'L', 'C', 'R', 'C', 'R'];

class AgendaPDF extends PDFBase
{
    public string $cobrador_nombre = '';
    public array  $cols   = [];
    public array  $labels = [];
    public array  $aligns = [];

    // Encabezado de tabla reutilizable
    function encabezadoTabla()
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], false);
        }
        $this->Ln();
        $this->SetFont('Helvetica', '', 7);
    }

    // Mejora 4: encabezado de zona entre grupos
    function zonaHeader(string $zona)
    {
        $this->SetFont('Helvetica', 'BI', 7);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(array_sum($this->cols), 5, lat('  Zona: ' . strtoupper($zona)), 0, 1, 'L', false);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 7);
    }

    // Fila de una sola línea — el teléfono y el monto ya tienen columna
    // propia, no hace falta una segunda línea superpuesta como antes.
    function drawRow(
        array  $r,
        string $cuota_label,
        string $venc,
        float  $valor_cuota,
        int    $cuotas_atrasadas,
        float  $monto_total,
        int    $num = 0
    ): void {
        $cols  = $this->cols;
        $x0    = $this->GetX();
        $y0    = $this->GetY();
        $row_h = 6;

        $es_moroso = ($r['credito_estado'] ?? '') === 'MOROSO';

        // El prefijo [M] y el sufijo * se arman ANTES de truncar, para que
        // fitText() recorte el texto final completo y no se desborde la
        // columna cuando se suman a un nombre que ya estaba justo al límite.
        $cliente_name = $r['apellidos'] . ', ' . $r['nombres'];
        if ($es_moroso) $cliente_name = '[M] ' . $cliente_name;
        if ((int) ($r['pago_pen'] ?? 0) > 0) $cliente_name .= ' *';
        $cliente_name = $this->fitText($cliente_name, $cols[1] - 2);

        // Bordes vacíos — el texto se superpone centrado verticalmente.
        // Orden: # / Cliente / Telefono / Vencim. / Ult. Pago / Direccion /
        // Localidad / Barrio / Articulo / Cuota# / V. Cuota / C. A. / Cobrar
        foreach ($cols as $i => $w) {
            $texto = ($i === 0 && $num > 0) ? (string) $num : '';
            $this->Cell($w, $row_h, $texto, 1, 0, $this->aligns[$i], false);
        }
        $this->Ln();

        $y_centro = $y0 + ($row_h - 3.5) / 2;
        $x = $x0 + $cols[0];

        // Cliente
        $this->SetFont('Helvetica', $es_moroso ? 'B' : '', 7);
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[1] - 1, 4, $cliente_name, 0, 0, 'L', false);
        $x += $cols[1];

        // Teléfono
        $this->SetFont('Helvetica', 'I', 6.5);
        $this->SetTextColor(80, 80, 80);
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[2] - 1, 4, $this->fitText(trim($r['telefono'] ?? '') ?: '-', $cols[2] - 2), 0, 0, 'L', false);
        $this->SetTextColor(0, 0, 0);
        $x += $cols[2];

        // Vencimiento
        $this->SetFont('Helvetica', '', 7);
        $this->SetXY($x, $y_centro);
        $this->Cell($cols[3], 4, $venc, 0, 0, 'C', false);
        $x += $cols[3];

        // Último Pago (fecha + monto del último pago confirmado de este crédito)
        $this->SetFont('Helvetica', 'I', 6);
        $this->SetTextColor(80, 80, 80);
        $ult_fecha = $r['ultimo_pago_fecha'] ?? null;
        $ult_txt   = $ult_fecha
            ? date('d/m', strtotime($ult_fecha)) . ' $' . number_format((float) ($r['ultimo_pago_monto'] ?? 0), 0, ',', '.')
            : 'Sin pagos';
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[4] - 1, 4, $this->fitText($ult_txt, $cols[4] - 2), 0, 0, 'L', false);
        $this->SetTextColor(0, 0, 0);
        $x += $cols[4];

        // Dirección
        $this->SetFont('Helvetica', 'I', 6);
        $this->SetTextColor(80, 80, 80);
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[5] - 1, 4, $this->fitText(trim($r['direccion'] ?? '') ?: '-', $cols[5] - 2), 0, 0, 'L', false);
        $x += $cols[5];

        // Localidad
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[6] - 1, 4, $this->fitText(trim($r['localidad'] ?? '') ?: '—', $cols[6] - 2), 0, 0, 'L', false);
        $x += $cols[6];

        // Barrio
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[7] - 1, 4, $this->fitText(trim($r['barrio'] ?? '') ?: '—', $cols[7] - 2), 0, 0, 'L', false);
        $this->SetTextColor(0, 0, 0);
        $x += $cols[7];

        // Artículo
        $this->SetFont('Helvetica', '', 7);
        $this->SetXY($x + 0.8, $y_centro);
        $this->Cell($cols[8] - 1, 4, $this->fitText($r['articulo'] ?? '-', $cols[8] - 2), 0, 0, 'L', false);
        $x += $cols[8];

        // Cuota #
        $this->SetXY($x, $y_centro);
        $this->Cell($cols[9], 4, lat($cuota_label), 0, 0, 'C', false);
        $x += $cols[9];

        // Valor de Cuota (nominal, sin mora)
        $this->SetXY($x + 0.5, $y_centro);
        $this->Cell($cols[10] - 1, 4, lat(fmt($valor_cuota)), 0, 0, 'R', false);
        $x += $cols[10];

        // Cuotas Atrasadas — resaltado si hay
        $this->SetFont('Helvetica', $cuotas_atrasadas > 0 ? 'B' : '', 7);
        if ($cuotas_atrasadas > 0) $this->SetTextColor(200, 80, 0);
        $this->SetXY($x, $y_centro);
        $this->Cell($cols[11], 4, (string) $cuotas_atrasadas, 0, 0, 'C', false);
        $this->SetTextColor(0, 0, 0);
        $x += $cols[11];

        // Monto Total a Cobrar (cuota en fecha + todo el atraso, con mora)
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetXY($x + 0.5, $y_centro);
        $this->Cell($cols[12] - 1, 4, lat(fmt($monto_total)), 0, 0, 'R', false);

        // Restablecer cursor al inicio de la siguiente fila
        $this->SetXY(10, $y0 + $row_h);
        $this->SetFont('Helvetica', '', 7);
    }
}

$pdf = new AgendaPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->cobrador_nombre = $cobrador['nombre'] . ' ' . $cobrador['apellido'];
$pdf->cols   = $COLS;
$pdf->labels = $LABELS;
$pdf->aligns = $ALIGNS;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

// ── Encabezado del documento ─────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 13);
$pdf->Cell(277, 7, lat('Imperio Comercial - Ficha Semanal de Cobros'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 8);
$pdf->Cell(138.5, 5, lat('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 0, 'L');
$dias_label = implode(', ', array_map(fn($d) => [1=>'Lun',2=>'Mar',3=>'Mie',4=>'Jue',5=>'Vie',6=>'Sab'][$d], $dias_sel));
$pdf->Cell(138.5, 5, lat('Dias: ' . $dias_label . '   |   Emision: ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetLineWidth(0.4);
$pdf->Line(10, $pdf->GetY() + 1, 287, $pdf->GetY() + 1);
$pdf->Ln(5);

// Mejora 5: coleccionar datos para resumen
$resumen = [];

// ── Renderiza un bloque de clientes (un día, o la Agenda General combinada) ──
// Header + total, tabla, agrupado por zona con paginación, fila TOTAL, y un
// registro en $resumen. Se llama una vez por día (comportamiento de siempre)
// o una sola vez con todos los clientes juntos (Agenda General, ver abajo).
function renderBloqueSemanal(AgendaPDF $pdf, array $COLS, string $titulo, array $clientes, array &$resumen, bool &$hay_pago_pendiente): void
{
    if (empty($clientes)) return;

    // Pre-calcular total del bloque, separado en fijo (en fecha) y atraso
    $total_fijo   = 0.0;
    $total_atraso = 0.0;
    foreach ($clientes as $r) {
        $g = calcularGrupo($r['_cuotas']);
        $total_fijo   += $g['monto_fijo'];
        $total_atraso += $g['monto_atraso'];
    }
    $total_dia = $total_fijo + $total_atraso;

    $cant = count($clientes);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(277, 7, lat($titulo . ' — ' . $cant . ' cliente(s)'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(277, 5, lat('Cuotas semanales a cobrar: ' . fmt($total_fijo) . '   |   Cobrando atrasos: ' . fmt($total_atraso) . '   |   Total: ' . fmt($total_dia)), 0, 1, 'R');

    $pdf->encabezadoTabla();
    $pdf->SetFont('Helvetica', '', 7);

    $zona_actual    = null;
    $num            = 0;
    $reprint_zona   = false;

    foreach ($clientes as $r) {
        // Salto de página si hace falta (zona header ~5mm + fila de 6mm)
        if ($pdf->GetY() + 13 > $pdf->GetPageHeight() - 18) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(277, 6, lat($titulo . ' (continuacion)'), 0, 1, 'L');
            $pdf->encabezadoTabla();
            $pdf->SetFont('Helvetica', '', 7);
            $reprint_zona = true;
        }

        // Encabezado de zona: resetear num solo cuando la zona realmente cambia
        $zona_fila = mb_strtoupper(trim($r['zona'] ?? ''));
        if ($zona_fila !== $zona_actual) {
            $zona_actual  = $zona_fila;
            $num          = 0;
            $reprint_zona = false;
            if (!empty($zona_fila)) {
                $pdf->zonaHeader($zona_fila);
            }
        } elseif ($reprint_zona) {
            $reprint_zona = false;
            if (!empty($zona_fila)) {
                $pdf->zonaHeader($zona_fila);
            }
        }

        $num++;
        if ((int)($r['pago_pen'] ?? 0) > 0) $hay_pago_pendiente = true;

        // Mejora 2: cuota X/Y (la más vieja del grupo)
        $cuota_label = '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'];
        $venc = date('d/m', strtotime($r['fecha_vencimiento']));

        $g = calcularGrupo($r['_cuotas']);
        $pdf->drawRow($r, $cuota_label, $venc, (float)$r['monto_cuota'], $g['cuotas_atrasadas'], $g['monto_total'], $num);
    }

    // Fila total del bloque — Monto es la última columna
    $pdf->SetFont('Helvetica', 'B', 7);
    $ancho = array_sum(array_slice($COLS, 0, 12));
    $pdf->Cell($ancho, 6, lat('TOTAL ' . strtoupper($titulo)), 1, 0, 'R', false);
    $pdf->Cell($COLS[12], 6, fmt($total_dia), 1, 0, 'R', false);
    $pdf->Ln();
    $pdf->Ln(3);

    $resumen[] = ['tipo' => 'dia', 'label' => $titulo, 'cant' => $cant, 'fijo' => $total_fijo, 'atraso' => $total_atraso, 'total' => $total_dia];
}

// ── Una sección por día, o "Agenda General" combinada para los cobradores
//    que solo trabajan Lunes-Miércoles — sin importar qué días queden
//    tildados en el modal (los checkboxes vienen todos tildados por
//    defecto, así que basarse en los días elegidos no alcanza) ───────────
$USUARIOS_LUNES_MIERCOLES = ['TURCOCOBRANZA', 'sebadelga', 'davbrizuela'];
$hay_pago_pendiente = false;
$es_agenda_general  = in_array($cobrador['usuario'], $USUARIOS_LUNES_MIERCOLES, true);

if ($es_agenda_general) {
    $clientes_general = [];
    foreach ($dias_sel as $d) {
        $clientes_general = array_merge($clientes_general, $por_dia[$d] ?? []);
    }
    // Re-ordenar el combinado por zona+apellidos: cada $por_dia[$d] ya viene
    // ordenado individualmente, pero concatenar 2-3 arrays ya ordenados NO
    // da un combinado ordenado — sin este paso se repetiría el mismo bug de
    // "Zona: X" duplicada que se corrigió al principio de esta sesión.
    usort($clientes_general, fn($a, $b) =>
        strcmp(
            mb_strtoupper(($a['zona'] ?? '') . $a['apellidos']),
            mb_strtoupper(($b['zona'] ?? '') . $b['apellidos'])
        )
    );
    renderBloqueSemanal($pdf, $COLS, 'Agenda General', $clientes_general, $resumen, $hay_pago_pendiente);
} else {
    foreach ($dias_sel as $dia) {
        $nombre_dia = [1=>'Lunes',2=>'Martes',3=>'Miercoles',4=>'Jueves',5=>'Viernes',6=>'Sabado'][$dia];
        renderBloqueSemanal($pdf, $COLS, $nombre_dia, $por_dia[$dia] ?? [], $resumen, $hay_pago_pendiente);
    }
}

// ── Sección: Quincenales y Mensuales ────────────────────────────
$stmt_qm = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.direccion, cl.localidad, cl.barrio,
           cr.id AS credito_id, cr.frecuencia, cr.cant_cuotas, cr.estado AS credito_estado,
           cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota, cu.estado AS cuota_estado,
           cu.monto_mora, cu.saldo_pagado,
           cr.interes_moratorio_pct,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id = cu.id AND pt.estado IN ('PENDIENTE','APROBADO')) AS pago_pen,
           (SELECT pt3.fecha_jornada
            FROM ic_pagos_confirmados pc3
            JOIN ic_pagos_temporales pt3 ON pt3.id = pc3.pago_temp_id
            JOIN ic_cuotas cu3            ON cu3.id = pc3.cuota_id
            WHERE cu3.credito_id = cr.id
            ORDER BY pt3.fecha_jornada DESC, pc3.id DESC LIMIT 1) AS ultimo_pago_fecha,
           (SELECT pc3.monto_total
            FROM ic_pagos_confirmados pc3
            JOIN ic_pagos_temporales pt3 ON pt3.id = pc3.pago_temp_id
            JOIN ic_cuotas cu3            ON cu3.id = pc3.cuota_id
            WHERE cu3.credito_id = cr.id
            ORDER BY pt3.fecha_jornada DESC, pc3.id DESC LIMIT 1) AS ultimo_pago_monto
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    LEFT JOIN (
        SELECT credito_id
        FROM ic_cuotas
        WHERE fecha_vencimiento < CURDATE()
          AND estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
        GROUP BY credito_id
        HAVING COUNT(*) >= 5
    ) filtro ON filtro.credito_id = cr.id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
      AND cr.frecuencia IN ('diario', 'quincenal', 'mensual')
      AND cu.estado IN ('PENDIENTE', 'VENCIDA', 'CAP_PAGADA', 'PARCIAL')
      AND filtro.credito_id IS NULL
    ORDER BY cr.frecuencia ASC, COALESCE(cl.zona,'') ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$stmt_qm->execute([$cobrador_id]);
$rows_qm = $stmt_qm->fetchAll();

if (!empty($rows_qm)) {
    // Agrupar por frecuencia + crédito — TODAS las cuotas impagas de
    // cada cliente quedan juntas en '_cuotas' (mismo criterio que la
    // Sección A más arriba).
    $qm_aux = ['diario' => [], 'quincenal' => [], 'mensual' => []];
    foreach ($rows_qm as $r) {
        $key  = $r['credito_id'];
        $frec = $r['frecuencia'];
        if (!isset($qm_aux[$frec][$key])) {
            $qm_aux[$frec][$key] = $r;
            $qm_aux[$frec][$key]['_cuotas'] = [];
        }
        $qm_aux[$frec][$key]['_cuotas'][] = $r;
    }
    $qm_grupos = [
        'diario'    => array_values($qm_aux['diario']),
        'quincenal' => array_values($qm_aux['quincenal']),
        'mensual'   => array_values($qm_aux['mensual']),
    ];

    // Ordenar alfabéticamente por zona + apellidos dentro de cada grupo
    // (normalizado a mayúsculas — ver comentario del mismo fix más arriba)
    foreach ($qm_grupos as $frec => &$lista) {
        usort($lista, fn($a, $b) =>
            strcmp(
                mb_strtoupper(($a['zona'] ?? '') . $a['apellidos']),
                mb_strtoupper(($b['zona'] ?? '') . $b['apellidos'])
            )
        );
    }
    unset($lista);

    foreach ($qm_grupos as $frec => $lista) {
        if (empty($lista)) continue;

        $titulo = match($frec) { 'diario' => 'Diarios', 'quincenal' => 'Quincenales', default => 'Mensuales' };

        $total_frec_fijo   = 0.0;
        $total_frec_atraso = 0.0;
        foreach ($lista as $r) {
            $g = calcularGrupo($r['_cuotas']);
            $total_frec_fijo   += $g['monto_fijo'];
            $total_frec_atraso += $g['monto_atraso'];
        }
        $total_frec = $total_frec_fijo + $total_frec_atraso;

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(277, 7, lat($titulo . ' — ' . count($lista) . ' cliente(s)'), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(277, 5, lat('Cuotas al dia a cobrar: ' . fmt($total_frec_fijo) . '   |   Cobrando atrasos: ' . fmt($total_frec_atraso) . '   |   Total: ' . fmt($total_frec)), 0, 1, 'R');

        $pdf->encabezadoTabla();
        $pdf->SetFont('Helvetica', '', 7);

        $zona_actual  = null;
        $num          = 0;
        $reprint_zona = false;

        foreach ($lista as $r) {
            if ($pdf->GetY() + 13 > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(277, 6, lat($titulo . ' (continuacion)'), 0, 1, 'L');
                $pdf->encabezadoTabla();
                $pdf->SetFont('Helvetica', '', 7);
                $reprint_zona = true;
            }

            // Encabezado de zona: resetear num solo cuando la zona realmente cambia
            $zona_fila = mb_strtoupper(trim($r['zona'] ?? ''));
            if ($zona_fila !== $zona_actual) {
                $zona_actual  = $zona_fila;
                $num          = 0;
                $reprint_zona = false;
                if (!empty($zona_fila)) {
                    $pdf->zonaHeader($zona_fila);
                }
            } elseif ($reprint_zona) {
                $reprint_zona = false;
                if (!empty($zona_fila)) {
                    $pdf->zonaHeader($zona_fila);
                }
            }

            $num++;
            if ((int)($r['pago_pen'] ?? 0) > 0) $hay_pago_pendiente = true;

            $cuota_label = '#' . $r['numero_cuota'] . '/' . $r['cant_cuotas'];
            $venc = date('d/m/y', strtotime($r['fecha_vencimiento']));

            $g = calcularGrupo($r['_cuotas']);
            $pdf->drawRow($r, $cuota_label, $venc, (float)$r['monto_cuota'], $g['cuotas_atrasadas'], $g['monto_total'], $num);
        }

        // Fila total de frecuencia — Monto es la última columna
        $pdf->SetFont('Helvetica', 'B', 7);
        $ancho = array_sum(array_slice($COLS, 0, 12));
        $pdf->Cell($ancho, 6, lat('TOTAL ' . strtoupper($titulo)), 1, 0, 'R', false);
        $pdf->Cell($COLS[12], 6, fmt($total_frec), 1, 0, 'R', false);
        $pdf->Ln();
        $pdf->Ln(3);

        $resumen[] = ['tipo' => 'frec', 'label' => $titulo, 'cant' => count($lista), 'fijo' => $total_frec_fijo, 'atraso' => $total_frec_atraso, 'total' => $total_frec];
    }
}

// ── Sección: Clientes con 5+ cuotas atrasadas ───────────────────
$stmt_atr = $pdo->prepare("
    SELECT cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cl.zona,'') AS zona, cl.localidad, cl.barrio,
           cr.id AS credito_id, cr.cant_cuotas, cr.frecuencia,
           cr.estado AS credito_estado, cr.interes_moratorio_pct,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           COUNT(cu.id)        AS cuotas_atrasadas,
           MIN(cu.monto_cuota) AS valor_cuota,
           SUM(cu.monto_cuota) AS monto_base,
           (SELECT MAX(pt.fecha_jornada)
            FROM ic_pagos_confirmados pc
            JOIN ic_pagos_temporales pt ON pt.id = pc.pago_temp_id
            JOIN ic_cuotas cu2          ON cu2.id = pc.cuota_id
            WHERE cu2.credito_id = cr.id) AS ultimo_pago
    FROM ic_creditos cr
    JOIN ic_clientes cl  ON cl.id  = cr.cliente_id
    JOIN ic_cuotas   cu  ON cu.credito_id = cr.id
                        AND cu.fecha_vencimiento < CURDATE()
                        AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
    WHERE cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
    GROUP BY cr.id, cl.id, cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.localidad, cl.barrio,
             cr.cant_cuotas, cr.frecuencia, cr.estado, cr.interes_moratorio_pct, articulo
    HAVING COUNT(cu.id) >= 5
    ORDER BY COALESCE(cl.zona,'') ASC, cl.apellidos ASC
");
$stmt_atr->execute([$cobrador_id]);
$rows_atr = $stmt_atr->fetchAll();

if (!empty($rows_atr)) {
    // Agrupar por zona (normalizado: "Norte"/"norte" deben ser el mismo grupo)
    $atr_por_zona = [];
    foreach ($rows_atr as $r) {
        $zona_key = mb_strtoupper(trim($r['zona'] ?? ''));
        $atr_por_zona[$zona_key][] = $r;
    }

    $pdf->AddPage();

    // Encabezado sección
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(277, 7, lat('Clientes con 5 o mas cuotas atrasadas'), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(138.5, 5, lat('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 0, 'L');
    $pdf->Cell(138.5, 5, lat('Emision: ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->SetLineWidth(0.4);
    $pdf->Line(10, $pdf->GetY() + 1, 287, $pdf->GetY() + 1);
    $pdf->Ln(5);

    // Columnas: #(8) + Cliente/Tel.(54) + Localidad(30) + Barrio(36) + Articulo(32) + Adeud.(18) + Valor cuota(28) + Ult.Pago(38) + Total(33) = 277
    // Total queda al final — es el monto que importa escanear rápido, mismo criterio que Sección A/B.
    $CA = [8, 54, 30, 36, 32, 18, 28, 38, 33];
    $LA = ['#', 'Cliente / Tel.', 'Localidad', 'Barrio', 'Articulo', 'Adeud.', 'Valor cuota', 'Ult. Pago', 'Total'];

    $zona_actual = null;

    foreach ($atr_por_zona as $zona => $lista) {
        // Encabezado de zona
        $pdf->SetFont('Helvetica', 'BI', 8);
        $pdf->SetFillColor(240, 240, 240);
        $zona_txt = !empty($zona) ? strtoupper($zona) : 'SIN ZONA';
        $pdf->Cell(277, 6, lat('  Zona: ' . $zona_txt . '  (' . count($lista) . ' credito(s))'), 1, 1, 'L', true);
        $pdf->SetFillColor(255, 255, 255);

        // Encabezado columnas
        $pdf->SetFont('Helvetica', 'B', 7);
        foreach ($CA as $i => $w) {
            $pdf->Cell($w, 5, lat($LA[$i]), 1, 0, 'L');
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 7);
        $total_zona = 0.0;
        $num        = 0;

        foreach ($lista as $r) {
            // Salto de página si hace falta
            if ($pdf->GetY() + 11 > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage();
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(277, 6, lat('Clientes con 5+ cuotas atrasadas (continuacion)'), 0, 1, 'L');
                $pdf->SetFont('Helvetica', 'B', 7);
                foreach ($CA as $i => $w) {
                    $pdf->Cell($w, 5, lat($LA[$i]), 1, 0, 'L');
                }
                $pdf->Ln();
                $pdf->SetFont('Helvetica', '', 7);
            }

            $num++;
            $has_phone = !empty(trim($r['telefono'] ?? ''));
            $row_h     = 9;
            $x0 = $pdf->GetX();
            $y0 = $pdf->GetY();

            $es_moroso    = $r['credito_estado'] === 'MOROSO';
            $cliente_name = mb_strimwidth($r['apellidos'] . ', ' . $r['nombres'], 0, 31, '..');
            if ($es_moroso) $cliente_name = '[M] ' . $cliente_name;
            $articulo    = mb_strimwidth($r['articulo'] ?? '-', 0, 26, '..');
            $valor_cuota = (float)$r['valor_cuota'];
            $monto_total = (float)$r['monto_base'];
            $total_zona += $monto_total;

            // Celdas con borde. Orden: # / Cliente/Tel. / Localidad / Barrio / Articulo / Adeud. / Valor cuota / Ult.Pago / Total
            $pdf->Cell($CA[0], $row_h, (string)$num, 1, 0, 'C', false);    // número
            $pdf->Cell($CA[1], $row_h, '', 1, 0, 'L', false);              // cliente (borde)
            $pdf->SetFont('Helvetica', 'I', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell($CA[2], $row_h, $pdf->fitText(trim($r['localidad'] ?? '') ?: '—', $CA[2] - 2), 1, 0, 'L', false); // localidad
            $pdf->Cell($CA[3], $row_h, $pdf->fitText(trim($r['barrio'] ?? '') ?: '—', $CA[3] - 2), 1, 0, 'L', false);    // barrio
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->Cell($CA[4], $row_h, lat($articulo), 1, 0, 'L', false);
            $pdf->Cell($CA[5], $row_h, '', 1, 0, 'C', false);              // adeud (borde)
            $pdf->Cell($CA[6], $row_h, lat(fmt($valor_cuota)), 1, 0, 'R', false);
            $pdf->Cell($CA[7], $row_h, '', 1, 0, 'L', false);              // ult. pago (borde)
            $pdf->Cell($CA[8], $row_h, '', 1, 0, 'R', false);              // total (borde)
            $pdf->Ln();

            // Texto cliente — línea 1
            $pdf->SetFont('Helvetica', $es_moroso ? 'B' : '', 7);
            $pdf->SetXY($x0 + $CA[0] + 0.8, $y0 + 0.8);
            $pdf->Cell($CA[1] - 1, 4, lat($cliente_name), 0, 0, 'L', false);

            // Texto cliente — línea 2 (teléfono)
            if ($has_phone) {
                $pdf->SetFont('Helvetica', 'I', 6);
                $pdf->SetTextColor(80, 80, 80);
                $pdf->SetXY($x0 + $CA[0] + 0.8, $y0 + 4.5);
                $pdf->Cell($CA[1] - 1, 3.5, lat('Tel: ' . mb_strimwidth($r['telefono'], 0, 22, '')), 0, 0, 'L', false);
                $pdf->SetTextColor(0, 0, 0);
            }

            // Cuotas adeudadas — línea 1: número destacado
            $cx = $x0 + $CA[0] + $CA[1] + $CA[2] + $CA[3] + $CA[4];
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetXY($cx + 0.5, $y0 + 0.8);
            $pdf->Cell($CA[5] - 1, 4, (string)$r['cuotas_atrasadas'], 0, 0, 'C', false);

            // Cuotas adeudadas — línea 2: "de N" en pequeño gris
            $pdf->SetFont('Helvetica', 'I', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($cx + 0.5, $y0 + 4.5);
            $pdf->Cell($CA[5] - 1, 3.5, lat('de ' . $r['cant_cuotas']), 0, 0, 'C', false);
            $pdf->SetTextColor(0, 0, 0);

            // Último pago
            $ult_txt = !empty($r['ultimo_pago'])
                ? date('d/m/y', strtotime($r['ultimo_pago']))
                : 'Sin pagos';
            $ux = $x0 + $CA[0] + $CA[1] + $CA[2] + $CA[3] + $CA[4] + $CA[5] + $CA[6];
            $pdf->SetFont('Helvetica', empty($r['ultimo_pago']) ? 'I' : '', 6.5);
            if (empty($r['ultimo_pago'])) $pdf->SetTextColor(150, 80, 80);
            $pdf->SetXY($ux + 0.5, $y0 + 2.5);
            $pdf->Cell($CA[7] - 1, 4, lat($ult_txt), 0, 0, 'C', false);
            $pdf->SetTextColor(0, 0, 0);

            // Total — última columna
            $pdf->SetFont('Helvetica', '', 7);
            $mx = $ux + $CA[7];
            $pdf->SetXY($mx + 0.5, $y0 + 1.5);
            $pdf->Cell($CA[8] - 1, 4, lat(fmt($monto_total)), 0, 0, 'R', false);

            $pdf->SetXY(10, $y0 + $row_h);
            $pdf->SetFont('Helvetica', '', 7);
        }

        // Total zona — Total es la última columna
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell(array_sum(array_slice($CA, 0, 8)), 5, lat('Total zona'), 1, 0, 'R');
        $pdf->Cell($CA[8], 5, lat(fmt($total_zona)), 1, 1, 'R');
        $pdf->Ln(3);
    }

    // Total general sección — Total es la última columna
    $total_atr = array_sum(array_map(fn($r) => (float)$r['monto_base'], $rows_atr));
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(array_sum(array_slice($CA, 0, 8)), 6, lat('TOTAL GENERAL — ' . count($rows_atr) . ' credito(s)'), 1, 0, 'R');
    $pdf->Cell($CA[8], 6, lat(fmt($total_atr)), 1, 1, 'R');
}

// ── Resumen general al final ─────────────────────────────────────
if (!empty($resumen)) {
    $pdf->Ln(2);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(10, $pdf->GetY(), 287, $pdf->GetY());
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(277, 7, lat('Resumen General'), 0, 1, 'L');

    $dias_res = array_values(array_filter($resumen, fn($r) => $r['tipo'] === 'dia'));
    $frec_res = array_values(array_filter($resumen, fn($r) => $r['tipo'] === 'frec'));

    // Columnas: Dia/Frecuencia(90) + Cuotas(40) + Cuotas Fijas(50) + Cuotas con Atraso(50) + Total(47) = 277
    $RCOLS = [90, 40, 50, 50, 47];

    $total_gral_cant   = 0;
    $total_gral_fijo   = 0.0;
    $total_gral_atraso = 0.0;

    // ── Grupo Semanales (Lun–Sáb) ──────────────────────────────
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(277, 6, lat('  Semanales'), 1, 1, 'L', true);
    $pdf->SetFillColor(255, 255, 255);

    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($RCOLS[0], 5, lat('Dia'), 1, 0, 'L');
    $pdf->Cell($RCOLS[1], 5, lat('Cuotas'), 1, 0, 'C');
    $pdf->Cell($RCOLS[2], 5, lat('Cuotas Fijas'), 1, 0, 'R');
    $pdf->Cell($RCOLS[3], 5, lat('Cuotas con Atraso'), 1, 0, 'R');
    $pdf->Cell($RCOLS[4], 5, lat('Total'), 1, 1, 'R');

    $sub_cant_dias   = 0;
    $sub_fijo_dias   = 0.0;
    $sub_atraso_dias = 0.0;
    $pdf->SetFont('Helvetica', '', 7);
    foreach ($dias_res as $row) {
        $pdf->Cell($RCOLS[0], 5, lat($row['label']), 1, 0, 'L');
        $pdf->Cell($RCOLS[1], 5, (string)$row['cant'], 1, 0, 'C');
        $pdf->Cell($RCOLS[2], 5, fmt($row['fijo']), 1, 0, 'R');
        $pdf->Cell($RCOLS[3], 5, fmt($row['atraso']), 1, 0, 'R');
        $pdf->Cell($RCOLS[4], 5, fmt($row['total']), 1, 1, 'R');
        $sub_cant_dias   += $row['cant'];
        $sub_fijo_dias   += $row['fijo'];
        $sub_atraso_dias += $row['atraso'];
    }
    $sub_monto_dias = $sub_fijo_dias + $sub_atraso_dias;
    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->Cell($RCOLS[0], 5, lat('Subtotal Semanales'), 1, 0, 'R');
    $pdf->Cell($RCOLS[1], 5, (string)$sub_cant_dias, 1, 0, 'C');
    $pdf->Cell($RCOLS[2], 5, fmt($sub_fijo_dias), 1, 0, 'R');
    $pdf->Cell($RCOLS[3], 5, fmt($sub_atraso_dias), 1, 0, 'R');
    $pdf->Cell($RCOLS[4], 5, fmt($sub_monto_dias), 1, 1, 'R');
    $total_gral_cant   += $sub_cant_dias;
    $total_gral_fijo   += $sub_fijo_dias;
    $total_gral_atraso += $sub_atraso_dias;

    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(277, 4, lat('Nota: excluye Criticos (5+ atrasadas, ver seccion aparte) - incluye TODAS las cuotas impagas de cada cliente. Cuotas Fijas = vencen en fecha, sin mora. Cuotas con Atraso = ya vencidas, con mora. Puede diferir del Monto Estimado de la Rendicion.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // ── Grupo Quincenales y Mensuales ───────────────────────────
    if (!empty($frec_res)) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(277, 6, lat('  Quincenales / Mensuales'), 1, 1, 'L', true);
        $pdf->SetFillColor(255, 255, 255);

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($RCOLS[0], 5, lat('Frecuencia'), 1, 0, 'L');
        $pdf->Cell($RCOLS[1], 5, lat('Clientes'), 1, 0, 'C');
        $pdf->Cell($RCOLS[2], 5, lat('Cuotas Fijas'), 1, 0, 'R');
        $pdf->Cell($RCOLS[3], 5, lat('Cuotas con Atraso'), 1, 0, 'R');
        $pdf->Cell($RCOLS[4], 5, lat('Total'), 1, 1, 'R');

        $sub_cant_frec   = 0;
        $sub_fijo_frec   = 0.0;
        $sub_atraso_frec = 0.0;
        $pdf->SetFont('Helvetica', '', 7);
        foreach ($frec_res as $row) {
            $pdf->Cell($RCOLS[0], 5, lat($row['label']), 1, 0, 'L');
            $pdf->Cell($RCOLS[1], 5, (string)$row['cant'], 1, 0, 'C');
            $pdf->Cell($RCOLS[2], 5, fmt($row['fijo']), 1, 0, 'R');
            $pdf->Cell($RCOLS[3], 5, fmt($row['atraso']), 1, 0, 'R');
            $pdf->Cell($RCOLS[4], 5, fmt($row['total']), 1, 1, 'R');
            $sub_cant_frec   += $row['cant'];
            $sub_fijo_frec   += $row['fijo'];
            $sub_atraso_frec += $row['atraso'];
        }
        $sub_monto_frec = $sub_fijo_frec + $sub_atraso_frec;
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($RCOLS[0], 5, lat('Subtotal Quinc./Mens.'), 1, 0, 'R');
        $pdf->Cell($RCOLS[1], 5, (string)$sub_cant_frec, 1, 0, 'C');
        $pdf->Cell($RCOLS[2], 5, fmt($sub_fijo_frec), 1, 0, 'R');
        $pdf->Cell($RCOLS[3], 5, fmt($sub_atraso_frec), 1, 0, 'R');
        $pdf->Cell($RCOLS[4], 5, fmt($sub_monto_frec), 1, 1, 'R');
        $total_gral_cant   += $sub_cant_frec;
        $total_gral_fijo   += $sub_fijo_frec;
        $total_gral_atraso += $sub_atraso_frec;
    }

    // ── Total General ───────────────────────────────────────────
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($RCOLS[0], 7, lat('TOTAL GENERAL'), 1, 0, 'R');
    $pdf->Cell($RCOLS[1], 7, (string)$total_gral_cant, 1, 0, 'C');
    $pdf->Cell($RCOLS[2], 7, fmt($total_gral_fijo), 1, 0, 'R');
    $pdf->Cell($RCOLS[3], 7, fmt($total_gral_atraso), 1, 0, 'R');
    $pdf->Cell($RCOLS[4], 7, fmt($total_gral_fijo + $total_gral_atraso), 1, 1, 'R');
}

if ($hay_pago_pendiente) {
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(10);
    $pdf->Cell(277, 4, lat('* = Cuota ya cobrada por el cobrador, pendiente de aprobacion del pago.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$nombre = 'agenda_semanal_' . $cobrador_id . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $nombre);
