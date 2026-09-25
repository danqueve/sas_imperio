<?php
// ============================================================
// tickets/reclamos_excel.php — Excel de Reclamos/Posventa Abiertos y
// En Progreso (PhpSpreadsheet). Mismos filtros que tickets/index.php,
// pero solo casos no resueltos — una hoja por estado.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
require_once __DIR__ . '/../vendor/autoload.php';
verificar_sesion();
verificar_permiso('gestionar_reclamos');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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
    SELECT tk.id, tk.tipo, tk.titulo, tk.descripcion, tk.estado, tk.prioridad,
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

// ============================================================
// Construcción del Excel
// ============================================================
$AZUL_CLARO = 'D9E2F3';

function tk_estilizarEncabezado($sheet, string $rango, string $color): void
{
    $sheet->getStyle($rango)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
    ]);
}

function tk_autoAjustar($sheet, int $cantColumnas): void
{
    for ($i = 1; $i <= $cantColumnas; $i++) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
}

function tk_llenarHoja($sheet, array $lista): void
{
    $headers = ['ID', 'Tipo', 'Cliente', 'Credito', 'Articulo', 'Titulo', 'Descripcion',
                'Prioridad', 'Cobrador', 'Creado', 'Actualizado', 'Respuestas'];
    $sheet->fromArray($headers, null, 'A1');
    tk_estilizarEncabezado($sheet, 'A1:L1', 'D9E2F3');

    $fila = 2;
    foreach ($lista as $t) {
        $cobrador = $t['cobrador_nombre'] ? $t['cobrador_apellido'] . ', ' . $t['cobrador_nombre'] : '—';
        // $strictNullComparison=true: sin esto, fromArray() compara con "!="
        // (floja) contra $nullValue=null, y un 0 legitimo (ej. "0 respuestas")
        // matchea "0 == null" y la celda queda vacia en vez de mostrar 0.
        $sheet->fromArray([
            (int) $t['id'],
            ucfirst($t['tipo']),
            sanear_csv_formula($t['cliente_apellidos'] . ', ' . $t['cliente_nombres']),
            (int) $t['credito_id'],
            sanear_csv_formula($t['articulo']),
            sanear_csv_formula($t['titulo']),
            sanear_csv_formula($t['descripcion']),
            ucfirst($t['prioridad']),
            sanear_csv_formula($cobrador),
            date('d/m/Y H:i', strtotime($t['created_at'])),
            date('d/m/Y H:i', strtotime($t['updated_at'])),
            (int) $t['num_resp'],
        ], null, 'A' . $fila, true);
        $fila++;
    }

    $sheet->freezePane('A2');
    tk_autoAjustar($sheet, 12);
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Imperio Comercial')
    ->setTitle('Reclamos y Posventa - Abiertos y En Progreso');

$hojas_creadas = 0;
if (!empty($grupos['abierto'])) {
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Abiertos');
    tk_llenarHoja($sheet, $grupos['abierto']);
    $hojas_creadas++;
}
if (!empty($grupos['en_progreso'])) {
    $sheet = $hojas_creadas === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $sheet->setTitle('En Progreso');
    tk_llenarHoja($sheet, $grupos['en_progreso']);
    $hojas_creadas++;
}

$spreadsheet->setActiveSheetIndex(0);

$nombre = 'reclamos_posventa_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
