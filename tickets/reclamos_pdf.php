<?php
// ============================================================
// tickets/reclamos_pdf.php — PDF de Reclamos/Posventa Abiertos y En Progreso
// Respeta los mismos filtros (tipo, cobrador, cliente) que tickets/index.php,
// pero solo incluye casos no resueltos — pensado como listado de trabajo.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_reclamos');

$pdo = obtener_conexion();
$uid = (int) $_SESSION['user_id'];
$is_cobrador = es_cobrador();
$puede_filtrar_cobrador = es_admin() || es_supervisor();

// ── Mismos filtros que tickets/index.php ────────────────────────
$f_tipo     = $_GET['tipo'] ?? 'todos';
$f_cobrador = $puede_filtrar_cobrador ? (int) ($_GET['cobrador_id'] ?? 0) : 0;
$f_q        = trim($_GET['q'] ?? '');

$where  = ["tk.estado IN ('abierto','en_progreso')"];
$params = [];

if ($is_cobrador) {
    $where[]  = 'cl.cobrador_id = ?';
    $params[] = $uid;
} elseif ($f_cobrador > 0) {
    $where[]  = 'cl.cobrador_id = ?';
    $params[] = $f_cobrador;
}

if (in_array($f_tipo, ['reclamo', 'posventa'], true)) {
    $where[]  = 'tk.tipo = ?';
    $params[] = $f_tipo;
}

if ($f_q !== '') {
    $where[]  = "(cl.nombres LIKE ? OR cl.apellidos LIKE ?)";
    $params[] = "%{$f_q}%";
    $params[] = "%{$f_q}%";
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT tk.id, tk.tipo, tk.titulo, tk.estado, tk.prioridad,
           tk.created_at, tk.updated_at, tk.cliente_id, tk.credito_id,
           cl.nombres AS cliente_nombres, cl.apellidos AS cliente_apellidos,
           cob.nombre AS cobrador_nombre, cob.apellido AS cobrador_apellido,
           COALESCE(cr.articulo_desc, art.descripcion, 'Sin artículo') AS articulo,
           (SELECT COUNT(*) FROM ic_ticket_respuestas tr WHERE tr.ticket_id = tk.id) AS num_resp
    FROM ic_tickets tk
    JOIN ic_clientes cl  ON cl.id = tk.cliente_id
    LEFT JOIN ic_usuarios cob ON cob.id = cl.cobrador_id
    JOIN ic_creditos cr  ON cr.id = tk.credito_id
    LEFT JOIN ic_articulos art ON art.id = cr.articulo_id
    WHERE {$where_sql}
    ORDER BY FIELD(tk.estado,'abierto','en_progreso'), FIELD(tk.prioridad,'alta','media','baja'), tk.updated_at DESC
");
$stmt->execute($params);
$todos = $stmt->fetchAll();

if (empty($todos)) {
    die('No hay casos Abiertos ni En Progreso con los filtros aplicados.');
}

$grupos = ['abierto' => [], 'en_progreso' => []];
foreach ($todos as $t) {
    $grupos[$t['estado']][] = $t;
}

// ── Etiquetas de filtro para el encabezado ──────────────────────
$tipo_lbl = match ($f_tipo) { 'reclamo' => 'Reclamo', 'posventa' => 'Posventa', default => 'Todos' };
$cob_lbl  = 'Todos';
if ($is_cobrador) {
    $cob_lbl = trim($_SESSION['nombre'] . ' ' . $_SESSION['apellido']);
} elseif ($f_cobrador > 0) {
    $cs = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
    $cs->execute([$f_cobrador]);
    $cob = $cs->fetch();
    if ($cob) $cob_lbl = $cob['apellido'] . ', ' . $cob['nombre'];
}

require_once __DIR__ . '/../lib/PDFBase.php';

// Columnas: suma = 190mm (A4 portrait, márgenes 10mm c/lado)
// #(6) + Tipo(14) + Cliente(32) + Credito/Articulo(32) + Titulo(48)
// + Prior.(13) + Cobrador(22) + Actualiz.(13) + Resp.(10) = 190
$COLS   = [6, 14, 32, 32, 48, 13, 22, 13, 10];
$LABELS = ['#', 'Tipo', 'Cliente', 'Credito/Articulo', 'Titulo', 'Prior.', 'Cobrador', 'Actualiz.', 'Resp.'];
$ALIGNS = ['C', 'L', 'L', 'L', 'L', 'C', 'L', 'C', 'C'];

class ReclamosPDF extends PDFBase
{
    public string $tipo_lbl = '';
    public string $cob_lbl  = '';
    public string $q_lbl    = '';
    public string $fecha_gen = '';
    public int    $total    = 0;
    public array  $cols     = [];
    public array  $labels   = [];
    public array  $aligns   = [];

    function Header(): void
    {
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY(10, 8);
        $this->Cell(190, 6, lat('Imperio Comercial - Reclamos y Posventa'), 0, 1, 'L');

        $this->SetFont('Helvetica', 'I', 8);
        $this->SetX(10);
        $this->Cell(190, 5, lat('Casos Abiertos y En Progreso — no incluye Resueltos'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 7);
        $this->SetX(10);
        $filtro_txt = 'Tipo: ' . $this->tipo_lbl . '   |   Cobrador: ' . $this->cob_lbl;
        if ($this->q_lbl !== '') $filtro_txt .= '   |   Cliente: "' . $this->q_lbl . '"';
        $this->Cell(130, 5, lat($filtro_txt), 0, 0, 'L');
        $this->Cell(60, 5, lat('Emision: ' . $this->fecha_gen . '  |  ' . $this->total . ' caso(s)'), 0, 1, 'R');

        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY() + 1, 200, $this->GetY() + 1);
        $this->Ln(4);
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
        $this->SetFont('Helvetica', '', 7);
    }

    // Cuenta cuántas líneas ocuparía $txt (ya codificado con lat()) en un
    // MultiCell de ancho $w — mismo algoritmo de word-wrap que usa
    // MultiCell() por dentro (adaptado de fpdf/fpdf.php), para poder
    // calcular la altura de la fila ANTES de dibujar nada.
    function nbLines(float $w, string $txt): int
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s  = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++; $sep = -1; $j = $i; $l = 0; $nl++;
                continue;
            }
            if ($c === ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) $i++;
                } else {
                    $i = $sep + 1;
                }
                $nl++;
                $j = $i; $l = 0; $sep = -1;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    // Fila con altura variable: las columnas marcadas en $wrap se dibujan
    // con MultiCell (texto completo, en varias líneas si hace falta); el
    // resto con Cell de una sola línea, centrada verticalmente en la
    // misma altura de fila. El borde de cada columna se dibuja aparte con
    // Rect(), a la altura uniforme de toda la fila — si se dejara que
    // MultiCell/Cell dibujen su propio borde, una columna más corta
    // quedaría con un borde más bajo que sus vecinas.
    function drawFilaAjustada(array $valores, array $wrap, float $lineH = 4.2): void
    {
        $maxLineas = 1;
        foreach ($wrap as $i => $w) {
            if ($w) $maxLineas = max($maxLineas, $this->nbLines($this->cols[$i] - 2, $valores[$i]));
        }
        $rowH = max(5.5, $maxLineas * $lineH);

        // Salto de página manual — Rect()/MultiCell() sin borde no activan
        // por sí solos el auto-page-break de FPDF.
        if ($this->GetY() + $rowH > $this->GetPageHeight() - 18) {
            $this->AddPage();
            $this->encabezadoTabla();
        }

        $x0 = 10;
        $y0 = $this->GetY();

        foreach ($this->cols as $i => $w) {
            $x = $x0 + array_sum(array_slice($this->cols, 0, $i));
            $this->Rect($x, $y0, $w, $rowH);
        }
        foreach ($this->cols as $i => $w) {
            $x = $x0 + array_sum(array_slice($this->cols, 0, $i));
            $this->SetXY($x, $y0);
            if ($wrap[$i]) {
                $this->MultiCell($w, $lineH, $valores[$i], 0, $this->aligns[$i]);
            } else {
                $this->Cell($w, $rowH, $valores[$i], 0, 0, $this->aligns[$i]);
            }
        }
        $this->SetXY($x0, $y0 + $rowH);
    }
}

$pdf = new ReclamosPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 14);
$pdf->tipo_lbl   = $tipo_lbl;
$pdf->cob_lbl    = $cob_lbl;
$pdf->q_lbl      = $f_q;
$pdf->fecha_gen  = date('d/m/Y H:i');
$pdf->total      = count($todos);
$pdf->cols       = $COLS;
$pdf->labels     = $LABELS;
$pdf->aligns     = $ALIGNS;
$pdf->AddPage();

$SECCIONES = [
    'abierto'     => 'ABIERTOS',
    'en_progreso' => 'EN PROGRESO',
];

foreach ($SECCIONES as $estado_key => $titulo) {
    $lista = $grupos[$estado_key];
    if (empty($lista)) continue;

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetX(10);
    $pdf->Cell(190, 7, lat($titulo . ' — ' . count($lista) . ' caso(s)'), 0, 1, 'L');
    $pdf->encabezadoTabla();

    // Columnas que se ajustan a varias líneas si hace falta (Cliente,
    // Credito/Articulo, Titulo, Cobrador) — el resto son valores cortos
    // de una sola línea (#, Tipo, Prior., Actualiz., Resp.).
    $WRAP = [false, false, true, true, true, false, true, false, false];

    $pdf->SetFont('Helvetica', '', 7);
    foreach ($lista as $t) {
        $cliente   = $t['cliente_apellidos'] . ', ' . $t['cliente_nombres'];
        $credito   = '#' . $t['credito_id'] . ' — ' . $t['articulo'];
        $cobrador  = $t['cobrador_nombre'] ? $t['cobrador_apellido'] . ', ' . $t['cobrador_nombre'] : '—';
        $prioridad = ucfirst($t['prioridad']);
        $actualiz  = date('d/m/y', strtotime($t['updated_at']));

        $valores = [
            (string) $t['id'],
            lat(ucfirst($t['tipo'])),
            lat($cliente),
            lat($credito),
            lat($t['titulo']),
            lat($prioridad),
            lat($cobrador),
            $actualiz,
            (string) $t['num_resp'],
        ];

        $pdf->drawFilaAjustada($valores, $WRAP);
    }

    $pdf->Ln(3);
}

// ── Resumen al pie ────────────────────────────────────────────
$pdf->Ln(2);
$bx  = 100;
$bw1 = 55;
$bw2 = 35;

$resumen = [
    ['Casos Abiertos',      count($grupos['abierto'])],
    ['Casos En Progreso',   count($grupos['en_progreso'])],
    ['TOTAL',               count($todos)],
];

foreach ($resumen as $i => [$label, $valor]) {
    $es_total = ($i === count($resumen) - 1);
    $pdf->SetFont('Helvetica', $es_total ? 'B' : '', 9);
    $pdf->SetX($bx);
    $pdf->Cell($bw1, 7, lat($label), 1, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell($bw2, 7, (string) $valor, 1, 1, 'R');
}

// ── Output ───────────────────────────────────────────────────
$nombre = 'reclamos_posventa_' . date('Ymd_His') . '.pdf';
$pdf->Output('I', $nombre);
