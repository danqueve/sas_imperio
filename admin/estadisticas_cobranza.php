<?php
// ============================================================
// admin/estadisticas_cobranza.php — Estadisticas de Cobradores
// Ventana Lun-Mié para los cobradores semanales (Enzo, JP, Sebastián),
// Lun-Sáb para el resto. Quincenal/Mensual = cartera vencida a hoy.
// Mismos criterios que admin/rendicion_pdf.php y cobrador/agenda_pdf.php.
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_estadisticas');

$pdo = obtener_conexion();

$USUARIOS_SEMANALES = ['enzoteceira', 'jpbicego', 'sebadelga'];

function color_pct(int $pct): string
{
    return $pct >= 80 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
}

// ── Calcula todas las métricas de cobradores para la semana dada ────
// Se comparte entre la pantalla y el export CSV para garantizar que
// ambos muestren siempre los mismos números.
function calcular_estadisticas(PDO $pdo, DateTimeImmutable $lunes_sel, array $usuarios_semanales): array
{
    $lunes_str  = $lunes_sel->format('Y-m-d');
    $mier_sel   = $lunes_sel->modify('+2 days')->format('Y-m-d');
    $sabado_sel = $lunes_sel->modify('+5 days')->format('Y-m-d');

    $cobradores = $pdo->query(
        "SELECT id, usuario, nombre, apellido FROM ic_usuarios
         WHERE rol = 'cobrador' AND activo = 1
         ORDER BY apellido, nombre"
    )->fetchAll();

    $ids_especiales = [];
    $ids_normales   = [];
    $data           = [];
    foreach ($cobradores as $c) {
        $es_semanal = in_array($c['usuario'], $usuarios_semanales, true);
        if ($es_semanal) {
            $ids_especiales[] = (int) $c['id'];
        } else {
            $ids_normales[] = (int) $c['id'];
        }
        $data[(int) $c['id']] = [
            'nombre'      => $c['nombre'],
            'apellido'    => $c['apellido'],
            'es_semanal'  => $es_semanal,
            'semanal'     => ['estimado' => 0.0, 'faltante' => 0.0, 'cobrados' => 0, 'faltan' => 0, 'criticos' => 0],
            'quincenal'   => ['estimado' => 0.0, 'faltante' => 0.0, 'clientes' => 0, 'faltan' => 0],
            'mensual'     => ['estimado' => 0.0, 'faltante' => 0.0, 'clientes' => 0, 'faltan' => 0],
            'efectivo'      => 0.0,
            'transferencia' => 0.0,
            'mora_cobrada'  => 0.0,
            'aprobado'      => 0.0,
            'pendiente'     => 0.0,
        ];
    }

    // ── Semanal: una consulta por grupo (ventana Lun-Mié / Lun-Sáb) ──
    $cargar_semanal = function (array $ids, string $desde, string $hasta) use ($pdo, &$data) {
        if (empty($ids)) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT cu.id, cu.estado, cu.monto_cuota, cu.monto_mora, cu.saldo_pagado, cu.fecha_vencimiento,
                   cr.cobrador_id, cr.cliente_id, cr.interes_moratorio_pct,
                   (SELECT COUNT(*)
                    FROM ic_cuotas cu2
                    JOIN ic_creditos cr2 ON cu2.credito_id = cr2.id
                    WHERE cr2.cliente_id = cr.cliente_id
                      AND cu2.estado IN ('PENDIENTE','VENCIDA','PARCIAL')
                      AND cr2.estado IN ('EN_CURSO','MOROSO')
                      AND cu2.fecha_vencimiento < CURDATE()
                      AND (cu2.monto_cuota - cu2.saldo_pagado) > 0
                   ) AS cuotas_atrasadas_cliente,
                   EXISTS(
                       SELECT 1 FROM ic_pagos_temporales pt2
                       WHERE pt2.cuota_id = cu.id AND pt2.estado IN ('PENDIENTE','APROBADO')
                         AND pt2.origen = 'cobrador'
                   ) AS tiene_pago
            FROM ic_cuotas cu
            JOIN ic_creditos cr ON cu.credito_id = cr.id
            WHERE cr.cobrador_id IN ($ph) AND cr.estado IN ('EN_CURSO','MOROSO')
              AND cr.frecuencia = 'semanal'
              AND cu.fecha_vencimiento BETWEEN ? AND ?
              AND cu.estado != 'CANCELADA'
        ");
        $stmt->execute([...$ids, $desde, $hasta]);

        $cobrados_set = [];
        $faltan_set   = [];
        $criticos_set = [];
        foreach ($stmt->fetchAll() as $cu) {
            $cid  = (int) $cu['cobrador_id'];
            $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
            $mora = (float) $cu['monto_mora'] > 0
                ? (float) $cu['monto_mora']
                : calcular_mora((float) $cu['monto_cuota'], $dias_atraso, (float) $cu['interes_moratorio_pct']);
            $data[$cid]['semanal']['estimado'] += (float) $cu['monto_cuota'] + $mora;

            $cobrado = ((float) $cu['saldo_pagado'] > 0)
                || in_array($cu['estado'], ['PAGADA', 'CAP_PAGADA'], true)
                || (bool) $cu['tiene_pago'];

            if ($cobrado) {
                $cobrados_set[$cid][$cu['cliente_id']] = true;
            } else {
                $faltan_set[$cid][$cu['cliente_id']] = true;
                if ((int) $cu['cuotas_atrasadas_cliente'] >= 5) {
                    $criticos_set[$cid][$cu['cliente_id']] = true;
                }
                $data[$cid]['semanal']['faltante'] += max(0, (float) $cu['monto_cuota'] + $mora - (float) $cu['saldo_pagado']);
            }
        }
        foreach ($cobrados_set as $cid => $set) $data[$cid]['semanal']['cobrados'] = count($set);
        foreach ($faltan_set as $cid => $set)   $data[$cid]['semanal']['faltan']   = count($set);
        foreach ($criticos_set as $cid => $set) $data[$cid]['semanal']['criticos'] = count($set);
    };

    $cargar_semanal($ids_especiales, $lunes_str, $mier_sel);
    $cargar_semanal($ids_normales,   $lunes_str, $sabado_sel);

    // ── Quincenal / Mensual: cartera vencida a hoy, todos los cobradores ──
    $ids_todos = array_merge($ids_especiales, $ids_normales);
    if (!empty($ids_todos)) {
        $ph = implode(',', array_fill(0, count($ids_todos), '?'));

        $stmt = $pdo->prepare("
            SELECT cr.cobrador_id, cr.frecuencia, cr.cliente_id, cr.interes_moratorio_pct,
                   cu.monto_cuota, cu.monto_mora, cu.saldo_pagado, cu.fecha_vencimiento
            FROM ic_cuotas cu
            JOIN ic_creditos cr ON cu.credito_id = cr.id
            WHERE cr.cobrador_id IN ($ph) AND cr.estado IN ('EN_CURSO','MOROSO')
              AND cr.frecuencia IN ('quincenal','mensual')
              AND cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
              AND cu.fecha_vencimiento <= CURDATE()
        ");
        $stmt->execute($ids_todos);

        $clientes_qm        = [];
        $clientes_qm_faltan = [];
        foreach ($stmt->fetchAll() as $cu) {
            $cid  = (int) $cu['cobrador_id'];
            $freq = $cu['frecuencia'];
            $dias_atraso = dias_atraso_habiles($cu['fecha_vencimiento']);
            $mora = (float) $cu['monto_mora'] > 0
                ? (float) $cu['monto_mora']
                : calcular_mora((float) $cu['monto_cuota'], $dias_atraso, (float) $cu['interes_moratorio_pct']);
            $estimado = (float) $cu['monto_cuota'] + $mora;
            $faltante = max(0, $estimado - (float) $cu['saldo_pagado']);

            $data[$cid][$freq]['estimado'] += $estimado;
            $data[$cid][$freq]['faltante'] += $faltante;
            $clientes_qm[$cid][$freq][$cu['cliente_id']] = true;
            if ($faltante > 0.005) {
                $clientes_qm_faltan[$cid][$freq][$cu['cliente_id']] = true;
            }
        }
        foreach ($clientes_qm as $cid => $porFreq) {
            foreach ($porFreq as $freq => $set) {
                $data[$cid][$freq]['clientes'] = count($set);
                $data[$cid][$freq]['faltan']   = count($clientes_qm_faltan[$cid][$freq] ?? []);
            }
        }

        // ── Efectivo / Transferencia / Mora cobrada (ventana Lun-Sáb, superset) ──
        $stmt2 = $pdo->prepare("
            SELECT cobrador_id,
                   SUM(monto_efectivo)      AS efectivo,
                   SUM(monto_transferencia) AS transferencia,
                   SUM(monto_mora_cobrada)  AS mora_cobrada,
                   SUM(CASE WHEN estado = 'APROBADO'  THEN monto_total ELSE 0 END) AS aprobado,
                   SUM(CASE WHEN estado = 'PENDIENTE' THEN monto_total ELSE 0 END) AS pendiente
            FROM ic_pagos_temporales
            WHERE cobrador_id IN ($ph)
              AND fecha_jornada BETWEEN ? AND ?
              AND estado IN ('PENDIENTE','APROBADO')
              AND origen = 'cobrador'
            GROUP BY cobrador_id
        ");
        $stmt2->execute([...$ids_todos, $lunes_str, $sabado_sel]);
        foreach ($stmt2->fetchAll() as $row) {
            $cid = (int) $row['cobrador_id'];
            if (!isset($data[$cid])) continue;
            $data[$cid]['efectivo']      = (float) $row['efectivo'];
            $data[$cid]['transferencia'] = (float) $row['transferencia'];
            $data[$cid]['mora_cobrada']  = (float) $row['mora_cobrada'];
            $data[$cid]['aprobado']      = (float) $row['aprobado'];
            $data[$cid]['pendiente']     = (float) $row['pendiente'];
        }
    }

    return [
        'data'           => $data,
        'ids_especiales' => $ids_especiales,
        'ids_normales'   => $ids_normales,
        'lunes_str'      => $lunes_str,
        'mier_sel'       => $mier_sel,
        'sabado_sel'     => $sabado_sel,
    ];
}

// ── Rango de la semana seleccionada ──────────────────────────
$hoy          = new DateTimeImmutable('today');
$dow          = (int) $hoy->format('N'); // 1=Lun … 7=Dom
$lunes_actual = $hoy->modify('-' . ($dow - 1) . ' days');

if (!empty($_GET['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semana'])) {
    try {
        $lunes_sel = new DateTimeImmutable($_GET['semana']);
        $dow_sel   = (int) $lunes_sel->format('N');
        $lunes_sel = $lunes_sel->modify('-' . ($dow_sel - 1) . ' days');
    } catch (Exception $e) {
        $lunes_sel = $lunes_actual;
    }
} else {
    $lunes_sel = $lunes_actual;
}

$es_semana_actual = ($lunes_sel->format('Y-m-d') === $lunes_actual->format('Y-m-d'));
$semana_ant_str   = $lunes_sel->modify('-7 days')->format('Y-m-d');
$semana_sig_str   = $lunes_sel->modify('+7 days')->format('Y-m-d');

$resultado      = calcular_estadisticas($pdo, $lunes_sel, $USUARIOS_SEMANALES);
$data           = $resultado['data'];
$ids_especiales = $resultado['ids_especiales'];
$ids_normales   = $resultado['ids_normales'];
$lunes_str      = $resultado['lunes_str'];
$mier_sel       = $resultado['mier_sel'];
$sabado_sel     = $resultado['sabado_sel'];

// ── Export CSV ─────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="estadisticas_cobranza_' . $lunes_str . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Cobrador', 'Grupo', 'Bloque', 'Clientes', 'Cobrados', 'Faltan', 'Criticos', 'Monto Estimado', 'Monto Cobrado', 'Monto Faltante'], ';', '"', '\\');

    foreach ($data as $c) {
        $nombre_completo = $c['apellido'] . ', ' . $c['nombre'];
        $grupo = $c['es_semanal'] ? 'Semanal (Lun-Mie)' : 'Normal (Lun-Sab)';

        $sem = $c['semanal'];
        $cobrado_sem = $sem['estimado'] - $sem['faltante'];
        fputcsv($out, [
            $nombre_completo, $grupo, 'Semanal',
            $sem['cobrados'] + $sem['faltan'], $sem['cobrados'], $sem['faltan'], $sem['criticos'],
            number_format($sem['estimado'], 2, ',', '.'), number_format($cobrado_sem, 2, ',', '.'), number_format($sem['faltante'], 2, ',', '.'),
        ], ';', '"', '\\');

        foreach (['quincenal' => 'Quincenal', 'mensual' => 'Mensual'] as $key => $label) {
            $q = $c[$key];
            $cobrado_q  = $q['estimado'] - $q['faltante'];
            $cobrados_q = max(0, $q['clientes'] - $q['faltan']);
            fputcsv($out, [
                $nombre_completo, $grupo, $label,
                $q['clientes'], $cobrados_q, $q['faltan'], '',
                number_format($q['estimado'], 2, ',', '.'), number_format($cobrado_q, 2, ',', '.'), number_format($q['faltante'], 2, ',', '.'),
            ], ';', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

// ── KPIs globales (bloque semanal, todos los cobradores) ─────
$g_estimado = 0.0;
$g_cobrado  = 0.0;
$g_faltan   = 0;
$g_criticos = 0;
foreach ($data as $c) {
    $sem = $c['semanal'];
    $g_estimado += $sem['estimado'];
    $g_cobrado  += $sem['estimado'] - $sem['faltante'];
    $g_faltan   += $sem['faltan'];
    $g_criticos += $sem['criticos'];
}
$g_pct = $g_estimado > 0 ? round($g_cobrado / $g_estimado * 100) : 0;

// ── Datos para el gráfico Estimado vs Cobrado por cobrador ───
$chart_labels   = [];
$chart_estimado = [];
$chart_cobrado  = [];
foreach ($data as $c) {
    $sem = $c['semanal'];
    $chart_labels[]   = $c['apellido'] . ', ' . mb_substr($c['nombre'], 0, 1) . '.';
    $chart_estimado[] = round($sem['estimado'], 2);
    $chart_cobrado[]  = round($sem['estimado'] - $sem['faltante'], 2);
}

$page_title     = 'Estadísticas de Cobranza';
$page_current   = 'estadisticas';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="font-size:.78rem;color:var(--text-muted);margin-bottom:10px">
    <i class="fa fa-circle-info"></i> Solo pagos cargados por el cobrador en su agenda (no incluye pagos directos de admin/supervisor).
</div>

<!-- ── SELECTOR DE SEMANA ─────────────────────────────────────── -->
<div class="card-ic mb-4">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <a href="?semana=<?= urlencode($semana_ant_str) ?>"
           class="btn-ic btn-ghost btn-sm" title="Semana anterior">
            <i class="fa fa-chevron-left"></i>
        </a>
        <div style="flex:1;text-align:center">
            <div style="font-size:1rem;font-weight:700;color:var(--text-main)">
                <?= $lunes_sel->format('d/m/Y') ?> — <?= $lunes_sel->modify('+5 days')->format('d/m/Y') ?>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                <?php if ($es_semana_actual): ?>
                    <span style="color:var(--success)"><i class="fa fa-circle" style="font-size:.5rem;vertical-align:middle"></i> Semana actual</span>
                <?php else: ?>
                    Semana <?= $lunes_sel->format('W') ?> de <?= $lunes_sel->format('Y') ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$es_semana_actual): ?>
        <a href="?semana=<?= urlencode($semana_sig_str) ?>"
           class="btn-ic btn-ghost btn-sm" title="Semana siguiente">
            <i class="fa fa-chevron-right"></i>
        </a>
        <?php else: ?>
        <span class="btn-ic btn-ghost btn-sm" style="opacity:.3;cursor:default;pointer-events:none">
            <i class="fa fa-chevron-right"></i>
        </span>
        <?php endif; ?>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <input type="date" name="semana" value="<?= e($lunes_str) ?>"
                   style="min-width:150px" max="<?= $lunes_actual->format('Y-m-d') ?>">
            <button type="submit" class="btn-ic btn-ghost btn-sm">
                <i class="fa fa-search"></i> Ir
            </button>
        </form>
        <?php if (!$es_semana_actual): ?>
        <a href="estadisticas_cobranza" class="btn-ic btn-primary btn-sm">
            <i class="fa fa-calendar-check"></i> Semana actual
        </a>
        <?php endif; ?>
        <a href="estadisticas_cobranza?semana=<?= urlencode($lunes_str) ?>&export=csv"
           class="btn-ic btn-success btn-sm" title="Exportar estadísticas a CSV">
            <i class="fa fa-file-csv"></i> CSV
        </a>
        <a href="estadisticas_pdf?semana=<?= urlencode($lunes_str) ?>"
           class="btn-ic btn-ghost btn-sm" target="_blank" title="Exportar estadísticas a PDF">
            <i class="fa fa-file-pdf"></i> PDF
        </a>
    </div>
</div>

<!-- ── KPIs GLOBALES ─────────────────────────────────────────── -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(255,167,11,.15);--icon-color:#FFA70B">
            <i class="fa fa-dollar-sign"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Estimado Semana</div>
            <div class="kpi-value" style="font-size:1.3rem"><?= formato_pesos($g_estimado) ?></div>
            <div class="kpi-sub">Lun-Mié / Lun-Sáb según cobrador</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981">
            <i class="fa fa-sack-dollar"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cobrado Semana</div>
            <div class="kpi-value" style="font-size:1.3rem;color:var(--success)"><?= formato_pesos($g_cobrado) ?></div>
            <div class="kpi-sub">
                <span style="color:<?= color_pct($g_pct) ?>;font-weight:700"><?= $g_pct ?>% recaudado</span>
            </div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(60,80,224,.15);--icon-color:#3C50E0">
            <i class="fa fa-user-clock"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Clientes Faltan</div>
            <div class="kpi-value" style="font-size:1.3rem"><?= $g_faltan ?></div>
            <div class="kpi-sub">de la semana en curso</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(211,64,83,.15);--icon-color:#D34053">
            <i class="fa fa-triangle-exclamation"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Críticos (5+ atr.)</div>
            <div class="kpi-value" style="font-size:1.3rem;color:<?= $g_criticos > 0 ? 'var(--danger)' : 'var(--text-main)' ?>"><?= $g_criticos ?></div>
            <div class="kpi-sub">clientes con 5+ cuotas atrasadas</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(0,181,226,.15);--icon-color:#00B5E2">
            <i class="fa fa-users"></i>
        </div>
        <div class="kpi-body">
            <div class="kpi-label">Cobradores</div>
            <div class="kpi-value" style="font-size:1.3rem"><?= count($data) ?></div>
            <div class="kpi-sub"><?= count($ids_especiales) ?> semanales, <?= count($ids_normales) ?> normales</div>
        </div>
    </div>
</div>

<!-- ── GRÁFICO ESTIMADO VS COBRADO ────────────────────────────── -->
<?php if (!empty($data)): ?>
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-chart-bar"></i> Estimado vs Cobrado — Semana por cobrador</span>
    </div>
    <div style="position:relative;height:280px;padding:6px 0">
        <canvas id="chart-estimado-cobrado"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- ── TARJETAS POR COBRADOR, AGRUPADAS ───────────────────────── -->
<?php if (empty($data)): ?>
    <div class="card-ic"><p class="text-muted text-center" style="padding:30px">No hay cobradores activos.</p></div>
<?php endif; ?>

<?php
$grupos = [
    [
        'titulo'    => 'Cobradores Semanales (Lun-Mié)',
        'icono'     => 'fa-calendar-week',
        'ids'       => $ids_especiales,
        'fin_fecha' => $mier_sel,
        'fin_label' => 'Mié',
    ],
    [
        'titulo'    => 'Cobradores Normales (Lun-Sáb)',
        'icono'     => 'fa-calendar-days',
        'ids'       => $ids_normales,
        'fin_fecha' => $sabado_sel,
        'fin_label' => 'Sáb',
    ],
];

foreach ($grupos as $grupo):
    if (empty($grupo['ids'])) continue;
?>
<h2 style="font-size:1rem;font-weight:700;margin:28px 0 14px;display:flex;align-items:center;gap:8px;color:var(--text-main)">
    <i class="fa <?= $grupo['icono'] ?>" style="color:var(--primary)"></i>
    <?= e($grupo['titulo']) ?>
    <span style="font-size:.75rem;font-weight:400;color:var(--text-muted)">
        (Lun <?= date('d/m', strtotime($lunes_str)) ?> — <?= $grupo['fin_label'] ?> <?= date('d/m', strtotime($grupo['fin_fecha'])) ?>)
    </span>
</h2>

<?php foreach ($grupo['ids'] as $cob_id):
    $c   = $data[$cob_id];
    $sem = $c['semanal'];
    $qui = $c['quincenal'];
    $men = $c['mensual'];

    $cobrado_sem = $sem['estimado'] - $sem['faltante'];
    $pct_sem     = $sem['estimado'] > 0 ? round($cobrado_sem / $sem['estimado'] * 100) : 0;
    $color_sem   = color_pct($pct_sem);
    $iniciales   = strtoupper(mb_substr($c['nombre'], 0, 1) . mb_substr($c['apellido'], 0, 1));
?>
<div class="card-ic mb-4">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px">
        <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;flex-shrink:0;color:#fff">
            <?= $iniciales ?>
        </div>
        <div style="min-width:160px">
            <div style="font-weight:700;font-size:1rem"><?= e($c['apellido'] . ', ' . $c['nombre']) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">
                Semana <?= date('d/m', strtotime($lunes_str)) ?> — <?= date('d/m', strtotime($c['es_semanal'] ? $mier_sel : $sabado_sel)) ?>
            </div>
        </div>
        <div style="min-width:170px;max-width:240px;flex:1">
            <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-muted);margin-bottom:3px">
                <span>Estimado &rarr; Cobrado</span>
                <span style="color:<?= $color_sem ?>;font-weight:700"><?= $pct_sem ?>%</span>
            </div>
            <div style="background:rgba(255,255,255,.1);border-radius:99px;height:7px;overflow:hidden">
                <div style="width:<?= $pct_sem ?>%;height:100%;background:<?= $color_sem ?>;border-radius:99px;transition:width .4s"></div>
            </div>
        </div>
    </div>

    <!-- KPIs pares semanal -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--dark-border);border:1px solid var(--dark-border);border-radius:8px;overflow:hidden;margin-bottom:14px">
        <div style="background:var(--dark-card);padding:10px 12px">
            <div style="font-size:.66rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em">Cobrados</div>
            <div style="font-weight:800;font-size:1.05rem;color:var(--success)"><?= $sem['cobrados'] ?></div>
        </div>
        <div style="background:var(--dark-card);padding:10px 12px">
            <div style="font-size:.66rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em">Faltan cobrar</div>
            <div style="font-weight:800;font-size:1.05rem"><?= $sem['faltan'] ?></div>
        </div>
        <div style="background:var(--dark-card);padding:10px 12px">
            <div style="font-size:.66rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em">Críticos (5+ atr.)</div>
            <div style="font-weight:800;font-size:1.05rem;color:<?= $sem['criticos'] > 0 ? 'var(--danger)' : 'var(--text-main)' ?>"><?= $sem['criticos'] ?></div>
        </div>
        <div style="background:var(--dark-card);padding:10px 12px">
            <div style="font-size:.66rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em">Estimado Semana</div>
            <div style="font-weight:800;font-size:1.05rem"><?= formato_pesos($sem['estimado']) ?></div>
        </div>
    </div>

    <!-- 3 mini-bloques: Semanal / Quincenal / Mensual -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <?php
        $bloques = [
            ['label' => 'Semanal',   'icono' => 'fa-calendar-week', 'estimado' => $sem['estimado'], 'faltante' => $sem['faltante'], 'faltan_cli' => $sem['faltan']],
            ['label' => 'Quincenal', 'icono' => 'fa-calendar-days', 'estimado' => $qui['estimado'], 'faltante' => $qui['faltante'], 'faltan_cli' => $qui['faltan']],
            ['label' => 'Mensual',   'icono' => 'fa-calendar',      'estimado' => $men['estimado'], 'faltante' => $men['faltante'], 'faltan_cli' => $men['faltan']],
        ];
        foreach ($bloques as $b):
            $cobrado_b = $b['estimado'] - $b['faltante'];
            $pct_b     = $b['estimado'] > 0 ? round($cobrado_b / $b['estimado'] * 100) : 0;
            $color_b   = color_pct($pct_b);
        ?>
        <div style="flex:1;min-width:180px;background:rgba(0,0,0,.2);border-radius:10px;padding:12px 14px;border:1px solid rgba(255,255,255,.07)">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px">
                <i class="fa <?= $b['icono'] ?>" style="color:var(--primary);font-size:.85rem"></i>
                <span style="font-size:.8rem;font-weight:700;color:var(--text-main)"><?= $b['label'] ?></span>
            </div>
            <?php if ($b['estimado'] > 0.005): ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:6px">
                <div style="font-size:.75rem;color:var(--text-muted)"><?= $b['faltan_cli'] ?> cli. faltan</div>
                <div style="font-size:.82rem;font-weight:800;color:<?= $color_b ?>"><?= $pct_b ?>%</div>
            </div>
            <div style="background:rgba(255,255,255,.1);border-radius:99px;height:5px;overflow:hidden;margin-bottom:8px">
                <div style="width:<?= $pct_b ?>%;height:100%;background:<?= $color_b ?>;border-radius:99px"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.75rem">
                <span style="color:var(--text-muted)">Est: <?= formato_pesos($b['estimado']) ?></span>
                <span style="color:var(--success);font-weight:700">Cob: <?= formato_pesos($cobrado_b) ?></span>
            </div>
            <?php else: ?>
            <div style="font-size:.75rem;color:var(--text-muted)">Sin cartera <?= mb_strtolower($b['label']) ?> vencida</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Efectivo / Transferencia / Mora -->
    <div style="display:flex;gap:24px;flex-wrap:wrap;padding-top:12px;border-top:1px solid rgba(255,255,255,.06);font-size:.8rem">
        <div><span style="color:var(--text-muted)">Efectivo:</span> <strong><?= formato_pesos($c['efectivo']) ?></strong></div>
        <div><span style="color:var(--text-muted)">Transferencia:</span> <strong><?= formato_pesos($c['transferencia']) ?></strong></div>
        <?php if ($c['mora_cobrada'] > 0): ?>
        <div><span style="color:var(--text-muted)">Mora Cobrada:</span> <strong style="color:var(--warning)"><?= formato_pesos($c['mora_cobrada']) ?></strong></div>
        <?php endif; ?>
        <?php if ($c['pendiente'] > 0): ?>
        <div title="Cobrado por el cobrador pero todavia no aprobado por admin/supervisor en Rendiciones">
            <span style="color:var(--text-muted)">Pendiente de aprobar:</span> <strong style="color:var(--warning)"><?= formato_pesos($c['pendiente']) ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<?php
$js_labels   = json_encode($chart_labels);
$js_estimado = json_encode($chart_estimado);
$js_cobrado  = json_encode($chart_cobrado);

$page_scripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color       = 'rgba(255,255,255,.6)';
Chart.defaults.borderColor = 'rgba(255,255,255,.07)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size   = 12;

const chartEl = document.getElementById('chart-estimado-cobrado');
if (chartEl) {
    new Chart(chartEl, {
        type: 'bar',
        data: {
            labels: $js_labels,
            datasets: [
                {
                    label: 'Estimado',
                    data: $js_estimado,
                    backgroundColor: 'rgba(160,174,191,.35)',
                    borderColor: 'rgba(160,174,191,.6)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Cobrado',
                    data: $js_cobrado,
                    backgroundColor: 'rgba(16,185,129,.75)',
                    borderColor: '#10b981',
                    borderWidth: 1,
                    borderRadius: 4,
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { boxWidth: 12, boxHeight: 12, borderRadius: 3, useBorderRadius: true, padding: 14 }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': \$ ' +
                            ctx.raw.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0})
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    grid: { color: 'rgba(255,255,255,.06)' },
                    ticks: { callback: v => v >= 1000 ? '\$' + (v/1000).toFixed(0)+'k' : '\$'+v }
                }
            }
        }
    });
}
</script>
HTML;
require_once __DIR__ . '/../views/layout_footer.php';
?>
