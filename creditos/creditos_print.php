<?php
// ============================================================
// creditos/creditos_print.php — Listado de créditos para imprimir/PDF
// Respeta los mismos filtros que index.php
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$pdo        = obtener_conexion();
$q          = trim($_GET['q'] ?? '');
$estado     = trim($_GET['estado'] ?? '');
$frec       = trim($_GET['frecuencia'] ?? '');
$cobrador_f = (int) ($_GET['cobrador_id'] ?? 0);

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(cl.nombres LIKE ? OR cl.apellidos LIKE ? OR COALESCE(cr.articulo_desc, a.descripcion) LIKE ?)";
    $l = "%$q%";
    $params = array_merge($params, [$l, $l, $l]);
}
if ($estado !== '') {
    $where[] = 'cr.estado=?';
    $params[] = $estado;
}
if ($frec !== '') {
    $where[] = 'cr.frecuencia=?';
    $params[] = $frec;
}
if ($cobrador_f > 0) {
    $where[] = 'cr.cobrador_id=?';
    $params[] = $cobrador_f;
}
$whereStr = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA')    AS cuotas_pagadas,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado!='PAGADA')   AS cuotas_pendientes,
           (SELECT SUM(monto_cuota) FROM ic_cuotas WHERE credito_id=cr.id AND estado NOT IN ('PAGADA'))  AS saldo_pendiente,
           (SELECT GREATEST(0, DATEDIFF(CURDATE(), MIN(fecha_vencimiento))) FROM ic_cuotas WHERE credito_id=cr.id AND estado IN ('PENDIENTE', 'VENCIDA')) AS dias_atraso
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    WHERE $whereStr
    ORDER BY cr.fecha_alta DESC
");
$stmt->execute($params);
$creditos = $stmt->fetchAll();

// Etiquetas para el título
$frec_labels = ['semanal' => 'Semanales', 'quincenal' => 'Quincenales', 'mensual' => 'Mensuales'];
$tit_frec    = $frec !== '' ? ($frec_labels[$frec] ?? ucfirst($frec)) : 'Todos';
$tit_estado  = $estado !== '' ? $estado : 'Todos los estados';
$tit_cobrador = '';
if ($cobrador_f > 0) {
    $cob_row = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id=?");
    $cob_row->execute([$cobrador_f]);
    $cob_data = $cob_row->fetch();
    $tit_cobrador = $cob_data ? $cob_data['apellido'] . ', ' . $cob_data['nombre'] : '';
}

$totales_monto   = array_sum(array_column($creditos, 'monto_total'));
$totales_saldo   = array_sum(array_column($creditos, 'saldo_pendiente'));

function fmt_p(float $v): string {
    return '$ ' . number_format($v, 0, ',', '.');
}
$estados_label = ['EN_CURSO' => 'En Curso', 'FINALIZADO' => 'Finalizado', 'MOROSO' => 'Moroso', 'CANCELADO' => 'Cancelado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de Créditos — <?= e($tit_frec) ?></title>
    <style>
        :root { --border: #d1d5db; --text: #111827; --muted: #6b7280; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: var(--text);
            margin: 0;
            padding: 20px;
            background: #e5e7eb;
            display: flex;
            justify-content: center;
        }
        .a4-page {
            background: #fff;
            width: 210mm; /* A4 portrait */
            min-height: 297mm;
            padding: 12mm 14mm;
            box-shadow: 0 4px 6px rgba(0,0,0,.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid var(--text);
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .brand { font-size: 22px; font-weight: 800; text-transform: uppercase; margin: 0; }
        .header-meta { font-size: 12px; color: var(--muted); text-align: right; line-height: 1.6; }
        .filters-row {
            display: flex; gap: 20px; margin-bottom: 14px;
            background: #f9fafb; border: 1px solid var(--border);
            border-radius: 6px; padding: 8px 14px; font-size: 12px;
        }
        .filters-row span { color: var(--muted); }
        .filters-row strong { color: var(--text); }
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th {
            background: #f3f4f6; font-weight: 700; font-size: 10px;
            text-transform: uppercase; color: #374151;
            padding: 7px 8px; border-bottom: 2px solid var(--border);
            text-align: left;
        }
        td { padding: 7px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        .text-muted { color: var(--muted); font-size: 10px; }
        .badge {
            display: inline-block; padding: 2px 6px; border-radius: 3px;
            font-size: 9px; font-weight: 700; text-transform: uppercase; border: 1px solid;
        }
        .badge-en_curso   { color: #2563eb; border-color: #2563eb; }
        .badge-moroso     { color: #ef4444; border-color: #ef4444; }
        .badge-finalizado { color: #10b981; border-color: #10b981; }
        .badge-cancelado  { color: #6b7280; border-color: #6b7280; }
        .progress-bar-wrap {
            width: 50px; height: 4px; background: #e5e7eb;
            border-radius: 4px; display: inline-block; vertical-align: middle; margin-left: 4px;
        }
        .progress-bar-fill { height: 100%; background: #10b981; border-radius: 4px; }
        tfoot td { background: #f3f4f6; font-weight: 700; font-size: 11px; border-top: 2px solid var(--border); }
        .no-print { display: none; }
        .print-btn {
            display: inline-block; margin-bottom: 14px; padding: 8px 18px;
            background: #4f46e5; color: #fff; border: none; border-radius: 6px;
            font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        @media print {
            body { background: none; padding: 0; display: block; }
            .a4-page { box-shadow: none; padding: 10mm 12mm; width: 100%; }
            .no-print { display: none !important; }
            @page { size: A4 portrait; margin: 0; }
        }
    </style>
</head>
<body>

<div class="no-print" style="position:fixed;top:16px;right:20px;display:flex;gap:8px;z-index:999">
    <button class="print-btn" onclick="window.print()">
        🖨️ Imprimir / Guardar PDF
    </button>
    <a class="print-btn" style="background:#374151" href="index<?= ($frec||$estado||$q||$cobrador_f) ? '?'.http_build_query(array_filter(['frecuencia'=>$frec,'estado'=>$estado,'q'=>$q,'cobrador_id'=>$cobrador_f?:''])) : '' ?>" >
        ← Volver
    </a>
</div>

<div class="a4-page">
    <div class="header">
        <div>
            <p class="brand">Imperio Comercial</p>
            <div style="font-size:13px;margin-top:4px">Listado de Créditos — <?= e($tit_frec) ?></div>
        </div>
        <div class="header-meta">
            Generado: <?= date('d/m/Y H:i') ?><br>
            <?= count($creditos) ?> crédito<?= count($creditos) !== 1 ? 's' : '' ?>
        </div>
    </div>

    <div class="filters-row">
        <div><span>Frecuencia: </span><strong><?= e($tit_frec) ?></strong></div>
        <div><span>Estado: </span><strong><?= e($tit_estado) ?></strong></div>
        <?php if ($tit_cobrador): ?>
            <div><span>Cobrador: </span><strong><?= e($tit_cobrador) ?></strong></div>
        <?php endif; ?>
        <?php if ($q): ?>
            <div><span>Búsqueda: </span><strong>"<?= e($q) ?>"</strong></div>
        <?php endif; ?>
        <div><span>Total cartera: </span><strong><?= fmt_p($totales_monto) ?></strong></div>
        <div><span>Saldo pendiente: </span><strong><?= fmt_p($totales_saldo) ?></strong></div>
    </div>

    <?php if (empty($creditos)): ?>
        <p style="color:var(--muted);text-align:center;padding:40px">Sin resultados para los filtros seleccionados.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Cliente</th>
                <th>Artículo</th>
                <th class="text-right">Cuota</th>
                <th>Frec.</th>
                <th class="text-center">Avance</th>
                <th class="text-right">Saldo Pend.</th>
                <th class="text-center">Atraso</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($creditos as $index => $cr):
                $avance = $cr['cant_cuotas'] > 0 ? round($cr['cuotas_pagadas'] / $cr['cant_cuotas'] * 100) : 0;
                $est_lc = strtolower($cr['estado']);
            ?>
            <tr>
                <td class="text-muted">#<?= $index + 1 ?></td>
                <td>
                    <span class="fw-bold"><?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?></span><br>
                    <span class="text-muted"><?= e($cr['telefono']) ?></span>
                </td>
                <td><?= e($cr['articulo'] ?? '—') ?></td>
                <td class="text-right"><?= fmt_p($cr['monto_cuota']) ?></td>
                <td><?= ucfirst($cr['frecuencia']) ?></td>
                <td class="text-center">
                    <?= $cr['cuotas_pagadas'] ?>/<?= $cr['cant_cuotas'] ?>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill" style="width:<?= $avance ?>%"></div>
                    </div>
                </td>
                <td class="text-right <?= ($cr['saldo_pendiente'] ?? 0) > 0 ? '' : 'text-muted' ?>">
                    <?= fmt_p((float)($cr['saldo_pendiente'] ?? 0)) ?>
                </td>
                <td class="text-center">
                    <?php 
                    if ($cr['estado'] === 'FINALIZADO') {
                        echo '<span class="text-muted">Finalizado</span>';
                    } elseif ($cr['estado'] === 'CANCELADO') {
                        echo '<span class="text-muted">Cancelado</span>';
                    } else {
                        $dias = (int)($cr['dias_atraso'] ?? 0);
                        if ($dias > 0) {
                            echo "<span class='badge badge-moroso'>{$dias} días</span>";
                        } else {
                            echo "<span class='text-muted'>Al día</span>";
                        }
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" style="text-align:right;padding-right:10px;font-size:10px;letter-spacing:.05em">TOTAL SALDO PENDIENTE</td>
                <td class="text-right"><?= fmt_p($totales_saldo) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

</body>
</html>
