<?php
// ============================================================
// scripts/reporte_combos_inconsistentes.php
// ------------------------------------------------------------
// Reporte de SOLO LECTURA sobre los créditos COMBO (los que tienen filas
// en ic_credito_articulos), para revisar cuáles quedaron con datos
// incoherentes.
//
// Contexto: hasta el fix de creditos/editar.php, editar un combo
// recalculaba monto_total como precio_articulo × (1 + interes_pct/100).
// Como en un combo precio_articulo es la suma de los ítems SIN descuento
// e interes_pct es 0, cada guardado pisaba el monto acordado y regeneraba
// el cronograma. Varios admins lo compensaron a mano retipeando el total
// en el campo "Precio del Artículo", con lo cual se salvó el monto pero
// se perdió la suma de ítems de referencia (recuperable desde
// ic_credito_articulos).
//
// ESTE SCRIPT NO ESCRIBE NADA. No existe --commit. Solo lee y reporta.
//
// ── Uso ──────────────────────────────────────────────────────
//   php scripts\reporte_combos_inconsistentes.php            (solo los que tienen alguna marca)
//   php scripts\reporte_combos_inconsistentes.php --todos    (todos los combos)
//   php scripts\reporte_combos_inconsistentes.php --csv      (CSV a stdout, para Excel)
// ============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo se ejecuta por línea de comandos.');
}

define('BASE_DIR', __DIR__ . '/..');
require_once BASE_DIR . '/config/conexion.php';

$args   = $argv ?? [];
$todos  = in_array('--todos', $args, true);
$as_csv = in_array('--csv', $args, true);

// Tolerancia: las columnas son DECIMAL(12,2), un centavo alcanza.
const EPS = 0.01;

$pdo = obtener_conexion();

$sql = "
    SELECT cr.id, cr.estado, cr.fecha_alta,
           CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
           cr.interes_pct, cr.precio_articulo, cr.monto_total,
           cr.cant_cuotas, cr.monto_cuota,
           ROUND(cr.monto_cuota * cr.cant_cuotas, 2) AS cuota_x_cant,
           (SELECT ROUND(SUM(ca.subtotal), 2) FROM ic_credito_articulos ca WHERE ca.credito_id = cr.id) AS suma_items,
           (SELECT COUNT(*)                   FROM ic_credito_articulos ca WHERE ca.credito_id = cr.id) AS n_items,
           (SELECT COUNT(*)                        FROM ic_cuotas q WHERE q.credito_id = cr.id) AS n_cuotas,
           (SELECT ROUND(SUM(q.monto_cuota), 2)    FROM ic_cuotas q WHERE q.credito_id = cr.id) AS suma_cuotas,
           (SELECT ROUND(MIN(q.monto_cuota), 2)    FROM ic_cuotas q WHERE q.credito_id = cr.id) AS cuota_min,
           (SELECT ROUND(MAX(q.monto_cuota), 2)    FROM ic_cuotas q WHERE q.credito_id = cr.id) AS cuota_max,
           (SELECT COUNT(*) FROM ic_cuotas q WHERE q.credito_id = cr.id AND q.estado = 'PAGADA') AS pagadas,
           (SELECT COUNT(*) FROM ic_log_actividades l
              WHERE l.accion = 'CREDITO_EDITADO' AND l.entidad = 'credito' AND l.entidad_id = cr.id) AS veces_editado,
           (SELECT MAX(l.fecha) FROM ic_log_actividades l
              WHERE l.accion = 'CREDITO_EDITADO' AND l.entidad = 'credito' AND l.entidad_id = cr.id) AS ultima_edicion,
           (SELECT COUNT(*) FROM ic_cuota_vencimiento_historial h WHERE h.credito_id = cr.id) AS ajustes_fecha
    FROM   ic_creditos cr
    JOIN   ic_clientes cl ON cl.id = cr.cliente_id
    WHERE  EXISTS (SELECT 1 FROM ic_credito_articulos ca WHERE ca.credito_id = cr.id)
    ORDER BY cr.estado, cr.id
";

$filas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$marcas_de = static function (array $f): array {
    $m = [];
    if (abs((float) $f['precio_articulo'] - (float) $f['suma_items']) > EPS) $m[] = 'PRECIO!=ITEMS';
    if (abs((float) $f['monto_total']     - (float) $f['cuota_x_cant']) > EPS) $m[] = 'TOT!=CUOxCANT';
    if (abs((float) $f['suma_cuotas']     - (float) $f['monto_total'])  > EPS) $m[] = 'SUMCUO!=TOT';
    if (abs((float) $f['cuota_max']       - (float) $f['cuota_min'])    > EPS) $m[] = 'CUOTAS_DISPARES';
    if ((int) $f['n_cuotas'] !== (int) $f['cant_cuotas'])                      $m[] = 'FILAS!=CANT';
    if (abs((float) $f['interes_pct']) > EPS)                                  $m[] = 'INTERES!=0';
    return $m;
};

$total_combos = count($filas);
$conteo = [];
$listado = [];
foreach ($filas as $f) {
    $m = $marcas_de($f);
    foreach ($m as $x) $conteo[$x] = ($conteo[$x] ?? 0) + 1;
    if ($m || $todos) $listado[] = $f + ['_marcas' => $m];
}

if ($as_csv) {
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID','Estado','Cliente','Items','Suma Items','Precio Articulo','Monto Total',
                   'Cant Cuotas','Monto Cuota','Cuota x Cant','Filas Cuotas','Suma Cuotas',
                   'Pagadas','Veces Editado','Ultima Edicion','Ajustes Fecha','Marcas'], ';', '"', '\\');
    foreach ($listado as $f) {
        fputcsv($out, [
            $f['id'], $f['estado'], $f['cliente'], $f['n_items'],
            number_format((float) $f['suma_items'], 2, ',', '.'),
            number_format((float) $f['precio_articulo'], 2, ',', '.'),
            number_format((float) $f['monto_total'], 2, ',', '.'),
            $f['cant_cuotas'],
            number_format((float) $f['monto_cuota'], 2, ',', '.'),
            number_format((float) $f['cuota_x_cant'], 2, ',', '.'),
            $f['n_cuotas'],
            number_format((float) $f['suma_cuotas'], 2, ',', '.'),
            $f['pagadas'], $f['veces_editado'], $f['ultima_edicion'] ?? '', $f['ajustes_fecha'],
            implode(' ', $f['_marcas']),
        ], ';', '"', '\\');
    }
    fclose($out);
    exit(0);
}

$fmt = static fn($n): string => number_format((float) $n, 2, ',', '.');

echo str_repeat('=', 150) . PHP_EOL;
echo 'REPORTE DE CREDITOS COMBO — solo lectura, no se modifica ningun dato' . PHP_EOL;
echo 'Base: ' . DB_NAME . '   |   Generado: ' . date('d/m/Y H:i') . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL . PHP_EOL;

printf("%-6s %-10s %-26s %3s %14s %14s %14s %4s %13s %5s %14s %4s %5s  %s\n",
    'ID', 'ESTADO', 'CLIENTE', 'IT', 'SUMA ITEMS', 'PRECIO ART.', 'MONTO TOTAL',
    'N', 'MONTO CUOTA', 'FILAS', 'SUMA CUOTAS', 'PAG', 'EDIC', 'MARCAS');
echo str_repeat('-', 150) . PHP_EOL;

foreach ($listado as $f) {
    printf("%-6s %-10s %-26s %3s %14s %14s %14s %4s %13s %5s %14s %4s %5s  %s\n",
        '#' . $f['id'], $f['estado'], mb_strimwidth((string) $f['cliente'], 0, 26, '..'),
        $f['n_items'], $fmt($f['suma_items']), $fmt($f['precio_articulo']), $fmt($f['monto_total']),
        $f['cant_cuotas'], $fmt($f['monto_cuota']), $f['n_cuotas'], $fmt($f['suma_cuotas']),
        $f['pagadas'], $f['veces_editado'], implode(' ', $f['_marcas']));
}

echo str_repeat('-', 150) . PHP_EOL;
echo 'Combos totales: ' . $total_combos . '   |   Listados: ' . count($listado)
   . ($todos ? ' (todos)' : ' (solo con alguna marca)') . PHP_EOL . PHP_EOL;

echo 'RESUMEN POR MARCA' . PHP_EOL;
$leyenda = [
    'PRECIO!=ITEMS'   => 'precio_articulo no coincide con la suma de los items — la referencia fue pisada por una edicion previa. Se puede recuperar desde ic_credito_articulos.',
    'TOT!=CUOxCANT'   => 'monto_total no coincide con monto_cuota x cant_cuotas.',
    'SUMCUO!=TOT'     => 'la suma del cronograma no coincide con monto_total (normal si se cambio el monto habiendo cuotas ya cobradas).',
    'CUOTAS_DISPARES' => 'hay cuotas de distinto importe dentro del mismo credito.',
    'FILAS!=CANT'     => 'ic_creditos.cant_cuotas no coincide con la cantidad real de filas en ic_cuotas.',
    'INTERES!=0'      => 'un combo deberia tener interes_pct = 0 (nuevo.php siempre lo guarda en 0).',
];
foreach ($leyenda as $marca => $desc) {
    printf("  %-16s %3d   %s\n", $marca, $conteo[$marca] ?? 0, $desc);
}

echo PHP_EOL . 'Nota: este script no modifico ningun dato. Cualquier correccion se decide y se aplica aparte.' . PHP_EOL;
exit(0);
