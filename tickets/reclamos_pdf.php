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

    foreach ($lista as $t) {
        $cliente = $t['cliente_apellidos'] . ', ' . $t['cliente_nombres'];
        $credito = '#' . $t['credito_id'] . ' — ' . $t['articulo'];
        $cobrador = $t['cobrador_nombre'] ? $t['cobrador_apellido'] . ', ' . $t['cobrador_nombre'] : '—';
        $prioridad = ucfirst($t['prioridad']);
        $actualiz = date('d/m/y', strtotime($t['updated_at']));

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetX(10);
        $pdf->Cell($COLS[0], 5.5, (string) $t['id'],                                      1, 0, 'C');
        $pdf->Cell($COLS[1], 5.5, lat(ucfirst($t['tipo'])),                                1, 0, 'L');
        $pdf->Cell($COLS[2], 5.5, $pdf->fitText($cliente, $COLS[2] - 2),                   1, 0, 'L');
        $pdf->Cell($COLS[3], 5.5, $pdf->fitText($credito, $COLS[3] - 2),                   1, 0, 'L');
        $pdf->Cell($COLS[4], 5.5, $pdf->fitText($t['titulo'], $COLS[4] - 2),               1, 0, 'L');
        $pdf->Cell($COLS[5], 5.5, lat($prioridad),                                         1, 0, 'C');
        $pdf->Cell($COLS[6], 5.5, $pdf->fitText($cobrador, $COLS[6] - 2),                  1, 0, 'L');
        $pdf->Cell($COLS[7], 5.5, $actualiz,                                               1, 0, 'C');
        $pdf->Cell($COLS[8], 5.5, (string) $t['num_resp'],                                 1, 0, 'C');
        $pdf->Ln();
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
