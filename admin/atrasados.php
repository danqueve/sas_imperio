<?php
// ============================================================
// admin/atrasados.php — Cuotas atrasadas con filtros y export PDF
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

function build_atrasados_where(int $cobrador_id, string $zona): array
{
    $w = [
        "cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL')",
        "cr.estado IN ('EN_CURSO','MOROSO')",
        "cu.fecha_vencimiento < CURDATE()",
        "(cu.monto_cuota - cu.saldo_pagado) > 0",
    ];
    $p = [];
    if ($cobrador_id > 0) { $w[] = 'cr.cobrador_id = ?'; $p[] = $cobrador_id; }
    if ($zona !== '')     { $w[] = 'cl.zona = ?';         $p[] = $zona; }
    return ['sql' => implode(' AND ', $w), 'params' => $p];
}

// ── Export CSV ─────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $cobrador_id_csv = (int) ($_GET['cobrador_id'] ?? 0);
    $zona_csv        = trim($_GET['zona'] ?? '');

    ['sql' => $wStr, 'params' => $p] = build_atrasados_where($cobrador_id_csv, $zona_csv);

    $stmtCsv = $pdo->prepare("
        SELECT
            cl.id AS cliente_id,
            cl.apellidos, cl.nombres,
            cl.dni,
            cl.telefono AS celular,
            SUM(cu.monto_cuota - cu.saldo_pagado)                   AS total_adeudado,
            COUNT(*)                                                AS cant_cuotas_vencidas,
            MAX(DATEDIFF(CURDATE(), cu.fecha_vencimiento))          AS max_dias_atraso,
            (SELECT MAX(pc.fecha_pago)
             FROM ic_pagos_confirmados pc
             JOIN ic_cuotas cu2 ON pc.cuota_id = cu2.id
             JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
             WHERE cr2.cliente_id = cl.id)                         AS ultimo_pago,
            IF(COUNT(DISTINCT cr.cobrador_id) = 1,
               MAX(CONCAT(u.apellido, ', ', u.nombre)),
               CONCAT(COUNT(DISTINCT cr.cobrador_id), ' cobradores')) AS cobrador,
            cl.zona
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        JOIN ic_usuarios u       ON cr.cobrador_id = u.id
        WHERE $wStr
        GROUP BY cl.id, cl.apellidos, cl.nombres, cl.dni, cl.telefono, cl.zona
        ORDER BY total_adeudado DESC, cl.apellidos ASC
    ");
    $stmtCsv->execute($p);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="atrasados_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($out, ['Cliente', 'DNI', 'Celular', 'Cuotas Vencidas', 'Total Adeudado', 'Máx. Días Atraso', 'Último Pago', 'Cobrador', 'Zona'], ';');
    while ($row = $stmtCsv->fetch()) {
        fputcsv($out, [
            $row['apellidos'] . ', ' . $row['nombres'],
            $row['dni'] ?: '—',
            $row['celular'] ?: '—',
            $row['cant_cuotas_vencidas'],
            number_format((float)$row['total_adeudado'], 2, ',', '.'),
            $row['max_dias_atraso'],
            $row['ultimo_pago'] ? date('d/m/Y', strtotime($row['ultimo_pago'])) : '—',
            $row['cobrador'],
            $row['zona'] ?: '—',
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Filtros y vista ──────────────────────────────────────────
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);
$zona_sel    = trim($_GET['zona'] ?? '');
$vista       = ($_GET['vista'] ?? 'cuotas') === 'clientes' ? 'clientes' : 'cuotas';

// ── Rango de fechas — sólo afecta "Monto Cobrado" del resumen por zona ──
$hoy            = date('Y-m-d');
$primer_dia_mes = date('Y-m-01');
$desde = (isset($_GET['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde']))
    ? $_GET['desde'] : $primer_dia_mes;
$hasta = (isset($_GET['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta']))
    ? $_GET['hasta'] : $hoy;
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$hasta_ext = (date('N', strtotime($hasta)) == 6) ? date('Y-m-d', strtotime($hasta . ' +1 day')) : $hasta;

$mes_ant_ini = date('Y-m-01', strtotime('first day of last month'));
$mes_ant_fin = date('Y-m-t',  strtotime('first day of last month'));
$trim_ini    = date('Y-m-01', strtotime('-2 months'));
$periodo_activo = 'custom';
if ($desde === $primer_dia_mes && $hasta === $hoy) $periodo_activo = 'mes';
elseif ($desde === $mes_ant_ini && $hasta === $mes_ant_fin) $periodo_activo = 'mes_ant';
elseif ($desde === $trim_ini && $hasta === $hoy) $periodo_activo = 'trimestre';

// ── Paginación ───────────────────────────────────────────────
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

// ── WHERE dinámico (compartido por todas las consultas) ──────
['sql' => $whereStr, 'params' => $params] = build_atrasados_where($cobrador_id, $zona_sel);

// ── Totales globales ─────────────────────────────────────────
$stmtTot = $pdo->prepare("
    SELECT
        COUNT(*)                                               AS total_cuotas,
        COUNT(DISTINCT cl.id)                                  AS total_clientes,
        SUM(cu.monto_cuota - cu.saldo_pagado)                  AS total_adeudado
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE $whereStr
");
$stmtTot->execute($params);
$totales = $stmtTot->fetch();

// ── Resumen por cobrador (siempre, independiente de paginación) ──
$stmtCob = $pdo->prepare("
    SELECT
        u.id, u.nombre, u.apellido,
        COUNT(*)                                               AS cant_cuotas,
        COUNT(DISTINCT cl.id)                                  AS cant_clientes,
        SUM(cu.monto_cuota - cu.saldo_pagado)                  AS total_adeudado,
        MAX(DATEDIFF(CURDATE(), cu.fecha_vencimiento))          AS max_dias
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    JOIN ic_usuarios u  ON cr.cobrador_id = u.id
    WHERE $whereStr
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY total_adeudado DESC
");
$stmtCob->execute($params);
$resumen_cob = $stmtCob->fetchAll();

// ── Resumen por zona (siempre, independiente de paginación) ──
// Z1: Saldo vencido + días máx de atraso + clientes atrasados, por zona
$stmtZonaSaldo = $pdo->prepare("
    SELECT
        cl.zona,
        SUM(cu.monto_cuota - cu.saldo_pagado)          AS saldo_zona,
        MAX(DATEDIFF(CURDATE(), cu.fecha_vencimiento)) AS max_dias_zona,
        COUNT(DISTINCT cl.id)                          AS clientes_atrasados_zona
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE $whereStr
    GROUP BY cl.zona
");
$stmtZonaSaldo->execute($params);

// Z2: Clientes activos por zona (denominador del %) — condición propia
$whereActivos  = ["cr.estado IN ('EN_CURSO','MOROSO')"];
$paramsActivos = [];
if ($cobrador_id > 0) { $whereActivos[] = 'cr.cobrador_id = ?'; $paramsActivos[] = $cobrador_id; }
if ($zona_sel !== '') { $whereActivos[] = 'cl.zona = ?';        $paramsActivos[] = $zona_sel; }
$stmtZonaActivos = $pdo->prepare("
    SELECT cl.zona, COUNT(DISTINCT cl.id) AS clientes_activos_zona
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE " . implode(' AND ', $whereActivos) . "
    GROUP BY cl.zona
");
$stmtZonaActivos->execute($paramsActivos);

// Z3: Monto cobrado por zona, entre $desde y $hasta_ext
$whereMC  = ['pc.fecha_pago BETWEEN ? AND ?'];
$paramsMC = [$desde, $hasta_ext];
if ($cobrador_id > 0) { $whereMC[] = 'cr.cobrador_id = ?'; $paramsMC[] = $cobrador_id; }
if ($zona_sel !== '') { $whereMC[] = 'cl.zona = ?';        $paramsMC[] = $zona_sel; }
$stmtZonaCobrado = $pdo->prepare("
    SELECT cl.zona, SUM(pc.monto_total) AS monto_cobrado_zona
    FROM ic_pagos_confirmados pc
    JOIN ic_cuotas cu   ON pc.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    WHERE " . implode(' AND ', $whereMC) . "
    GROUP BY cl.zona
");
$stmtZonaCobrado->execute($paramsMC);

// ── Reshape en PHP → $por_zona[zona] = [...] ─────────────────
$por_zona = [];
$zInit = ['saldo' => 0.0, 'max_dias' => 0, 'clientes_atrasados' => 0, 'clientes_activos' => 0, 'monto_cobrado' => 0.0];

foreach ($stmtZonaSaldo->fetchAll() as $r) {
    $z = $r['zona'] ?: 'Sin zona';
    $por_zona[$z] = $por_zona[$z] ?? $zInit;
    $por_zona[$z]['saldo']              = (float) $r['saldo_zona'];
    $por_zona[$z]['max_dias']           = (int) $r['max_dias_zona'];
    $por_zona[$z]['clientes_atrasados'] = (int) $r['clientes_atrasados_zona'];
}
foreach ($stmtZonaActivos->fetchAll() as $r) {
    $z = $r['zona'] ?: 'Sin zona';
    $por_zona[$z] = $por_zona[$z] ?? $zInit;
    $por_zona[$z]['clientes_activos'] = (int) $r['clientes_activos_zona'];
}
foreach ($stmtZonaCobrado->fetchAll() as $r) {
    $z = $r['zona'] ?: 'Sin zona';
    $por_zona[$z] = $por_zona[$z] ?? $zInit;
    $por_zona[$z]['monto_cobrado'] = (float) $r['monto_cobrado_zona'];
}
foreach ($por_zona as &$dz) {
    $dz['pct_atrasados'] = $dz['clientes_activos'] > 0
        ? round($dz['clientes_atrasados'] / $dz['clientes_activos'] * 100, 1)
        : 0.0;
}
unset($dz);
uasort($por_zona, fn($a, $b) => $b['saldo'] <=> $a['saldo']);

// ── Datos: VISTA POR CUOTA ───────────────────────────────────
if ($vista === 'cuotas') {
    $countSql = "
        SELECT COUNT(*)
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        WHERE $whereStr
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total     = (int) $stmtCount->fetchColumn();
    $totalPags = max(1, (int) ceil($total / $limit));

    $stmt = $pdo->prepare("
        SELECT
            cl.id AS cliente_id, cl.apellidos, cl.nombres, cl.zona, cl.telefono,
            COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
            cu.numero_cuota, cr.cant_cuotas,
            cu.monto_cuota, cu.saldo_pagado,
            (cu.monto_cuota - cu.saldo_pagado) AS monto_adeudado,
            DATEDIFF(CURDATE(), cu.fecha_vencimiento)           AS dias_atraso,
            cu.fecha_vencimiento,
            (SELECT MAX(pc.fecha_pago)
             FROM ic_pagos_confirmados pc
             JOIN ic_cuotas cu2 ON pc.cuota_id = cu2.id
             WHERE cu2.credito_id = cr.id)                     AS ultimo_pago,
            u.nombre AS cob_nombre, u.apellido AS cob_apellido,
            cr.id AS credito_id
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        JOIN ic_usuarios u       ON cr.cobrador_id = u.id
        WHERE $whereStr
        ORDER BY dias_atraso DESC, cl.apellidos ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $atrasados = $stmt->fetchAll();

// ── Datos: VISTA POR CLIENTE ─────────────────────────────────
} else {
    $countSql = "
        SELECT COUNT(DISTINCT cl.id)
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        WHERE $whereStr
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total     = (int) $stmtCount->fetchColumn();
    $totalPags = max(1, (int) ceil($total / $limit));

    $stmt = $pdo->prepare("
        SELECT
            cl.id AS cliente_id, cl.apellidos, cl.nombres, cl.zona, cl.telefono,
            COUNT(*)                                                AS cant_cuotas_vencidas,
            SUM(cu.monto_cuota - cu.saldo_pagado)                   AS total_adeudado,
            MAX(DATEDIFF(CURDATE(), cu.fecha_vencimiento))          AS max_dias_atraso,
            MIN(cu.fecha_vencimiento)                              AS fecha_vcto_peor,
            (SELECT MAX(pc.fecha_pago)
             FROM ic_pagos_confirmados pc
             JOIN ic_cuotas cu2 ON pc.cuota_id = cu2.id
             JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
             WHERE cr2.cliente_id = cl.id)                         AS ultimo_pago,
            IF(COUNT(DISTINCT cr.cobrador_id) = 1,
               MAX(CONCAT(u.apellido, ', ', u.nombre)),
               CONCAT(COUNT(DISTINCT cr.cobrador_id), ' cobradores')) AS cob_display
        FROM ic_cuotas cu
        JOIN ic_creditos cr ON cu.credito_id = cr.id
        JOIN ic_clientes cl ON cr.cliente_id = cl.id
        JOIN ic_usuarios u  ON cr.cobrador_id = u.id
        WHERE $whereStr
        GROUP BY cl.id, cl.apellidos, cl.nombres, cl.zona, cl.telefono
        ORDER BY total_adeudado DESC, cl.apellidos ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $atrasados = $stmt->fetchAll();
}

// ── Selectores de filtro ─────────────────────────────────────
$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' ORDER BY nombre"
)->fetchAll();

$zonas = $pdo->query(
    "SELECT DISTINCT zona FROM ic_clientes WHERE zona IS NOT NULL AND zona != '' ORDER BY zona"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Topbar ───────────────────────────────────────────────────
$qs_pdf = http_build_query(array_filter([
    'cobrador_id' => $cobrador_id ?: null,
    'zona'        => $zona_sel ?: null,
]));
$qs_csv = http_build_query(array_filter([
    'cobrador_id' => $cobrador_id ?: null,
    'zona'        => $zona_sel ?: null,
    'export'      => 'csv',
]));
$topbar_actions = '<a href="atrasados?' . e($qs_csv) . '"
    class="btn-ic btn-success btn-sm"><i class="fa fa-file-csv"></i> Exportar CSV</a>
    <a href="atrasados_pdf' . ($qs_pdf ? '?' . $qs_pdf : '') . '" target="_blank"
    class="btn-ic btn-danger btn-sm"><i class="fa fa-file-pdf"></i> Exportar PDF</a>';

$page_title   = 'Cuotas Atrasadas';
$page_current = 'atrasados';
require_once __DIR__ . '/../views/layout.php';

// ── Helper URL para toggle de vista (preserva filtros) ───────
function url_vista(string $v, array $get): string {
    return '?' . http_build_query(array_merge(
        array_filter(['cobrador_id' => $get['cobrador_id'] ?? '', 'zona' => $get['zona'] ?? '']),
        ['vista' => $v]
    ));
}
?>

<!-- FILTROS -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <input type="hidden" name="vista" value="<?= e($vista) ?>">
        <input type="hidden" name="desde" value="<?= e($desde) ?>">
        <input type="hidden" name="hasta" value="<?= e($hasta) ?>">
        <select name="cobrador_id">
            <option value="">Todos los cobradores</option>
            <?php foreach ($cobradores as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $cobrador_id === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= e($c['nombre'] . ' ' . $c['apellido']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="zona">
            <option value="">Todas las zonas</option>
            <?php foreach ($zonas as $z): ?>
                <option value="<?= e($z) ?>" <?= $zona_sel === $z ? 'selected' : '' ?>>
                    <?= e($z) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <a href="atrasados" class="btn-ic btn-ghost">Limpiar</a>
        <?php if ($cobrador_id > 0): ?>
        <a href="atrasados_clientes_pdf?<?= e(http_build_query(array_filter(['cobrador_id' => $cobrador_id, 'zona' => $zona_sel ?: null]))) ?>"
           target="_blank"
           class="btn-ic btn-ghost"
           style="margin-left:auto;border-color:rgba(239,68,68,.4);color:#ef4444">
            <i class="fa fa-file-pdf"></i> PDF Clientes
        </a>
        <?php endif; ?>
    </form>
</div>

<!-- RESUMEN POR COBRADOR -->
<?php if (!empty($resumen_cob)): ?>
<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px">
    <?php foreach ($resumen_cob as $rc): ?>
        <?php
        $pct_max = min(100, round(($rc['max_dias'] / 90) * 100));
        $col = $rc['max_dias'] > 30 ? 'var(--danger)' : ($rc['max_dias'] > 14 ? '#f97316' : 'var(--warning)');
        ?>
        <div style="flex:1;min-width:200px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;border-left:4px solid <?= $col ?>">
            <div style="font-weight:700;font-size:.95rem;margin-bottom:6px">
                <?= e($rc['nombre'] . ' ' . $rc['apellido']) ?>
            </div>
            <div style="font-size:1.2rem;font-weight:800;color:var(--danger)">
                <?= formato_pesos((float) $rc['total_adeudado']) ?>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap">
                <span><i class="fa fa-file-invoice"></i> <?= $rc['cant_cuotas'] ?> cuotas</span>
                <span><i class="fa fa-users"></i> <?= $rc['cant_clientes'] ?> clientes</span>
                <span style="color:<?= $col ?>"><i class="fa fa-clock"></i> máx. <?= $rc['max_dias'] ?> días</span>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- RESUMEN POR ZONA -->
<div class="card-ic mb-4">
    <div class="card-ic-header" style="flex-wrap:wrap;gap:8px">
        <span class="card-title"><i class="fa fa-map-location-dot"></i> Resumen por Zona</span>
        <span class="text-muted" style="font-size:.78rem">
            Cobrado del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?>
        </span>
    </div>

    <!-- Selector de período: sólo afecta la columna "Monto Cobrado" -->
    <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;flex-wrap:wrap;gap:8px">
        <span style="font-size:.75rem;color:var(--text-muted)">Período cobrado:</span>
        <?php $qs_base = array_filter(['cobrador_id' => $cobrador_id ?: null, 'zona' => $zona_sel ?: null, 'vista' => $vista]); ?>
        <a href="?<?= e(http_build_query(array_merge($qs_base, ['desde' => $primer_dia_mes, 'hasta' => $hoy]))) ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes' ? 'btn-primary' : 'btn-ghost' ?>">Mes actual</a>
        <a href="?<?= e(http_build_query(array_merge($qs_base, ['desde' => $mes_ant_ini, 'hasta' => $mes_ant_fin]))) ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'mes_ant' ? 'btn-primary' : 'btn-ghost' ?>">Mes anterior</a>
        <a href="?<?= e(http_build_query(array_merge($qs_base, ['desde' => $trim_ini, 'hasta' => $hoy]))) ?>"
           class="btn-ic btn-sm <?= $periodo_activo === 'trimestre' ? 'btn-primary' : 'btn-ghost' ?>">Último trimestre</a>
        <form method="GET" style="display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap">
            <input type="hidden" name="cobrador_id" value="<?= $cobrador_id ?>">
            <input type="hidden" name="zona" value="<?= e($zona_sel) ?>">
            <input type="hidden" name="vista" value="<?= e($vista) ?>">
            <input type="date" name="desde" value="<?= e($desde) ?>" max="<?= $hoy ?>" style="min-width:130px">
            <input type="date" name="hasta" value="<?= e($hasta) ?>" max="<?= $hoy ?>" style="min-width:130px">
            <button type="submit" class="btn-ic btn-ghost btn-sm"><i class="fa fa-search"></i></button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Zona</th>
                    <th class="text-right">Saldo</th>
                    <th class="text-center">Atraso (días)</th>
                    <th class="text-center">% Atrasados</th>
                    <th class="text-right">Monto Cobrado</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($por_zona)): ?>
                <tr><td colspan="5" class="text-center text-muted" style="padding:30px">
                    No hay datos de zona con los filtros aplicados.
                </td></tr>
            <?php else: $tot_saldo = 0.0; $tot_cobrado = 0.0; ?>
                <?php foreach ($por_zona as $zona_nombre => $dz):
                    $tot_saldo   += $dz['saldo'];
                    $tot_cobrado += $dz['monto_cobrado'];
                    $col_pct = $dz['pct_atrasados'] > 20 ? 'var(--danger)' : ($dz['pct_atrasados'] > 10 ? '#f97316' : 'var(--success)');
                ?>
                <tr>
                    <td><span class="badge" style="background:var(--info,#0ea5e9);color:#fff;font-size:.75rem"><?= e($zona_nombre) ?></span></td>
                    <td class="text-right fw-bold" style="color:var(--danger)"><?= formato_pesos($dz['saldo']) ?></td>
                    <td class="text-center"><?= $dz['max_dias'] ?> d.</td>
                    <td class="text-center" style="font-weight:700;color:<?= $col_pct ?>">
                        <?= $dz['pct_atrasados'] ?>%
                        <div style="font-size:.7rem;color:var(--text-muted);font-weight:400">
                            <?= $dz['clientes_atrasados'] ?>/<?= $dz['clientes_activos'] ?>
                        </div>
                    </td>
                    <td class="text-right" style="color:var(--success)"><?= formato_pesos($dz['monto_cobrado']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--bg-card);border-top:2px solid var(--border)">
                    <td class="text-right fw-bold">TOTALES</td>
                    <td class="text-right fw-bold" style="color:var(--danger)"><?= formato_pesos($tot_saldo) ?></td>
                    <td colspan="2"></td>
                    <td class="text-right fw-bold" style="color:var(--success)"><?= formato_pesos($tot_cobrado) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- TABLA -->
<div class="card-ic">
    <div class="card-ic-header" style="flex-wrap:wrap;gap:8px">
        <span class="card-title"><i class="fa fa-triangle-exclamation"></i> Cuotas Atrasadas</span>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span class="text-muted" style="font-size:.82rem">
                <?= number_format((int)$totales['total_cuotas']) ?> cuotas
                · <?= number_format((int)$totales['total_clientes']) ?> clientes
                · <strong style="color:var(--danger)"><?= formato_pesos((float)$totales['total_adeudado']) ?></strong>
            </span>
            <!-- Toggle vista -->
            <div style="display:flex;border:1px solid var(--border);border-radius:6px;overflow:hidden">
                <a href="<?= url_vista('cuotas', $_GET) ?>"
                   style="padding:4px 10px;font-size:.78rem;text-decoration:none;<?= $vista==='cuotas' ? 'background:var(--accent);color:#fff' : 'color:var(--text-muted)' ?>">
                    <i class="fa fa-list"></i> Por cuota
                </a>
                <a href="<?= url_vista('clientes', $_GET) ?>"
                   style="padding:4px 10px;font-size:.78rem;text-decoration:none;<?= $vista==='clientes' ? 'background:var(--accent);color:#fff' : 'color:var(--text-muted)' ?>">
                    <i class="fa fa-users"></i> Por cliente
                </a>
            </div>
        </div>
    </div>

    <!-- Filtros rápidos por nivel de riesgo (solo vista por cuota) -->
    <?php if ($vista === 'cuotas'): ?>
    <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span style="font-size:.78rem;color:var(--text-muted)">Riesgo:</span>
        <button class="btn-riesgo btn-ic btn-ghost btn-sm" data-nivel="todos" onclick="filtrarRiesgo('todos')" style="font-size:.75rem">
            Todos
        </button>
        <button class="btn-riesgo btn-ic btn-ghost btn-sm" data-nivel="critico" onclick="filtrarRiesgo('critico')" style="font-size:.75rem;color:#ef4444;border-color:#ef4444">
            🔴 Críticos &gt;30d
        </button>
        <button class="btn-riesgo btn-ic btn-ghost btn-sm" data-nivel="alerta" onclick="filtrarRiesgo('alerta')" style="font-size:.75rem;color:#f97316;border-color:#f97316">
            🟠 Alerta 15–30d
        </button>
        <button class="btn-riesgo btn-ic btn-ghost btn-sm" data-nivel="reciente" onclick="filtrarRiesgo('reciente')" style="font-size:.75rem;color:#eab308;border-color:#eab308">
            🟡 Recientes 1–14d
        </button>
        <span id="riesgo-count" style="font-size:.75rem;color:var(--text-muted);margin-left:4px"></span>
    </div>
    <?php endif; ?>

    <div class="table-responsive">

    <?php if ($vista === 'cuotas'): ?>
    <!-- ───── VISTA POR CUOTA ───── -->
    <table class="table-ic" id="tabla-atrasados">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th>Cliente</th>
                <th class="hide-mobile">Artículo</th>
                <th class="text-center">Cuota</th>
                <th class="text-right">Monto Adeudado</th>
                <th class="text-center">Días Atraso</th>
                <th class="text-center">Último Pago</th>
                <th class="hide-mobile">Cobrador</th>
                <th>Zona</th>
                <th class="text-center">WA</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($atrasados)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:40px">
                    No se encontraron cuotas atrasadas con los filtros aplicados.
                </td></tr>
            <?php else: ?>
                <?php foreach ($atrasados as $i => $r): ?>
                    <?php
                    $dias = dias_atraso_habiles($r['fecha_vencimiento']);
                    if ($dias > 25)     { $badge_bg = 'var(--danger)'; $badge_c = '#fff'; $nivel = 'critico'; }
                    elseif ($dias > 12) { $badge_bg = '#f97316';       $badge_c = '#fff'; $nivel = 'alerta'; }
                    else                { $badge_bg = '#eab308';       $badge_c = '#000'; $nivel = 'reciente'; }

                    $wa_msg = 'Hola ' . $r['nombres'] . ', le informamos que su cuota #' . $r['numero_cuota'] .
                        ' del artículo ' . $r['articulo'] . ' venció hace ' . $dias . ' días. ' .
                        'Monto adeudado: ' . formato_pesos((float)$r['monto_adeudado']) . '. ' .
                        'Por favor comuníquese con su cobrador. - Imperio Comercial';
                    ?>
                    <tr data-dias="<?= $dias ?>" data-nivel="<?= $nivel ?>">
                        <td class="text-center text-muted" style="font-size:.82rem"><?= $offset + $i + 1 ?></td>
                        <td>
                            <a href="../creditos/ver?id=<?= $r['credito_id'] ?>" target="_blank" style="font-weight:600">
                                <?= e($r['apellidos'] . ', ' . $r['nombres']) ?>
                                <i class="fa fa-arrow-up-right-from-square" style="font-size:.65rem;opacity:.5"></i>
                            </a>
                        </td>
                        <td class="text-muted hide-mobile" style="font-size:.88rem"><?= e($r['articulo']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary">#<?= $r['numero_cuota'] ?>/<?= $r['cant_cuotas'] ?></span>
                        </td>
                        <td class="text-right fw-bold" style="color:var(--danger)">
                            <?= formato_pesos((float) $r['monto_adeudado']) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge" style="background:<?= $badge_bg ?>;color:<?= $badge_c ?>;font-size:.8rem">
                                <?= $dias ?> d.
                            </span>
                        </td>
                        <td class="text-center text-muted" style="font-size:.88rem">
                            <?= $r['ultimo_pago'] ? date('d/m/Y', strtotime($r['ultimo_pago'])) : '<span style="color:#aaa">—</span>' ?>
                        </td>
                        <td class="hide-mobile" style="font-size:.88rem"><?= e($r['cob_apellido'] . ', ' . $r['cob_nombre']) ?></td>
                        <td>
                            <?php if ($r['zona']): ?>
                                <span class="badge" style="background:var(--info,#0ea5e9);color:#fff;font-size:.75rem">
                                    <?= e($r['zona']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($r['telefono'])): ?>
                                <a href="<?= whatsapp_url($r['telefono'], $wa_msg) ?>" target="_blank"
                                   class="btn-ic btn-ghost btn-sm btn-icon"
                                   title="Enviar WhatsApp"
                                   style="color:#22c55e;border-color:#22c55e">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- FILA TOTALES -->
                <tr style="background:var(--bg-card);border-top:2px solid var(--border)">
                    <td colspan="4" class="text-right fw-bold" style="padding:10px 8px">TOTALES</td>
                    <td class="text-right fw-bold" style="color:var(--danger);font-size:1.05rem">
                        <?= formato_pesos((float) $totales['total_adeudado']) ?>
                    </td>
                    <td colspan="5"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php else: ?>
    <!-- ───── VISTA POR CLIENTE ───── -->
    <table class="table-ic">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th>Cliente</th>
                <th class="text-center">Cuotas Vencidas</th>
                <th class="text-right">Total Adeudado</th>
                <th class="text-center">Máx. Días</th>
                <th class="text-center">Último Pago</th>
                <th class="hide-mobile">Cobrador</th>
                <th class="hide-mobile">Zona</th>
                <th class="text-center">WA</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($atrasados)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:40px">
                    No se encontraron clientes atrasados con los filtros aplicados.
                </td></tr>
            <?php else: ?>
                <?php foreach ($atrasados as $i => $r): ?>
                    <?php
                    $dias = !empty($r['fecha_vcto_peor']) ? dias_atraso_habiles($r['fecha_vcto_peor']) : (int) $r['max_dias_atraso'];
                    if ($dias > 25)     { $badge_bg = 'var(--danger)'; $badge_c = '#fff'; }
                    elseif ($dias > 12) { $badge_bg = '#f97316';       $badge_c = '#fff'; }
                    else                { $badge_bg = '#eab308';       $badge_c = '#000'; }

                    $wa_msg = 'Hola ' . $r['nombres'] . ', le informamos que tiene ' . $r['cant_cuotas_vencidas'] .
                        ' cuota(s) vencida(s) por un total de ' . formato_pesos((float)$r['total_adeudado']) . '. ' .
                        'Por favor comuníquese con su cobrador. - Imperio Comercial';
                    ?>
                    <tr>
                        <td class="text-center text-muted" style="font-size:.82rem"><?= $offset + $i + 1 ?></td>
                        <td>
                            <a href="../clientes/ver?id=<?= $r['cliente_id'] ?>" target="_blank" style="font-weight:600">
                                <?= e($r['apellidos'] . ', ' . $r['nombres']) ?>
                                <i class="fa fa-arrow-up-right-from-square" style="font-size:.65rem;opacity:.5"></i>
                            </a>
                        </td>
                        <td class="text-center">
                            <span class="badge" style="background:var(--danger);color:#fff">
                                <?= $r['cant_cuotas_vencidas'] ?>
                            </span>
                        </td>
                        <td class="text-right fw-bold" style="color:var(--danger)">
                            <?= formato_pesos((float) $r['total_adeudado']) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge" style="background:<?= $badge_bg ?>;color:<?= $badge_c ?>;font-size:.8rem">
                                <?= $dias ?> d.
                            </span>
                        </td>
                        <td class="text-center text-muted" style="font-size:.88rem">
                            <?= $r['ultimo_pago'] ? date('d/m/Y', strtotime($r['ultimo_pago'])) : '<span style="color:#aaa">—</span>' ?>
                        </td>
                        <td class="hide-mobile" style="font-size:.88rem"><?= e($r['cob_display']) ?></td>
                        <td class="hide-mobile">
                            <?php if ($r['zona']): ?>
                                <span class="badge" style="background:var(--info,#0ea5e9);color:#fff;font-size:.75rem">
                                    <?= e($r['zona']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($r['telefono'])): ?>
                                <a href="<?= whatsapp_url($r['telefono'], $wa_msg) ?>" target="_blank"
                                   class="btn-ic btn-ghost btn-sm btn-icon"
                                   title="Enviar WhatsApp"
                                   style="color:#22c55e;border-color:#22c55e">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- FILA TOTALES -->
                <tr style="background:var(--bg-card);border-top:2px solid var(--border)">
                    <td colspan="3" class="text-right fw-bold" style="padding:10px 8px">TOTALES</td>
                    <td class="text-right fw-bold" style="color:var(--danger);font-size:1.05rem">
                        <?= formato_pesos((float) $totales['total_adeudado']) ?>
                    </td>
                    <td colspan="5"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>

    </div><!-- overflow-x:auto -->

    <!-- PAGINACIÓN -->
    <?php if ($totalPags > 1): ?>
        <div class="pagination mt-3">
            <?php for ($p = 1; $p <= $totalPags; $p++): ?>
                <a href="<?= '?' . http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                   class="page-item <?= $p === $page ? 'active' : '' ?>">
                   <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$page_scripts = <<<'JS'
<script>
// ── Filtro rápido por nivel de riesgo ────────────────────────
function filtrarRiesgo(nivel) {
    var rows   = document.querySelectorAll('#tabla-atrasados tbody tr[data-nivel]');
    var count  = 0;

    rows.forEach(function(tr) {
        var show = (nivel === 'todos') || (tr.dataset.nivel === nivel);
        tr.style.display = show ? '' : 'none';
        if (show) count++;
    });

    document.querySelectorAll('.btn-riesgo').forEach(function(b) {
        var active = b.dataset.nivel === nivel;
        b.style.fontWeight = active ? '700' : '';
        b.style.opacity    = active ? '1' : '0.6';
    });

    var lbl = document.getElementById('riesgo-count');
    if (lbl) lbl.textContent = nivel !== 'todos' ? '(' + count + ' filas)' : '';
}
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
