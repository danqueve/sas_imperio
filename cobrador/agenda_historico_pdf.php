<?php
// ============================================================
// cobrador/agenda_historico_pdf.php — PDF del Histórico de Agendas
// Mismos datos que cobrador/agenda_historico.php (misma función
// obtener_agenda_historica()) — nunca puede mostrar números distintos
// a los que ya se vieron en pantalla.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
if ($cobrador_id <= 0) {
    die('Elegí un cobrador desde la pantalla de histórico antes de exportar.');
}

$cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ? AND rol = 'cobrador'");
$cs->execute([$cobrador_id]);
$cob = $cs->fetch();
if (!$cob) {
    die('Cobrador no encontrado.');
}
$cobrador_lbl = $cob['apellido'] . ', ' . $cob['nombre'];

$hoy_lunes = calcular_semana_lunes(date('Y-m-d'));
if (!empty($_GET['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana'])) {
    $lunes_str = calcular_semana_lunes($_GET['semana']);
} else {
    $lunes_str = $hoy_lunes;
}
$sabado_str = calcular_semana_sabado($lunes_str);

$agenda = obtener_agenda_historica($pdo, $cobrador_id, $lunes_str, $sabado_str);

// ── Resumen "Semanales": uno solo, sumando los 6 días (ya no se separa
//    por Lunes/Martes/Miercoles en la lista, ver más abajo). "Clientes"
//    NO se puede sumar día por día (un mismo cliente puede tener 2
//    créditos semanales en días distintos, ej. Lunes y Miércoles) — se
//    arma con un set de cliente_id para contarlo una sola vez. ────────
$resumen_semanales = ['clientes' => 0, 'cuotas' => 0, 'pagaron' => 0, 'no_pagaron' => 0, 'ya_pagas' => 0, 'estimado' => 0.0, 'cobrado' => 0.0];
$semanales_flat         = [];
$semanales_clientes_set = [];
foreach ($agenda['semanales'] as $g) {
    $resumen_semanales['cuotas']     += $g['resumen']['cuotas'];
    $resumen_semanales['pagaron']    += $g['resumen']['pagaron'];
    $resumen_semanales['no_pagaron'] += $g['resumen']['no_pagaron'];
    $resumen_semanales['ya_pagas']   += $g['resumen']['ya_pagas'];
    $resumen_semanales['estimado']   += $g['resumen']['estimado'];
    $resumen_semanales['cobrado']    += $g['resumen']['cobrado'];
    foreach ($g['clientes'] as $c) {
        $semanales_flat[] = $c;
        $semanales_clientes_set[$c['cliente_id']] = true;
    }
}
$resumen_semanales['clientes'] = count($semanales_clientes_set);

// Set global de clientes distintos (Semanales + cada frecuencia), para
// el TOTAL del resumen final — se arma ANTES de mezclar los pagos de
// otra semana (más abajo), así solo cuenta clientes con una cuota que
// realmente vencía esta semana. Sumar el 'clientes' de cada categoría
// duplicaría a quien tiene, por ejemplo, un crédito semanal y uno
// mensual venciendo la misma semana.
$tot_clientes_set = $semanales_clientes_set;
foreach ($agenda['otras_frecuencias'] as $g) {
    foreach ($g['clientes'] as $c) {
        $tot_clientes_set[$c['cliente_id']] = true;
    }
}

// ── Pagos de OTRA semana: en vez de una tabla aparte, se insertan
//    dentro de la misma lista a la que corresponde su credito (por
//    frecuencia) — se reparten acá, ANTES de armar las categorías del
//    resumen final, así una frecuencia sin ninguna cuota venciendo esta
//    semana pero con un cobro de backlog/adelanto igual aparece (con
//    Estimado/Cobrado/Pagaron/No Pagaron en 0, que es lo correcto). ──
$FREC_LABELS = ['quincenal' => 'Quincenal', 'mensual' => 'Mensual', 'diario' => 'Diario'];
$pagos_fuera_semana    = $agenda['pagos_fuera_semana'];
$clientes_fuera_semana = $agenda['clientes_pago_fuera_semana'];
$monto_fuera_semana    = 0.0;
foreach ($pagos_fuera_semana as $pf) {
    $monto_fuera_semana += (float) $pf['pagado_esa_semana'];
    if ($pf['frecuencia'] === 'semanal') {
        $semanales_flat[] = $pf;
        continue;
    }
    $fk = $pf['frecuencia'];
    if (!isset($agenda['otras_frecuencias'][$fk])) {
        $agenda['otras_frecuencias'][$fk] = [
            'label'    => $FREC_LABELS[$fk] ?? ucfirst($fk),
            'clientes' => [],
            'resumen'  => ['clientes' => 0, 'cuotas' => 0, 'pagaron' => 0, 'no_pagaron' => 0, 'ya_pagas' => 0, 'estimado' => 0.0, 'cobrado' => 0.0],
        ];
    }
    $agenda['otras_frecuencias'][$fk]['clientes'][] = $pf;
}

// ── Categorías para el resumen final (Semanales + una por frecuencia
//    de otras_frecuencias, ya con los pagos de otra semana repartidos) ──
$categorias = [['label' => 'Semanales', 'resumen' => $resumen_semanales]];
foreach ($agenda['otras_frecuencias'] as $g) {
    $categorias[] = ['label' => $g['label'], 'resumen' => $g['resumen']];
}

// $tot_clientes NO es la suma de "clientes" de cada categoría (eso
// contaría dos veces a quien tiene, ej., un crédito semanal y otro
// mensual venciendo la misma semana) — sale del set global armado
// arriba, antes de mezclar los pagos de otra semana.
$tot_clientes = count($tot_clientes_set);
$tot_cuotas = $tot_pagaron = $tot_no_pagaron = $tot_ya_pagas = 0;
$tot_estimado = $tot_cobrado = 0.0;
foreach ($categorias as $cat) {
    $tot_cuotas     += $cat['resumen']['cuotas'];
    $tot_pagaron    += $cat['resumen']['pagaron'];
    $tot_no_pagaron += $cat['resumen']['no_pagaron'];
    $tot_ya_pagas   += $cat['resumen']['ya_pagas'];
    $tot_estimado   += $cat['resumen']['estimado'];
    $tot_cobrado    += $cat['resumen']['cobrado'];
}

if ($tot_clientes === 0 && empty($pagos_fuera_semana)) {
    die('No hay ninguna cuota venciendo esa semana para ' . $cobrador_lbl . '.');
}

// Una sola lista de Semanales, ordenada por apellido — ya no separada
// en Lunes/Martes/Miercoles (pedido del usuario).
usort($semanales_flat, fn($a, $b) => strcmp(
    mb_strtoupper($a['apellidos'] . $a['nombres']),
    mb_strtoupper($b['apellidos'] . $b['nombres'])
));
// Los grupos de otras_frecuencias también se reordenan por apellido
// (los pagos de otra semana recién agregados quedaban al final).
foreach ($agenda['otras_frecuencias'] as $k => $g) {
    usort($agenda['otras_frecuencias'][$k]['clientes'], fn($a, $b) => strcmp(
        mb_strtoupper($a['apellidos'] . $a['nombres']),
        mb_strtoupper($b['apellidos'] . $b['nombres'])
    ));
}

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas = 190mm (A4 vertical, margenes 10mm c/lado). Sin columna de
// Zona (el reporte ya está acotado a un solo cobrador) y con
// "Atrasadas" (cuotas vencidas hoy de ese mismo credito, aparte de la
// que se muestra en la fila). "Vencim." pasa a ser "Semana" (semana a
// la que pertenece esa cuota) — para una fila normal coincide siempre
// con la semana del reporte; para un cobro de OTRA semana (mezclado en
// esta misma lista) muestra su semana real, resaltado en naranja.
// #(7) + Cliente(29) + Credito/Articulo(36) + Cuota(11) + Semana(22)
// + Atrasadas(12) + Monto(24) + Estado(49) = 190
$COLS   = [7, 29, 36, 11, 22, 12, 24, 49];
$LABELS = ['#', 'Cliente', 'Credito/Articulo', 'Cuota', 'Semana', 'Atrasadas', 'Monto', 'Estado'];
$ALIGNS = ['C', 'L', 'L', 'C', 'C', 'C', 'R', 'L'];

class AgendaHistoricoPDF extends PDFBase
{
    public string $cobrador_lbl  = '';
    public string $rango_lbl     = '';
    public string $fecha_gen     = '';
    public array  $cols          = [];
    public array  $labels        = [];
    public array  $aligns        = [];
    // Título + resumen de la sección en curso, repetidos en el Header()
    // automático de cada salto de página (mid-tabla o al arrancar una
    // sección nueva) — vacíos = no imprime nada extra.
    public string $seccionActual  = '';
    public string $seccionResumen = '';
    // false = no repetir la fila de columnas en el Header() (para
    // páginas que ya no muestran una tabla de detalle, ej. el resumen
    // final) — sin esto, quedaba impresa la fila de columnas VIEJA
    // (de la última lista de clientes) al arrancar página nueva ahí.
    public bool $mostrarEncabezadoTabla = true;

    function Header(): void
    {
        $this->resetStyles();

        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 6, lat('Imperio Comercial'), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 9);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Histórico de Agenda — Semana ' . $this->rango_lbl), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $this->Cell(95, 5, lat('Cobrador: ' . $this->cobrador_lbl), 0, 0, 'L');
        $this->Cell(95, 5, lat('Generado: ' . $this->fecha_gen), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(3);
        $this->SetLineWidth(0.2);

        if ($this->seccionActual !== '') {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetX(10);
            $this->Cell(190, 6, lat($this->seccionActual), 0, 1, 'L');
        }
        if ($this->seccionResumen !== '') {
            $this->imprimirResumenTexto($this->seccionResumen);
        }

        if ($this->mostrarEncabezadoTabla) {
            $this->encabezadoTabla();
        }
    }

    function encabezadoTabla(): void
    {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(220, 220, 230);
        $this->SetX(10);
        foreach ($this->cols as $i => $w) {
            $this->Cell($w, 6, lat($this->labels[$i]), 1, 0, $this->aligns[$i], true);
        }
        $this->Ln();
        $this->SetFillColor(255, 255, 255);
    }

    function imprimirResumenTexto(string $txt): void
    {
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(80, 80, 80);
        $this->SetX(10);
        $this->MultiCell(190, 4, lat($txt), 0, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    // Arranca una tabla nueva con su propio título + resumen + fila de
    // columnas, con salto de página seguro: si no entra el mínimo
    // (título + resumen + encabezado + 1 fila), pasa de página ANTES de
    // imprimir nada — el Header() automático de esa página nueva ya
    // imprime todo eso solo (vía $seccionActual/$seccionResumen), así
    // que nunca se duplica ni queda desordenado.
    function iniciarSeccionTabla(string $titulo, string $resumenTxt, array $cols, array $labels, array $aligns): void
    {
        $this->cols          = $cols;
        $this->labels        = $labels;
        $this->aligns        = $aligns;
        $this->seccionActual  = $titulo;
        $this->seccionResumen = $resumenTxt;

        $alto = 20 + ($resumenTxt !== '' ? 8 : 0);
        if ($this->GetY() + $alto > $this->GetPageHeight() - 16) {
            $this->AddPage();
        } else {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetX(10);
            $this->Cell(190, 6, lat($titulo), 0, 1, 'L');
            if ($resumenTxt !== '') {
                $this->imprimirResumenTexto($resumenTxt);
            }
            $this->encabezadoTabla();
        }
    }
}

$pdf = new AgendaHistoricoPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->cobrador_lbl = $cobrador_lbl;
$pdf->rango_lbl    = date('d/m/Y', strtotime($lunes_str)) . ' al ' . date('d/m/Y', strtotime($sabado_str));
$pdf->fecha_gen    = date('d/m/Y H:i');
$pdf->cols         = $COLS;
$pdf->labels       = $LABELS;
$pdf->aligns       = $ALIGNS;
$pdf->AddPage();

$num = 1;
function drawFilaAgendaHist(AgendaHistoricoPDF $pdf, array $COLS, int &$num, array $c, string $semana_vista_lunes): void
{
    $credito   = $c['articulo'] . ' - Cuota #' . (int) $c['numero_cuota'] . '/' . (int) $c['cant_cuotas'];
    $atrasadas = (int) $c['cuotas_atrasadas_otras'];
    $es_otra_semana = $c['semana_lunes_cuota'] !== $semana_vista_lunes;
    $semana_txt = date('d/m', strtotime($c['semana_lunes_cuota'])) . ' al ' . date('d/m/y', strtotime($c['semana_sabado_cuota']));

    $ya_estaba_paga = !empty($c['ya_estaba_paga']);
    if ($ya_estaba_paga) {
        $estado = 'Ya estaba paga'
            . ($c['fecha_pago_antes'] ? ' (' . date('d/m/y', strtotime($c['fecha_pago_antes'])) . ')' : '');
    } elseif ($c['pago_realizado']) {
        $estado = 'Pago ' . fmt((float) $c['pagado_esa_semana'])
            . ($c['fecha_pago_esa_semana'] ? ' el ' . date('d/m', strtotime($c['fecha_pago_esa_semana'])) : '')
            . ($c['forma_pago'] ? ' - ' . $c['forma_pago'] : '');
    } else {
        $estado = 'No pago';
    }

    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetX(10);
    $pdf->Cell($COLS[0], 5.5, (string) $num, 1, 0, 'C');
    $pdf->Cell($COLS[1], 5.5, $pdf->fitText($c['apellidos'] . ', ' . $c['nombres'], $COLS[1] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[2], 5.5, $pdf->fitText($credito, $COLS[2] - 2), 1, 0, 'L');
    $pdf->Cell($COLS[3], 5.5, '#' . (int) $c['numero_cuota'], 1, 0, 'C');

    if ($es_otra_semana) $pdf->SetTextColor(200, 80, 0);
    $pdf->Cell($COLS[4], 5.5, $pdf->fitText($semana_txt, $COLS[4] - 1), 1, 0, 'C');
    if ($es_otra_semana) $pdf->SetTextColor(0, 0, 0);

    if ($atrasadas > 0) {
        $pdf->SetTextColor(211, 64, 83);
        $pdf->Cell($COLS[5], 5.5, (string) $atrasadas, 1, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Cell($COLS[5], 5.5, '-', 1, 0, 'C');
    }

    $pdf->Cell($COLS[6], 5.5, lat(fmt((float) $c['monto_cuota'])), 1, 0, 'R');
    if ($ya_estaba_paga) $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell($COLS[7], 5.5, $pdf->fitText($estado, $COLS[7] - 2), 1, 0, 'L');
    if ($ya_estaba_paga) $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln();
    $num++;
}

function resumenTexto(array $r): string
{
    $txt = $r['clientes'] . ' clientes | ' . $r['cuotas'] . ' cuotas | ' . $r['pagaron'] . ' pagaron | ' . $r['no_pagaron'] . ' no pagaron';
    if (!empty($r['ya_pagas'])) {
        $txt .= ' | ' . $r['ya_pagas'] . ' ya estaban pagas';
    }
    $txt .= ' | Estimado ' . fmt($r['estimado']) . ' | Cobrado ' . fmt($r['cobrado']);
    return $txt;
}

// ── Semanales: una sola lista, sin separar por dia_cobro ─────────────
$pdf->iniciarSeccionTabla(
    'Semanales (' . count($semanales_flat) . ' filas)',
    resumenTexto($resumen_semanales),
    $COLS, $LABELS, $ALIGNS
);
foreach ($semanales_flat as $c) {
    drawFilaAgendaHist($pdf, $COLS, $num, $c, $lunes_str);
}
$pdf->Ln(2);

// ── Quincenal / Mensual / Diario: se mantienen agrupadas por frecuencia ──
foreach ($agenda['otras_frecuencias'] as $g) {
    $pdf->iniciarSeccionTabla(
        $g['label'] . ' (' . count($g['clientes']) . ' filas)',
        resumenTexto($g['resumen']),
        $COLS, $LABELS, $ALIGNS
    );
    foreach ($g['clientes'] as $c) {
        drawFilaAgendaHist($pdf, $COLS, $num, $c, $lunes_str);
    }
    $pdf->Ln(2);
}

$pdf->seccionActual          = '';
$pdf->seccionResumen         = '';
$pdf->mostrarEncabezadoTabla = false;

// ── Resumen final por tipo de agenda: Estimado / Cobrado / Faltante ──
// Categoria(32) + Clientes(18) + Cuotas(16) + Pagaron(18) + No Pagaron(22)
// + Estimado(28) + Cobrado(28) + Faltante(28) = 190. "Clientes" ahora
// cuenta clientes distintos (no cuotas) — por eso "Cuotas" es columna
// aparte, y ya no cuadra sumar Pagaron+No Pagaron contra Clientes.
$RCOLS = [32, 18, 16, 18, 22, 28, 28, 28];
$RLBLS = ['Categoria', 'Clientes', 'Cuotas', 'Pagaron', 'No Pagaron', 'Estimado', 'Cobrado', 'Faltante'];

$alto_resumen_final = 8 + 6 + (count($categorias) + 2) * 6;
if ($pdf->GetY() + $alto_resumen_final > $pdf->GetPageHeight() - 16) {
    $pdf->AddPage();
}

$pdf->SetFont('Helvetica', 'B', 10);
$pdf->SetX(10);
$pdf->Cell(190, 7, lat('Resumen por tipo de agenda — Semana ' . $pdf->rango_lbl), 0, 1, 'L');

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetFillColor(220, 220, 230);
$pdf->SetX(10);
foreach ($RCOLS as $i => $w) {
    $pdf->Cell($w, 6, lat($RLBLS[$i]), 1, 0, $i === 0 ? 'L' : ($i <= 4 ? 'C' : 'R'), true);
}
$pdf->Ln();
$pdf->SetFillColor(255, 255, 255);

$pdf->SetFont('Helvetica', '', 8);
foreach ($categorias as $cat) {
    $r = $cat['resumen'];
    $faltante = max(0, $r['estimado'] - $r['cobrado']);
    $pdf->SetX(10);
    $pdf->Cell($RCOLS[0], 6, lat($cat['label']), 1, 0, 'L');
    $pdf->Cell($RCOLS[1], 6, (string) $r['clientes'], 1, 0, 'C');
    $pdf->Cell($RCOLS[2], 6, (string) $r['cuotas'], 1, 0, 'C');
    $pdf->Cell($RCOLS[3], 6, (string) $r['pagaron'], 1, 0, 'C');
    $pdf->Cell($RCOLS[4], 6, (string) $r['no_pagaron'], 1, 0, 'C');
    $pdf->Cell($RCOLS[5], 6, lat(fmt($r['estimado'])), 1, 0, 'R');
    $pdf->Cell($RCOLS[6], 6, lat(fmt($r['cobrado'])), 1, 0, 'R');
    $pdf->Cell($RCOLS[7], 6, lat(fmt($faltante)), 1, 0, 'R');
    $pdf->Ln();
}

// Fila aparte: plata de cuotas de OTRA semana (ya visible arriba, en
// naranja, mezclada en las listas) — se suma acá y entra en el TOTAL de
// "Cobrado", para que esa columna refleje todo lo que efectivamente
// entró en caja esta semana. "Cuotas"/"Pagaron"/"No Pagaron"/"Estimado"
// no aplican a este bucket (no es "una cuota que vencía esta semana"),
// se muestran con "-"; por eso tampoco suma en "Faltante" del TOTAL —
// ver nota al pie.
if ($clientes_fuera_semana > 0) {
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(200, 80, 0);
    $pdf->SetX(10);
    $pdf->Cell($RCOLS[0], 6, lat('Otra semana'), 1, 0, 'L');
    $pdf->Cell($RCOLS[1], 6, (string) $clientes_fuera_semana, 1, 0, 'C');
    $pdf->Cell($RCOLS[2], 6, (string) count($pagos_fuera_semana), 1, 0, 'C');
    $pdf->Cell($RCOLS[3], 6, '-', 1, 0, 'C');
    $pdf->Cell($RCOLS[4], 6, '-', 1, 0, 'C');
    $pdf->Cell($RCOLS[5], 6, '-', 1, 0, 'C');
    $pdf->Cell($RCOLS[6], 6, lat(fmt($monto_fuera_semana)), 1, 0, 'R');
    $pdf->Cell($RCOLS[7], 6, '-', 1, 0, 'C');
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);
}

// "Faltante" sigue siendo Estimado - Cobrado de las categorias
// reconciliables (arriba) — no se recalcula con el Cobrado grande de
// abajo, para que siga reflejando exactamente lo que falta cobrar de
// lo que vencía esta semana puntual.
$tot_faltante        = max(0, $tot_estimado - $tot_cobrado);
$tot_cobrado_general = $tot_cobrado + $monto_fuera_semana;
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetX(10);
$pdf->Cell($RCOLS[0], 6, lat('TOTAL'), 1, 0, 'L');
$pdf->Cell($RCOLS[1], 6, (string) $tot_clientes, 1, 0, 'C');
$pdf->Cell($RCOLS[2], 6, (string) $tot_cuotas, 1, 0, 'C');
$pdf->Cell($RCOLS[3], 6, (string) $tot_pagaron, 1, 0, 'C');
$pdf->Cell($RCOLS[4], 6, (string) $tot_no_pagaron, 1, 0, 'C');
$pdf->Cell($RCOLS[5], 6, lat(fmt($tot_estimado)), 1, 0, 'R');
$pdf->Cell($RCOLS[6], 6, lat(fmt($tot_cobrado_general)), 1, 0, 'R');
$pdf->Cell($RCOLS[7], 6, lat(fmt($tot_faltante)), 1, 0, 'R');
$pdf->Ln();

if ($clientes_fuera_semana > 0) {
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(200, 80, 0);
    $pdf->SetX(10);
    $pdf->MultiCell(190, 4, lat(
        'La fila "Otra semana" son ' . count($pagos_fuera_semana) . ' cuota(s) cobradas esta semana a '
        . $clientes_fuera_semana . ' cliente(s) que NO vencian esta semana (resaltadas en naranja arriba, en la columna "Semana") por '
        . fmt($monto_fuera_semana) . ' — ya suman en el TOTAL de "Cobrado" de arriba, pero no en "Faltante" '
        . '(que solo compara contra las cuotas que vencian esta semana puntual).'
    ), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Ln(4);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
$pdf->MultiCell(190, 4, lat(
    'Nota: usa la asignacion de cobrador ACTUAL de cada credito, no la que tenia esa semana pasada. '
    . 'Si un credito fue reasignado a otro cobrador (Migrar Cobrador) despues de esa semana, figura aca bajo el cobrador de hoy, no el de entonces. '
    . 'No incluye cuotas de creditos dados de baja (Retiro de Producto, Incobrabilidad, etc.) — esa plata nunca fue ni va a ser cobrable, salvo que '
    . 'igual haya tenido un cobro real esa semana puntual, en cuyo caso sigue apareciendo. '
    . '"Ya estaba paga" = la cuota vencia esta semana pero el cliente ya la habia pagado por completo en una semana anterior (adelanto) — no suma '
    . 'en Estimado/Cobrado ni cuenta como Pagaron/No Pagaron, porque no habia nada que cobrarle esa semana; un pago parcial anterior no alcanza, '
    . 'sigue siendo "No pago" normal. '
    . '"Semana" = semana a la que pertenece esa cuota (Lunes a Sabado) — coincide con la semana del reporte salvo en las filas en naranja, '
    . 'que son cobros de esta semana aplicados a una cuota de otra semana (backlog viejo o adelanto de una futura). '
    . '"Pago" = pago confirmado con semana_lunes de esa misma semana (revertido=0) — un pago posterior no cuenta como pagado en esta semana. '
    . '"Atrasadas" = otras cuotas del mismo credito, vencidas y sin cobrar HOY (no necesariamente ya estaban vencidas en esa semana pasada). '
    . '"Clientes" del resumen final cuenta clientes distintos (un mismo cliente con 2 creditos venciendo esa semana cuenta una sola vez) — '
    . '"Cuotas" es la cantidad de filas/cuotas, que si se reparte exacto entre Pagaron + No Pagaron + las que ya estaban pagas. '
    . 'En el resumen final: "Faltante" = Estimado - Cobrado, solo de las cuotas que vencian esta semana puntual (la fila "Otra semana" no entra ahi). '
    . 'El TOTAL de "Cobrado" si incluye la fila "Otra semana" — por eso Estimado - Cobrado del TOTAL no va a dar exacto igual a "Faltante", a proposito.'
), 0, 'L');
$pdf->SetTextColor(0, 0, 0);

$nombre = 'agenda_historico_' . $cobrador_id . '_' . $lunes_str . '.pdf';
$pdf->Output('I', $nombre);
