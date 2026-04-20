<?php
// ============================================================
// cobrador/agenda.php — Vista diaria del cobrador
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo = obtener_conexion();
$hoy_dt = new DateTime('today');
$dia_semana = (int) $hoy_dt->format('N'); // 1=Lun…7=Dom
$hoy = $hoy_dt->format('Y-m-d');
$user_id = $_SESSION['user_id'];
$is_cobrador = es_cobrador();

// Si es domingo (7) no hay agenda
$dia_laboral = $dia_semana !== 7;

// Jornadas disponibles para registrar pagos ahora
$jornadas_disp     = jornadas_disponibles();
$es_modo_finde     = count($jornadas_disp) > 1; // Lunes antes de 10 AM
$jornada_principal = $jornadas_disp[0];

// Filtro de cobrador (admin/supervisor pueden ver a otros)
$cobrador_filtro = $is_cobrador ? $user_id : (int) ($_GET['cobrador_id'] ?? $user_id);
$q_busca = trim($_GET['q'] ?? '');

// Cuotas del día: vencen hoy (semanal con dia_cobro = hoy)
// También incluye cuotas vencidas pendientes del cobrador
$condCobrador = 'AND cr.cobrador_id = ?';

$sql = "
    SELECT cu.*, cr.id AS credito_id, cr.frecuencia, cr.interes_moratorio_pct, cr.cobrador_id,
           cr.cant_cuotas, cr.dia_cobro, cr.veces_refinanciado,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen,
           (SELECT pt2.id FROM ic_pagos_temporales pt2 WHERE pt2.cuota_id=cu.id AND pt2.estado='PENDIENTE' LIMIT 1) AS pt_id
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
      AND cr.estado IN ('EN_CURSO','MOROSO')
      $condCobrador
    ORDER BY cu.fecha_vencimiento ASC, cl.apellidos ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$cobrador_filtro]);
$todas = $stmt->fetchAll();

// ── Calcular riesgo en batch (una vez por crédito) ────────
$riesgo_cache = [];
foreach (array_unique(array_column($todas, 'credito_id')) as $crid) {
    $riesgo_cache[$crid] = calcular_riesgo_credito_activo((int)$crid, $pdo);
}
foreach ($todas as &$_t) {
    $_t['riesgo'] = $riesgo_cache[$_t['credito_id']] ?? 1;
}
unset($_t);

// Calcular mora y separar: del día vs vencidas
$del_dia = [];
$vencidas = [];
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

foreach ($todas as $c) {
    $dias_atraso = dias_atraso_habiles($c['fecha_vencimiento']);
    // CAP_PAGADA: mora ya congelada en monto_mora (no recalcular)
    $mora = ($c['estado'] === 'CAP_PAGADA')
        ? (float) $c['monto_mora']
        : calcular_mora($c['monto_cuota'], $dias_atraso, $c['interes_moratorio_pct']);
    $c['dias_atraso_calc'] = $dias_atraso;
    $c['mora_calc'] = $mora;
    // CAP_PAGADA: solo mora congelada | PARCIAL: descontar saldo ya pagado
    $saldo_prev = (float)($c['saldo_pagado'] ?? 0);
    $c['total_a_cobrar'] = ($c['estado'] === 'CAP_PAGADA')
        ? $mora
        : max(0, $c['monto_cuota'] + $mora - $saldo_prev);

    if ($dias_atraso === 0 && $c['fecha_vencimiento'] === $hoy) {
        $del_dia[] = $c;
    } elseif ($dias_atraso > 0) {
        $vencidas[] = $c;
    } elseif ($c['fecha_vencimiento'] === $hoy) {
        $del_dia[] = $c;
    } elseif (
        $c['frecuencia'] === 'semanal'
        && (int) $c['dia_cobro'] === $dia_semana
        && $c['fecha_vencimiento'] > $hoy
    ) {
        // Cliente adelantado: su próxima cuota aún no vence pero hoy es su día de cobro
        $c['adelantado'] = true;
        $del_dia[] = $c;
    }
}

// ── Guardar totales originales para KPIs (antes de deduplicar) ─
$kpi_del_dia   = count($del_dia);
$kpi_vencidas  = count($vencidas);
$kpi_total_hoy = array_sum(array_map(fn($c) => $c['total_a_cobrar'], $del_dia));
$kpi_mora_venc = array_sum(array_map(fn($c) => $c['mora_calc'], $vencidas));

// KPI INGRESOS (cubre todas las jornadas disponibles: fin de semana o solo hoy)
$_ph_ingresos = implode(',', array_fill(0, count($jornadas_disp), '?'));
$stmt_ingresos = $pdo->prepare("
    SELECT SUM(monto_total) AS total,
           SUM(monto_efectivo) AS efectivo,
           SUM(monto_transferencia) AS transferencia,
           SUM(monto_mora_cobrada) AS mora_cobrada
    FROM ic_pagos_temporales
    WHERE cobrador_id = ? AND fecha_jornada IN ($_ph_ingresos) AND estado = 'PENDIENTE'
");
$stmt_ingresos->execute(array_merge([$cobrador_filtro], $jornadas_disp));
$row_ingresos = $stmt_ingresos->fetch();
$kpi_ingresos_hoy = (float) $row_ingresos['total'];
$kpi_ingresos_efectivo = (float) $row_ingresos['efectivo'];
$kpi_ingresos_transferencia = (float) $row_ingresos['transferencia'];
$kpi_mora_cobrada_hoy = (float) $row_ingresos['mora_cobrada'];

// KPI POR RENDIR: todos los PENDIENTE del cobrador (cualquier jornada)
$stmt_por_rendir = $pdo->prepare("
    SELECT COALESCE(SUM(monto_total), 0)        AS total,
           COALESCE(SUM(monto_efectivo), 0)      AS efectivo,
           COALESCE(SUM(monto_transferencia), 0) AS transferencia,
           COUNT(DISTINCT fecha_jornada)          AS cant_jornadas
    FROM ic_pagos_temporales
    WHERE cobrador_id = ? AND estado = 'PENDIENTE'
");
$stmt_por_rendir->execute([$cobrador_filtro]);
$row_por_rendir          = $stmt_por_rendir->fetch();
$kpi_por_rendir_total    = (float) $row_por_rendir['total'];
$kpi_por_rendir_efectivo = (float) $row_por_rendir['efectivo'];
$kpi_por_rendir_transfer = (float) $row_por_rendir['transferencia'];
$kpi_por_rendir_jornadas = (int)   $row_por_rendir['cant_jornadas'];

// ── Lista de Cobrados (cubre todas las jornadas disponibles) ───
$_ph_cobrados = implode(',', array_fill(0, count($jornadas_disp), '?'));
$stmt_cobrados = $pdo->prepare("
    SELECT cu.*, cr.id AS credito_id, cr.frecuencia, cr.interes_moratorio_pct, cr.cobrador_id,
           cr.cant_cuotas,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           pt.id AS pt_id,
           pt.fecha_jornada AS jornada_pago,
           1 AS pago_pen
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas cu ON pt.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE pt.cobrador_id = ? AND pt.fecha_jornada IN ($_ph_cobrados) AND pt.estado = 'PENDIENTE'
    ORDER BY pt.fecha_jornada ASC, pt.fecha_registro DESC
");
$stmt_cobrados->execute(array_merge([$cobrador_filtro], $jornadas_disp));
$cobrados_hoy_raw = $stmt_cobrados->fetchAll();

$cobrados_hoy = [];
foreach ($cobrados_hoy_raw as $c) {
    $dias_atraso = dias_atraso_habiles($c['fecha_vencimiento']);
    $mora = ($c['estado'] === 'CAP_PAGADA')
        ? (float) $c['monto_mora']
        : calcular_mora($c['monto_cuota'], $dias_atraso, $c['interes_moratorio_pct']);
    $c['dias_atraso_calc'] = $dias_atraso;
    $c['mora_calc']        = $mora;
    $c['total_a_cobrar']   = ($c['estado'] === 'CAP_PAGADA') ? $mora : $c['monto_cuota'] + $mora;
    $cobrados_hoy[] = $c;
}

// ── Deduplicar: un registro por cliente ────────────────────────
// Si un cliente tiene varias cuotas atrasadas, aparece una sola vez
// con un badge que indica cuántas cuotas acumula.
// Clave = credito_id para que clientes con varios créditos aparezcan como tarjetas separadas.
$venc_por_credito = [];
foreach ($vencidas as $v) {
    $venc_por_credito[$v['credito_id']][] = $v;
}

// del_dia: conservar la cuota de hoy, agregar count de atrasadas extras
$del_dia_dedup = [];
foreach ($del_dia as $c) {
    $cid = $c['credito_id'];
    if (!isset($del_dia_dedup[$cid])) {
        $cuotas_venc = $venc_por_credito[$cid] ?? [];
        $c['cuotas_atrasadas'] = count($cuotas_venc);
        // Total real: suma de todas las vencidas + la cuota de hoy
        $total_venc = array_sum(array_map(fn($v) => $v['total_a_cobrar'], $cuotas_venc));
        $c['total_acumulado'] = $total_venc + $c['total_a_cobrar'];
        $del_dia_dedup[$cid]  = $c;
    }
}
$del_dia = array_values($del_dia_dedup);

// vencidas: excluir créditos ya mostrados en del_dia, deduplicar el resto
$vencidas = [];
foreach ($venc_por_credito as $cid => $cuotas) {
    if (isset($del_dia_dedup[$cid])) continue; // ya aparece arriba con badge
    $row = $cuotas[0]; // más antigua primero (ORDER BY fecha_vencimiento ASC)
    $row['cuotas_atrasadas'] = count($cuotas);
    // Total real acumulado de todas las cuotas vencidas
    $row['total_acumulado'] = array_sum(array_map(fn($v) => $v['total_a_cobrar'], $cuotas));
    $vencidas[] = $row;
}

// ── Vista semanal: clientes por dia_cobro ─────────────────────
$semana_stmt = $pdo->prepare("
    SELECT cu.id,
           cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona,
           cl.coordenadas,
           cr.id AS credito_id, cr.interes_moratorio_pct, cr.cant_cuotas, cr.dia_cobro, cr.veces_refinanciado,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado, cu.monto_mora, cu.saldo_pagado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen,
           (SELECT pt2.id FROM ic_pagos_temporales pt2 WHERE pt2.cuota_id=cu.id AND pt2.estado='PENDIENTE' LIMIT 1) AS pt_id
    FROM ic_clientes cl
    JOIN ic_creditos cr ON cr.cliente_id  = cl.id AND cr.cobrador_id = ? AND cr.estado IN ('EN_CURSO','MOROSO')
                       AND cr.frecuencia = 'semanal'
    JOIN ic_cuotas  cu ON cu.credito_id   = cr.id AND cu.estado IN ('PENDIENTE','VENCIDA','CAP_PAGADA','PARCIAL')
    LEFT JOIN ic_articulos a ON a.id           = cr.articulo_id
    WHERE cr.dia_cobro BETWEEN 1 AND 6
    ORDER BY cr.dia_cobro ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$semana_stmt->execute([$cobrador_filtro]);
$semana_rows_raw = $semana_stmt->fetchAll();

// Calcular mora para cada fila semanal
$semana_rows = [];
$semana_por_credito = [];

foreach ($semana_rows_raw as $r) {
    $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);
    $mora        = ($r['estado'] === 'CAP_PAGADA')
        ? (float) $r['monto_mora']
        : calcular_mora($r['monto_cuota'], $dias_atraso, $r['interes_moratorio_pct']);
    $r['dias_atraso_calc'] = $dias_atraso;
    $r['mora_calc']        = $mora;
    $saldo_prev_s = (float)($r['saldo_pagado'] ?? 0);
    $r['total_a_cobrar']   = ($r['estado'] === 'CAP_PAGADA') ? $mora : max(0, $r['monto_cuota'] + $mora - $saldo_prev_s);
    $r['pago_pen']         = $r['pago_pen'] ?? 0;

    // Agrupar por crédito para deduplicar (un cliente puede tener varios créditos semanales)
    $semana_por_credito[$r['credito_id']][] = $r;
}

// Extraer solo 1 cuota por crédito para la vista semanal
// Excluir créditos que ya aparecen en "del día" para evitar duplicados
foreach ($semana_por_credito as $cid => $cuotas_cl) {
    if (isset($del_dia_dedup[$cid])) continue;

    $row = $cuotas_cl[0]; // La primera es la más antigua según el ORDER BY
    $row['cuotas_atrasadas'] = count($cuotas_cl);

    // Cruzar con venc_por_credito para tener el total real de atrasadas del historial
    if (isset($venc_por_credito[$cid]) && count($venc_por_credito[$cid]) > count($cuotas_cl)) {
        $row['cuotas_atrasadas'] = count($venc_por_credito[$cid]);
    }

    $semana_rows[] = $row;
}

// Agrupar por día
$por_dia = array_fill(1, 6, []);
foreach ($semana_rows as $r) {
    $por_dia[(int)$r['dia_cobro']][] = $r;
}
$nombres_dia = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado'];

// ── Cuotas quincenales y mensuales ────────────────────────────
$mensual_stmt = $pdo->prepare("
    SELECT cu.*, cr.id AS credito_id, cr.frecuencia, cr.interes_moratorio_pct, cr.cobrador_id,
           cr.cant_cuotas, cr.veces_refinanciado,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen,
           (SELECT pt2.id FROM ic_pagos_temporales pt2 WHERE pt2.cuota_id=cu.id AND pt2.estado='PENDIENTE' LIMIT 1) AS pt_id
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE cu.estado IN ('PENDIENTE', 'VENCIDA', 'CAP_PAGADA', 'PARCIAL')
      AND cr.estado IN ('EN_CURSO','MOROSO')
      AND cr.frecuencia IN ('quincenal', 'mensual')
      AND cr.cobrador_id = ?
    ORDER BY cu.fecha_vencimiento ASC, cl.apellidos ASC
");
$mensual_stmt->execute([$cobrador_filtro]);
$mensual_rows_raw = $mensual_stmt->fetchAll();

// Dedup por crédito: una tarjeta por crédito (la cuota más urgente).
// cuotas_atrasadas = total de cuotas pendientes/vencidas de ese crédito.
$mensual_dedup = []; // key = credito_id
foreach ($mensual_rows_raw as $r) {
    $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);
    $mora        = ($r['estado'] === 'CAP_PAGADA')
        ? (float) $r['monto_mora']
        : calcular_mora($r['monto_cuota'], $dias_atraso, $r['interes_moratorio_pct']);
    $r['dias_atraso_calc'] = $dias_atraso;
    $r['mora_calc']        = $mora;
    $saldo_prev_m = (float)($r['saldo_pagado'] ?? 0);
    $r['total_a_cobrar']   = ($r['estado'] === 'CAP_PAGADA') ? $mora : max(0, $r['monto_cuota'] + $mora - $saldo_prev_m);
    $r['pago_pen']         = (int)($r['pago_pen'] ?? 0);
    $rid = $r['credito_id'];
    if (!isset($mensual_dedup[$rid])) {
        $r['cuotas_atrasadas'] = 1;
        $mensual_dedup[$rid]   = $r;
    } else {
        $mensual_dedup[$rid]['cuotas_atrasadas']++;
    }
}
$mensual_rows = array_values($mensual_dedup);

// ── FEATURE 7: Dashboard cobrador mejorado ──────────────────

// Meta semanal (configurable por cobrador en admin/usuarios)
$_meta_stmt = $pdo->prepare("SELECT meta_semanal FROM ic_usuarios WHERE id = ?");
$_meta_stmt->execute([$cobrador_filtro]);
$META_SEMANAL = (float) ($_meta_stmt->fetchColumn() ?: 500000);
$dow_cobro    = (int) date('N');
$lunes_sem    = date('Y-m-d', strtotime('-' . ($dow_cobro - 1) . ' days'));
$sabado_sem   = date('Y-m-d', strtotime($lunes_sem . ' +5 days'));
$domingo_sem  = date('Y-m-d', strtotime($lunes_sem . ' +6 days')); // incluye entradas tardías del sábado

$stmt_meta = $pdo->prepare("
    SELECT COALESCE(SUM(monto_total), 0) AS cobrado_semana
    FROM ic_pagos_temporales
    WHERE cobrador_id = ? AND fecha_jornada BETWEEN ? AND ?
      AND estado IN ('PENDIENTE','APROBADO') AND origen = 'cobrador'
");
$stmt_meta->execute([$cobrador_filtro, $lunes_sem, $domingo_sem]);
$cobrado_semana = (float) $stmt_meta->fetchColumn();
$pct_meta       = $META_SEMANAL > 0 ? min(100, round($cobrado_semana / $META_SEMANAL * 100)) : 0;
$falta_meta     = max(0, $META_SEMANAL - $cobrado_semana);

// Racha: días consecutivos cumpliendo meta diaria
$META_DIARIA = round($META_SEMANAL / 6);
$stmt_racha = $pdo->prepare("
    SELECT fecha_jornada, SUM(monto_total) AS total_dia
    FROM ic_pagos_temporales
    WHERE cobrador_id = ? AND fecha_jornada >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND estado IN ('PENDIENTE','APROBADO') AND origen = 'cobrador'
    GROUP BY fecha_jornada
    ORDER BY fecha_jornada DESC
");
$stmt_racha->execute([$cobrador_filtro]);
$racha = 0;
foreach ($stmt_racha->fetchAll() as $rd) {
    if ((float) $rd['total_dia'] >= $META_DIARIA) {
        $racha++;
    } else {
        break;
    }
}

// Mini ranking semanal
$stmt_rank = $pdo->prepare("
    SELECT cobrador_id, SUM(monto_total) AS total_sem
    FROM ic_pagos_temporales
    WHERE fecha_jornada BETWEEN ? AND ? AND estado IN ('PENDIENTE','APROBADO')
      AND origen = 'cobrador'
    GROUP BY cobrador_id
    ORDER BY total_sem DESC
");
$stmt_rank->execute([$lunes_sem, $domingo_sem]);
$ranking_rows = $stmt_rank->fetchAll();
$mi_posicion  = 0;
$total_cobs   = count($ranking_rows);
foreach ($ranking_rows as $idx => $rr) {
    if ((int) $rr['cobrador_id'] === $cobrador_filtro) {
        $mi_posicion = $idx + 1;
        break;
    }
}
if ($mi_posicion === 0 && $total_cobs > 0) $mi_posicion = $total_cobs + 1;

// Tendencia 4 semanas
$stmt_tend = $pdo->prepare("
    SELECT YEARWEEK(fecha_jornada, 1) AS yw, SUM(monto_total) AS total
    FROM ic_pagos_temporales
    WHERE cobrador_id = ? AND fecha_jornada >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
      AND estado IN ('PENDIENTE','APROBADO') AND origen = 'cobrador'
    GROUP BY yw
    ORDER BY yw ASC
");
$stmt_tend->execute([$cobrador_filtro]);
$tendencia_rows = $stmt_tend->fetchAll();
$tend_vals = array_column($tendencia_rows, 'total');
$tend_pct  = 0;
if (count($tend_vals) >= 2) {
    $sem_ant = (float) $tend_vals[count($tend_vals) - 2];
    $sem_act = (float) $tend_vals[count($tend_vals) - 1];
    $tend_pct = $sem_ant > 0 ? round(($sem_act - $sem_ant) / $sem_ant * 100) : 0;
}

$page_title = 'Agenda del ' . $hoy_dt->format('d/m/Y');
$page_current = 'agenda';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- FILTROS -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar">
        <input type="text" id="buscador-agenda" name="q" value="<?= e($q_busca) ?>"
               placeholder="🔍 Buscar cliente..." autocomplete="off"
               oninput="filtrarAgenda(this.value)"
               onkeydown="if(event.key==='Enter'){event.preventDefault();}">
        <?php if (!$is_cobrador): ?>
            <select name="cobrador_id">
                <?php foreach ($cobradores as $cob): ?>
                    <option value="<?= $cob['id'] ?>" <?= $cobrador_filtro == $cob['id'] ? 'selected' : '' ?>>
                        <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
        <?php if (!$is_cobrador): ?>
            <button type="button" class="btn-ic btn-ghost" onclick="openModal('modal-agenda-pdf')">
                <i class="fa fa-file-pdf"></i> Ficha PDF
            </button>
            <button type="button" class="btn-ic btn-ghost" onclick="openModal('modal-clientes-zona-pdf')">
                <i class="fa fa-map-location-dot"></i> Clientes por Zona
            </button>
        <?php endif; ?>
    </form>
</div>

<?php if (!$is_cobrador): ?>
<!-- MODAL EXPORTAR FICHA PDF -->
<div class="modal-overlay" id="modal-agenda-pdf">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-file-pdf"></i> Exportar Ficha Semanal</div>
            <button class="modal-close" onclick="closeModal('modal-agenda-pdf')">✕</button>
        </div>
        <form id="form-agenda-pdf" target="_blank" action="agenda_pdf.php" method="GET">
            <div class="form-group mb-3">
                <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:6px">Cobrador</label>
                <select name="cobrador_id" style="width:100%">
                    <?php foreach ($cobradores as $cob): ?>
                        <option value="<?= $cob['id'] ?>" <?= $cobrador_filtro == $cob['id'] ? 'selected' : '' ?>>
                            <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-3">
                <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:8px">Días a incluir</label>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                    <?php
                    $dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado'];
                    foreach ($dias as $num => $nom):
                    ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.9rem">
                            <input type="checkbox" name="dias[]" value="<?= $num ?>" checked
                                style="width:16px;height:16px;cursor:pointer">
                            <?= $nom ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group mb-4">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:8px;border:1px solid rgba(255,255,255,.1)">
                    <input type="hidden" name="incluir_qm" value="0">
                    <input type="checkbox" name="incluir_qm" value="1" checked
                        style="width:16px;height:16px;cursor:pointer;accent-color:var(--primary)">
                    <span>Incluir <strong>Quincenales y Mensuales</strong></span>
                </label>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-primary w-100" style="justify-content:center">
                    <i class="fa fa-file-pdf"></i> Generar PDF
                </button>
                <button type="button" onclick="closeModal('modal-agenda-pdf')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_cobrador): ?>
<!-- MODAL CLIENTES POR ZONA PDF -->
<div class="modal-overlay" id="modal-clientes-zona-pdf">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-map-location-dot"></i> Clientes por Zona</div>
            <button class="modal-close" onclick="closeModal('modal-clientes-zona-pdf')">✕</button>
        </div>
        <form target="_blank" action="clientes_zona_pdf.php" method="GET">
            <div class="form-group mb-4">
                <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:6px">Cobrador</label>
                <select name="cobrador_id" style="width:100%">
                    <?php foreach ($cobradores as $cob): ?>
                        <option value="<?= $cob['id'] ?>" <?= $cobrador_filtro == $cob['id'] ? 'selected' : '' ?>>
                            <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-primary w-100" style="justify-content:center">
                    <i class="fa fa-file-pdf"></i> Generar PDF
                </button>
                <button type="button" onclick="closeModal('modal-clientes-zona-pdf')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- BARRA DE PROGRESO DEL DÍA -->
<?php
$prog_total    = count($del_dia);
$prog_cobrados = count(array_filter($del_dia, fn($c) => (int)$c['pago_pen'] > 0));
$prog_pct      = $prog_total > 0 ? round(($prog_cobrados / $prog_total) * 100) : 0;
?>
<div class="card-ic mb-4" style="padding:14px 16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <span style="font-size:.85rem;color:var(--text-muted)">
            <i class="fa fa-route"></i> Progreso del día
        </span>
        <span style="font-size:.9rem;font-weight:700">
            <span style="color:var(--success)"><?= $prog_cobrados ?></span>
            <span style="color:var(--text-muted)"> / <?= $prog_total ?> cobrados</span>
            <span style="margin-left:8px;color:var(--text-muted);font-size:.8rem">(<?= $prog_pct ?>%)</span>
        </span>
    </div>
    <div style="background:rgba(255,255,255,.08);border-radius:99px;height:8px;overflow:hidden">
        <div style="width:<?= $prog_pct ?>%;height:100%;background:var(--success);border-radius:99px;transition:width .4s ease"></div>
    </div>
</div>

<!-- META SEMANAL -->
<?php
$meta_color = $pct_meta >= 100 ? '#d4a017' : ($pct_meta >= 70 ? 'var(--success)' : ($pct_meta >= 40 ? '#f97316' : 'var(--danger)'));
?>
<div class="card-ic mb-4" style="padding:16px;border-left:4px solid <?= $meta_color ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span style="font-size:.9rem;font-weight:700">
            <i class="fa fa-bullseye" style="color:<?= $meta_color ?>"></i> Meta Semanal
        </span>
        <span style="font-size:.85rem;font-weight:800;color:<?= $meta_color ?>">
            <?= $pct_meta ?>%
        </span>
    </div>
    <div style="background:rgba(255,255,255,.08);border-radius:99px;height:10px;overflow:hidden;margin-bottom:10px">
        <div style="width:<?= $pct_meta ?>%;height:100%;background:<?= $meta_color ?>;border-radius:99px;transition:width .4s ease"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:.82rem">
        <span style="color:var(--text-muted)">
            <?= formato_pesos($cobrado_semana) ?> / <?= formato_pesos($META_SEMANAL) ?>
        </span>
        <?php if ($pct_meta >= 100): ?>
            <span style="color:#d4a017;font-weight:800"><i class="fa fa-trophy"></i> Meta alcanzada!</span>
        <?php else: ?>
            <span style="color:var(--text-muted)">Faltan: <strong><?= formato_pesos($falta_meta) ?></strong></span>
        <?php endif; ?>
    </div>
</div>

<!-- STRIP GAMIFICACIÓN -->
<div class="kpi-grid mb-4" style="grid-template-columns:repeat(3,1fr)">
    <div class="kpi-card" style="--kpi-color:#f97316">
        <div class="kpi-label" style="font-size:.78rem">Racha</div>
        <div class="kpi-value" style="font-size:1.4rem;color:#f97316">
            <?= $racha ?> <span style="font-size:.8rem">dias</span>
        </div>
        <div class="kpi-sub"><?= $racha > 0 ? 'consecutivos sobre meta' : 'sin racha activa' ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <div class="kpi-label" style="font-size:.78rem">Tu Posicion</div>
        <div class="kpi-value" style="font-size:1.4rem">
            <?= $mi_posicion > 0 ? $mi_posicion . '<span style="font-size:.8rem;color:var(--text-muted)"> de ' . max($total_cobs, 1) . '</span>' : '—' ?>
        </div>
        <div class="kpi-sub">ranking esta semana</div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $tend_pct >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
        <div class="kpi-label" style="font-size:.78rem">Tendencia</div>
        <div class="kpi-value" style="font-size:1.4rem;color:<?= $tend_pct >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
            <i class="fa fa-arrow-<?= $tend_pct >= 0 ? 'up' : 'down' ?>"></i>
            <?= abs($tend_pct) ?>%
        </div>
        <div class="kpi-sub">
            <?php if (count($tend_vals) >= 2): ?>
                <?php foreach (array_slice($tend_vals, -4) as $tv): ?>
                    <span style="font-size:.7rem;color:var(--text-muted)"><?= formato_pesos((float)$tv) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                datos insuficientes
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- SUMARIO DEL DÍA -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <i class="fa fa-hand-holding-dollar kpi-icon"></i>
        <div class="kpi-label">Ingresos Hoy</div>
        <div class="kpi-value" style="font-size:1.2rem;color:var(--success)">
            <?= formato_pesos($kpi_ingresos_hoy) ?>
        </div>
        <div class="kpi-sub" style="font-size:0.75rem;">
            Efc: <?= formato_pesos($kpi_ingresos_efectivo) ?> <br>
            Trf: <?= formato_pesos($kpi_ingresos_transferencia) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <i class="fa fa-dollar-sign kpi-icon"></i>
        <div class="kpi-label">A Cobrar Hoy</div>
        <div class="kpi-value" style="font-size:1.2rem">
            <?= formato_pesos($kpi_total_hoy) ?>
        </div>
        <div class="kpi-sub">capital + mora programada</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <i class="fa fa-calendar-check kpi-icon"></i>
        <div class="kpi-label">Cuotas del Día</div>
        <div class="kpi-value">
            <?= $kpi_del_dia ?>
        </div>
        <div class="kpi-sub">cuotas agendadas para hoy</div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <i class="fa fa-fire kpi-icon"></i>
        <div class="kpi-label">Vencidas</div>
        <div class="kpi-value">
            <?= $kpi_vencidas ?>
        </div>
        <div class="kpi-sub">
            cuotas sin cobrar acumuladas
            <?php if ($kpi_mora_venc > 0): ?>
            <br><span style="color:var(--danger)">Mora: <?= formato_pesos($kpi_mora_venc) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary-light)">
        <i class="fa fa-clock-rotate-left kpi-icon"></i>
        <div class="kpi-label">Por Rendir</div>
        <div class="kpi-value" style="font-size:1.2rem;color:var(--primary-light)">
            <?= formato_pesos($kpi_por_rendir_total) ?>
        </div>
        <div class="kpi-sub" style="font-size:0.75rem">
            Efc: <?= formato_pesos($kpi_por_rendir_efectivo) ?><br>
            Trf: <?= formato_pesos($kpi_por_rendir_transfer) ?>
            <?php if ($kpi_por_rendir_jornadas > 1): ?>
            <br><span style="color:var(--warning)">
                <i class="fa fa-calendar-week"></i> <?= $kpi_por_rendir_jornadas ?> jornadas
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Helper: fecha relativa con contexto de estado
function fecha_relativa(string $fecha_str): string {
    $hoy  = new DateTime('today');
    $venc = new DateTime($fecha_str);
    $diff = (int) $hoy->diff($venc)->days;
    $fut  = $venc >= $hoy;
    if ($diff === 0)  return '<span style="color:var(--warning);font-weight:700">vence hoy</span>';
    if (!$fut)        return '<span style="color:var(--danger)">hace ' . $diff . ' día' . ($diff > 1 ? 's' : '') . '</span>';
    if ($diff === 1)  return '<span style="color:var(--success)">mañana</span>';
    if ($diff < 7)    return '<span style="color:var(--success)">en ' . $diff . ' días</span>';
    $sem = (int) ceil($diff / 7);
    return '<span style="color:var(--text-muted)">en ' . $sem . ' semana' . ($sem > 1 ? 's' : '') . '</span>';
}

// Helper: label de fecha según contexto (pasada/hoy/futura)
function label_fecha(string $fecha_str): string {
    $hoy  = date('Y-m-d');
    if ($fecha_str < $hoy) return '<span style="color:var(--danger);font-size:.8rem">Venció:</span>';
    if ($fecha_str === $hoy) return '<span style="color:var(--warning);font-size:.8rem">Vence:</span>';
    return '<span style="color:var(--text-muted);font-size:.8rem">Próxima cuota:</span>';
}

// Función helper para renderizar lista de cuotas
function render_tabla_cuotas(array $cuotas, string $titulo, string $color): string
{
    if (empty($cuotas))
        return "<p class='text-muted text-center' style='padding:20px'>Sin cuotas en esta sección.</p>";
    ob_start();
    echo '<div class="list-group list-group-flush agenda-list-group">';
    foreach ($cuotas as $c):
        $mora_pos = $c['mora_calc'] > 0;
        $data     = htmlspecialchars(json_encode($c), ENT_QUOTES);
        $wa_msg   = 'Hola ' . $c['nombres'] . ', le recordamos su cuota #' . $c['numero_cuota'] . ' vencida el ' . date('d/m/Y', strtotime($c['fecha_vencimiento'])) . '. Total: ' . formato_pesos($c['total_a_cobrar']);
?>
    <?php
        $cardClass = '';
        if ($c['estado'] === 'CAP_PAGADA')             $cardClass = 'agenda-card--cap-pagada';
        elseif (!empty($c['adelantado']))               $cardClass = 'agenda-card--adelantado';
        elseif (!empty($c['cuotas_atrasadas']) && $c['cuotas_atrasadas'] > 0) $cardClass = 'agenda-card--con-vencidas';
        elseif ($mora_pos)                              $cardClass = 'agenda-card--vencida';
    ?>
    <div class="list-group-item agenda-card <?= $cardClass ?>" id="row-<?= $c['id'] ?>" data-nombre="<?= strtolower(e($c['apellidos'] . ' ' . $c['nombres'])) ?>">
        
        <div class="agenda-card-header">
            <div class="agenda-card-client">
                <button class="agenda-cliente-btn" onclick='verEstadoCuenta(<?= $c['credito_id'] ?>, <?= json_encode($c['apellidos'].' '.$c['nombres'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Ver estado de cuenta">
                    <span class="agenda-nombre"><?= e(strtoupper($c['apellidos'] . ' ' . $c['nombres'])) ?> <i class="fa fa-chart-line" style="font-size:.75rem;opacity:.5"></i></span>
                    <span class="agenda-zona">Zona: <?= e($c['zona'] ?: '—') ?></span>
                </button>
                <?php if (($c['riesgo'] ?? 1) >= 2): ?>
                    <?= badge_riesgo((int)$c['riesgo']) ?>
                <?php endif; ?>
                <?php if (!empty($c['cuotas_atrasadas']) && $c['cuotas_atrasadas'] > 0): ?>
                    <span class="agenda-badge-prev">
                        <?= $c['cuotas_atrasadas'] ?> ant.
                    </span>
                <?php endif; ?>
                <?php if (!empty($c['adelantado'])): ?>
                    <span class="badge-ic badge-success" style="margin-left:4px;font-size:.75rem">
                        <i class="fa fa-forward"></i> Adelantado
                    </span>
                <?php endif; ?>
                <?php if (!empty($c['veces_refinanciado']) && (int)$c['veces_refinanciado'] > 0): ?>
                    <span class="badge-ic badge-warning" style="margin-left:4px;font-size:.75rem">
                        <i class="fa fa-sync-alt"></i> Ref. ×<?= (int)$c['veces_refinanciado'] ?>
                    </span>
                <?php endif; ?>
                <div class="agenda-articulo" id="art-<?= $c['id'] ?>" style="display:none">
                    <i class="fa fa-box-open"></i> <?= e($c['articulo']) ?>
                </div>
            </div>
            
            <div class="agenda-card-amounts text-end" style="text-align: right;">
                <?php if ($c['estado'] === 'CAP_PAGADA'): ?>
                    <span class="agenda-monto" style="color:var(--warning)"><?= formato_pesos($c['mora_calc']) ?></span>
                    <span class="agenda-cuota-num">Cuota <?= $c['numero_cuota'] ?>/<?= $c['cant_cuotas'] ?></span>
                    <span class="agenda-mora" style="color:var(--warning)">Capital pagado — Mora pendiente</span>
                <?php elseif (!empty($c['cuotas_atrasadas']) && $c['cuotas_atrasadas'] > 0 && isset($c['total_acumulado'])): ?>
                    <span class="agenda-monto" style="color:var(--danger)"><?= formato_pesos($c['total_acumulado']) ?></span>
                    <span class="agenda-cuota-num" style="color:var(--danger)">Total acumulado</span>
                    <span style="font-size:.75rem;color:var(--text-muted);display:block">
                        Esta cuota: <?= formato_pesos($c['monto_cuota']) ?> · <?= $c['numero_cuota'] ?>/<?= $c['cant_cuotas'] ?>
                    </span>
                <?php else: ?>
                    <span class="agenda-monto"><?= formato_pesos($c['monto_cuota']) ?></span>
                    <span class="agenda-cuota-num">Cuota <?= $c['numero_cuota'] ?>/<?= $c['cant_cuotas'] ?></span>
                    <?php if ($mora_pos): ?>
                        <span class="agenda-mora">+Mora: <?= formato_pesos($c['mora_calc']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="agenda-card-body">
            <div class="agenda-card-cobro">
                <?php if ($c['pago_pen'] == 0): ?>
                    <?php if ($mora_pos && $c['estado'] !== 'CAP_PAGADA'): ?>
                    <label class="agenda-cuota-pura-toggle" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer;font-size:.82rem;color:var(--text-muted)">
                        <input type="checkbox" id="pura-<?= $c['id'] ?>"
                            style="width:16px;height:16px;cursor:pointer;accent-color:var(--warning)"
                            onchange="toggleCuotaPura(this, <?= number_format($c['monto_cuota'], 2, '.', '') ?>, <?= number_format($c['mora_calc'], 2, '.', '') ?>)">
                        Cuota pura — solo capital (<?= formato_pesos($c['monto_cuota']) ?>)
                    </label>
                    <?php endif; ?>
                    <div class="agenda-cobro-wrap" style="display: flex; gap: 8px; width: 100%;">
                        <input type="number" class="agenda-cobro-input" style="flex: 1;" id="inp-<?= $c['id'] ?>"
                            value="<?= number_format($c['total_a_cobrar'], 2, '.', '') ?>"
                            step="0.01" min="0" placeholder="0.00">
                        <button class="agenda-cobro-btn" onclick="abrirPagoDesdeRow(<?= $data ?>, this)"
                            title="Registrar pago">
                            <i class="fa fa-check"></i> <span style="margin-left:4px;">Cobrar</span>
                        </button>
                    </div>
                <?php else: ?>
                    <div style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:rgba(255,193,7,.1);border-radius:8px;border:1px solid rgba(255,193,7,.2)">
                        <span style="color:var(--warning);font-weight:700;font-size:.9rem">
                            <i class="fa fa-clock"></i> Pago Registrado
                        </span>
                        <?php if (!empty($c['pt_id'])): ?>
                        <button type="button"
                            onclick='anularPago(<?= (int)$c['pt_id'] ?>, <?= json_encode($c['apellidos'].' '.$c['nombres'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>, this)'
                            class="btn-ic btn-ghost" title="Anular pago y corregir"
                            style="font-size:.78rem;padding:5px 10px;height:auto;color:var(--danger);border-color:rgba(220,53,69,.3);gap:5px">
                            <i class="fa fa-xmark"></i> Anular
                        </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="agenda-card-footer" style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 16px;">
            <div class="agenda-vencimiento" style="line-height: 1.4;">
                <?= label_fecha($c['fecha_vencimiento']) ?>
                <strong style="color: var(--text);margin-left:4px"><?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></strong><br>
                <?= fecha_relativa($c['fecha_vencimiento']) ?>
            </div>
            <div class="agenda-acciones" style="display: flex; gap: 8px;">
                <?php if ($c['coordenadas']): ?>
                    <a href="<?= maps_url($c['coordenadas']) ?>" target="_blank"
                       class="btn-ic btn-ghost btn-icon" title="Google Maps" style="width: 44px; height: 44px; border-radius: 8px; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                        <i class="fa fa-map-marker-alt"></i>
                    </a>
                <?php endif; ?>
                <a href="<?= whatsapp_url($c['telefono'], $wa_msg) ?>" target="_blank"
                   class="btn-ic btn-ghost btn-icon" title="WhatsApp" style="width: 44px; height: 44px; border-radius: 8px; font-size: 1.2rem; color: #25D366; background: rgba(37,211,102,.1); display: flex; align-items: center; justify-content: center;">
                    <i class="fa-brands fa-whatsapp"></i>
                </a>
                <button type="button" onclick="toggleArticulo(<?= $c['id'] ?>)"
                        class="btn-ic btn-ghost btn-icon" title="Ver artículo" style="width:44px;height:44px;border-radius:8px;font-size:1rem;display:flex;align-items:center;justify-content:center;">
                    <i class="fa fa-box-open"></i>
                </button>
                <?php if (!$c['pago_pen']): ?>
                <button type="button"
                        onclick="abrirIntento(<?= (int)$c['id'] ?>, '<?= e(addslashes($c['apellidos'].' '.$c['nombres'])) ?>')"
                        class="btn-ic btn-ghost btn-icon" title="No cobré"
                        style="width:44px;height:44px;border-radius:8px;font-size:1rem;color:var(--warning);display:flex;align-items:center;justify-content:center;">
                    <i class="fa fa-user-slash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>

    </div>
<?php endforeach;
    echo '</div>';
    return ob_get_clean();
}
?>

<!-- CUOTAS DEL DÍA -->
<div class="card-ic mb-4" id="sec-hoy">
    <div class="card-ic-header" style="cursor: pointer;" onclick="toggleCollapse('col-hoy', 'icon-hoy')">
        <span class="card-title">
            <i class="fa fa-chevron-down" id="icon-hoy" style="transition: transform 0.2s; margin-right: 6px;"></i>
            <i class="fa fa-calendar-check" style="color:var(--success)"></i> Cuotas de Hoy
            <span class="badge-ic badge-success" style="margin-left:8px">
                <?= count($del_dia) ?>
            </span>
        </span>
    </div>

    <div id="col-hoy" style="display: block;">
        <?= render_tabla_cuotas($del_dia, 'Hoy', 'success') ?>
    </div>
</div>

<!-- CUOTAS VENCIDAS -->
<?php if (!empty($vencidas)): ?>
    <div class="card-ic mb-4" id="sec-vencidas">
        <div class="card-ic-header" style="cursor: pointer;" onclick="toggleCollapse('col-vencidas', 'icon-vencidas')">
            <span class="card-title">
                <i class="fa fa-chevron-down" id="icon-vencidas" style="transition: transform 0.2s; margin-right: 6px;"></i>
                <i class="fa fa-fire" style="color:var(--danger)"></i> Cuotas Vencidas
                <span class="badge-ic badge-danger" style="margin-left:8px">
                    <?= count($vencidas) ?>
                </span>
            </span>
        </div>

        <div id="col-vencidas" style="display: block;">
            <?= render_tabla_cuotas($vencidas, 'Vencidas', 'danger') ?>
        </div>
    </div>
<?php endif; ?>

<!-- COBRADOS HOY -->
<?php if (!empty($cobrados_hoy)): ?>
    <div class="card-ic mb-4" id="sec-cobrados">
        <div class="card-ic-header" style="cursor: pointer;" onclick="toggleCollapse('col-cobrados', 'icon-cobrados')">
            <span class="card-title">
                <i class="fa fa-chevron-right" id="icon-cobrados" style="transition: transform 0.2s; margin-right: 6px;"></i>
                <i class="fa fa-hand-holding-dollar" style="color:var(--primary)"></i> Cobrados Hoy
                <span class="badge-ic badge-primary" style="margin-left:8px">
                    <?= count($cobrados_hoy) ?>
                </span>
            </span>
        </div>

        <div id="col-cobrados" style="display: none;">
            <?= render_tabla_cuotas($cobrados_hoy, 'Cobrados Hoy', 'primary') ?>
        </div>
    </div>
<?php endif; ?>

<!-- ── VISTA SEMANAL ──────────────────────────────────────────── -->
<div class="card-ic mb-4" id="sec-semanal">
    <div class="card-ic-header" style="cursor:pointer" onclick="toggleCollapse('col-semanal','icon-semanal')">
        <span class="card-title">
            <i class="fa fa-chevron-down" id="icon-semanal" style="transition:transform 0.2s;margin-right:6px"></i>
            <i class="fa fa-calendar-week"></i> Vista Semanal
            <span class="badge-ic badge-primary" style="margin-left:8px"><?= count($semana_rows) ?></span>
        </span>
        <span class="text-muted" style="font-size:.82rem">
            <?= count($semana_rows) ?> cuota(s) pendientes esta semana
        </span>
    </div>

    <div id="col-semanal" style="display:block">
    <!-- Tabs de días -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 16px 0;border-bottom:1px solid rgba(255,255,255,.08)">
        <?php foreach ($nombres_dia as $n => $nombre): ?>
            <?php $cnt = count($por_dia[$n]); ?>
            <button
                onclick="switchDia(<?= $n ?>)"
                id="tab-dia-<?= $n ?>"
                class="btn-ic btn-sm <?= $n === $dia_semana ? 'btn-primary' : 'btn-ghost' ?>"
                style="position:relative">
                <?= $nombre ?>
                <?php if ($cnt > 0): ?>
                    <span style="
                        position:absolute;top:-6px;right:-6px;
                        background:var(--primary);color:#fff;
                        border-radius:50%;width:18px;height:18px;
                        font-size:.65rem;display:flex;align-items:center;justify-content:center;
                        font-weight:700">
                        <?= $cnt ?>
                    </span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Paneles de cada día -->
    <?php foreach ($nombres_dia as $n => $nombre): ?>
        <div id="panel-dia-<?= $n ?>" style="display:<?= $n === $dia_semana ? 'block' : 'none' ?>">
            <?php if (empty($por_dia[$n])): ?>
                <p class="text-muted text-center" style="padding:24px">
                    Sin cuotas asignadas para <?= $nombre ?>.
                </p>
            <?php else: ?>

                <?= render_tabla_cuotas($por_dia[$n], '', '') ?>
                
                <!-- Totales pie de panel -->
                <div style="padding:16px; text-align:right; border-top:1px solid rgba(255,255,255,.1)">
                    <span class="text-muted" style="margin-right:16px; font-size:.85rem">Total <?= $nombre ?>:</span>
                    <span style="font-size:1.1rem; font-weight:700; color:var(--success)">
                        <?= formato_pesos(array_sum(array_column($por_dia[$n], 'total_a_cobrar'))) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div><!-- /col-semanal -->
</div>

<!-- ── CRÉDITOS QUINCENALES Y MENSUALES ──────────────────────── -->
<?php if (!empty($mensual_rows)): ?>
<div class="card-ic mb-4" id="sec-mensual">
    <div class="card-ic-header" style="cursor: pointer;" onclick="toggleCollapse('col-mensual', 'icon-mensual')">
        <span class="card-title">
            <i class="fa fa-chevron-down" id="icon-mensual" style="transition: transform 0.2s; margin-right: 6px;"></i>
            <i class="fa fa-calendar-alt" style="color:var(--primary)"></i> Quincenales y Mensuales
            <span class="badge-ic badge-primary" style="margin-left:8px">
                <?= count($mensual_rows) ?>
            </span>
        </span>
        <span class="text-muted" style="font-size:.82rem">
            <?= count(array_filter($mensual_rows, fn($r) => $r['dias_atraso_calc'] > 0)) ?> vencida(s)
        </span>
    </div>
    <div id="col-mensual" style="display:block">
        <!-- Tabs quincenal / mensual -->
        <?php
        $tab_quincenal = array_values(array_filter($mensual_rows, fn($r) => $r['frecuencia'] === 'quincenal'));
        $tab_mensual   = array_values(array_filter($mensual_rows, fn($r) => $r['frecuencia'] === 'mensual'));
        ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;padding:12px 16px 0;border-bottom:1px solid rgba(255,255,255,.08)">
            <button onclick="switchTabMensual('quincenal')" id="tab-quincenal"
                class="btn-ic btn-sm <?= !empty($tab_quincenal) ? 'btn-primary' : 'btn-ghost' ?>"
                style="position:relative">
                Quincenal
                <?php if (!empty($tab_quincenal)): ?>
                    <span style="position:absolute;top:-6px;right:-6px;background:var(--primary);color:#fff;border-radius:50%;width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700">
                        <?= count($tab_quincenal) ?>
                    </span>
                <?php endif; ?>
            </button>
            <button onclick="switchTabMensual('mensual')" id="tab-mensual"
                class="btn-ic btn-sm <?= empty($tab_quincenal) ? 'btn-primary' : 'btn-ghost' ?>"
                style="position:relative">
                Mensual
                <?php if (!empty($tab_mensual)): ?>
                    <span style="position:absolute;top:-6px;right:-6px;background:var(--primary);color:#fff;border-radius:50%;width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700">
                        <?= count($tab_mensual) ?>
                    </span>
                <?php endif; ?>
            </button>
        </div>

        <div id="panel-quincenal" style="display:<?= !empty($tab_quincenal) ? 'block' : 'none' ?>">
            <?php if (empty($tab_quincenal)): ?>
                <p class="text-muted text-center" style="padding:24px">Sin cuotas quincenales pendientes.</p>
            <?php else: ?>
                <?= render_tabla_cuotas($tab_quincenal, '', '') ?>
                <div style="padding:16px;text-align:right;border-top:1px solid rgba(255,255,255,.1)">
                    <span class="text-muted" style="margin-right:16px;font-size:.85rem">Total quincenal:</span>
                    <span style="font-size:1.1rem;font-weight:700;color:var(--success)">
                        <?= formato_pesos(array_sum(array_column($tab_quincenal, 'total_a_cobrar'))) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div id="panel-mensual" style="display:<?= empty($tab_quincenal) ? 'block' : 'none' ?>">
            <?php if (empty($tab_mensual)): ?>
                <p class="text-muted text-center" style="padding:24px">Sin cuotas mensuales pendientes.</p>
            <?php else: ?>
                <?= render_tabla_cuotas($tab_mensual, '', '') ?>
                <div style="padding:16px;text-align:right;border-top:1px solid rgba(255,255,255,.1)">
                    <span class="text-muted" style="margin-right:16px;font-size:.85rem">Total mensual:</span>
                    <span style="font-size:1.1rem;font-weight:700;color:var(--success)">
                        <?= formato_pesos(array_sum(array_column($tab_mensual, 'total_a_cobrar'))) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MODAL CONFIRMAR ANULACIÓN -->
<div class="modal-overlay" id="modal-anular">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--danger)"><i class="fa fa-triangle-exclamation"></i> Anular Pago</div>
            <button class="modal-close" onclick="closeModal('modal-anular')">&#10005;</button>
        </div>
        <div style="padding:4px 0 18px;font-size:.9rem;color:var(--text);line-height:1.5">
            Se anulará el pago de <strong id="anular-nombre"></strong>.<br>
            <span style="color:var(--text-muted);font-size:.82rem">El cobro volverá a la agenda para registrarlo nuevamente.</span>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn-ic btn-ghost" onclick="closeModal('modal-anular')" style="padding:8px 18px">Cancelar</button>
            <button class="btn-ic" id="anular-confirmar-btn" style="padding:8px 18px;background:var(--danger);color:#fff;border-color:var(--danger);gap:6px">
                <i class="fa fa-xmark"></i> Sí, anular
            </button>
        </div>
    </div>
</div>

<!-- MODAL ESTADO DE CUENTA -->
<div class="modal-overlay" id="modal-estado-cuenta">
    <div class="modal-box" style="max-width:560px;width:95vw">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-list-check"></i> <span id="esc-titulo">Estado de Cuenta</span></div>
            <button class="modal-close" onclick="closeModal('modal-estado-cuenta')">✕</button>
        </div>
        <div id="esc-subtitulo" style="font-size:.8rem;color:var(--text-muted);margin-bottom:14px;padding:8px 12px;background:rgba(255,255,255,.04);border-radius:6px"></div>

        <!-- COBRAR CUOTAS — sección interactiva -->
        <div id="esc-seleccion" style="display:none;margin-bottom:16px;border-radius:10px;border:1px solid rgba(34,197,94,.2);overflow:hidden">
            <div style="display:flex;align-items:center;gap:8px;padding:9px 14px;background:rgba(34,197,94,.08);border-bottom:1px solid rgba(34,197,94,.15)">
                <i class="fa fa-money-bill-wave" style="color:var(--success);font-size:.85rem"></i>
                <span style="font-size:.78rem;font-weight:700;color:var(--success);text-transform:uppercase;letter-spacing:.06em">Cobrar cuotas</span>
            </div>
            <div style="padding:12px">
                <div id="esc-cuotas-list" style="max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:5px"></div>
                <div id="esc-cuota-pura-wrap" style="display:none;margin-top:10px;padding:8px 12px;background:rgba(245,158,11,.1);border-radius:8px;border:1px solid rgba(245,158,11,.25)">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.85rem;user-select:none">
                        <input type="checkbox" id="esc-pura-cb" onchange="escRecalcular()"
                            style="width:16px;height:16px;cursor:pointer;accent-color:var(--warning);flex-shrink:0">
                        <span>Cuota pura — cobrar solo capital (sin mora)</span>
                    </label>
                </div>
                <div id="esc-total-wrap" style="display:none;margin-top:12px;flex-direction:column;gap:8px">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(255,255,255,.05);border-radius:8px">
                        <span style="font-size:.78rem;color:var(--text-muted)">Total a pagar:</span>
                        <span id="esc-total-val" style="font-size:1.3rem;font-weight:800;color:var(--success)">$ 0</span>
                    </div>
                    <button class="btn-ic btn-success" onclick="escCobrarSeleccionadas()"
                        style="width:100%;justify-content:center;gap:6px;padding:10px">
                        <i class="fa fa-check"></i> Cobrar seleccionadas
                    </button>
                </div>
            </div>
        </div>

        <div id="esc-body" style="max-height:50vh;overflow-y:auto"></div>
        <div id="esc-resumen" style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);display:flex;gap:16px;flex-wrap:wrap;font-size:.82rem"></div>
    </div>
</div>

<!-- MODAL REGISTRAR PAGO -->
<div class="modal-overlay" id="modal-pago">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-dollar-sign"></i> Registrar Pago</div>
            <button class="modal-close" onclick="closeModal('modal-pago')">✕</button>
        </div>
        <div id="modal-info"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>

        <!-- Toggle cuota pura: solo visible cuando la cuota tiene mora y no es CAP_PAGADA -->
        <div id="modal-cuota-pura-wrap" style="display:none;margin-bottom:14px;padding:10px 14px;background:rgba(245,158,11,.1);border-radius:8px;border:1px solid rgba(245,158,11,.3)">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none">
                <input type="checkbox" id="modal_pura_cb"
                    style="width:18px;height:18px;cursor:pointer;accent-color:var(--warning);flex-shrink:0"
                    onchange="onModalCuotaPuraChange()">
                <span style="font-size:.88rem;color:var(--text)">Cuota pura — cobrar solo capital</span>
                <span id="modal_pura_capital" style="margin-left:auto;font-weight:700;color:var(--warning);font-size:.9rem"></span>
            </label>
            <div id="modal_pura_info" style="display:none;margin-top:6px;font-size:.78rem;color:var(--text-muted);padding-left:28px">
                La mora queda congelada y pendiente de cobro en una próxima visita.
            </div>
        </div>

        <form method="POST" action="registrar_pago" class="form-ic" id="form-pago">
            <input type="hidden" name="cuota_id" id="input_cuota_id">

            <?php if ($es_modo_finde): ?>
            <!-- Selector de jornada: visible solo el lunes antes de las 10 AM -->
            <div style="margin-bottom:14px;padding:10px 14px;background:rgba(79,70,229,.12);border-radius:8px;border:1px solid rgba(79,70,229,.3)">
                <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:8px">
                    <i class="fa fa-calendar-week"></i> ¿A qué jornada pertenece este pago?
                </div>
                <div style="display:flex;gap:20px">
                    <?php foreach ($jornadas_disp as $i => $jornada): ?>
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.9rem;font-weight:600">
                        <input type="radio" name="fecha_jornada_sel" value="<?= $jornada ?>"
                            <?= $i === 0 ? 'checked' : '' ?>
                            style="accent-color:var(--primary);width:16px;height:16px">
                        <?= nombre_dia((int) date('N', strtotime($jornada))) ?>
                        <span style="font-weight:400;color:var(--text-muted);font-size:.8rem">
                            <?= date('d/m', strtotime($jornada)) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <input type="hidden" name="fecha_jornada_sel" value="<?= $jornadas_disp[0] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Monto Efectivo $</label>
                    <input type="number" name="monto_efectivo" id="inp_efectivo" step="0.01" min="0" value="0"
                        oninput="actualizarTotal()">
                </div>
                <div class="form-group">
                    <label>Monto Transferencia $</label>
                    <input type="number" name="monto_transferencia" id="inp_transfer" step="0.01" min="0" value="0"
                        oninput="actualizarTotal()">
                </div>
            </div>
            <div
                style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <span class="text-muted">Total Registrado:</span>
                <span id="total_display" style="font-size:1.2rem;font-weight:800;color:var(--success)">$ 0,00</span>
            </div>
            <input type="hidden" name="monto_mora_cobrada" id="inp_mora_cobrada" value="0">
            <input type="hidden" name="es_cuota_pura" id="inp_cuota_pura" value="0">
            <div class="form-group" style="margin-bottom:12px">
                <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:4px">
                    <i class="fa fa-comment-alt"></i> Observaciones (opcional)
                </label>
                <textarea name="observaciones" id="inp_observaciones" rows="2"
                    style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:8px 10px;color:var(--text);font-size:.88rem;resize:none"
                    placeholder="Ej: pagó con billete, prometió el resto mañana..."></textarea>
            </div>
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-success w-100" style="justify-content:center">
                    <i class="fa fa-save"></i> Confirmar Pago
                </button>
                <button type="button" onclick="closeModal('modal-pago')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- RESUMEN DE CIERRE DEL DÍA -->
<?php $pendientes_hoy = $prog_total - $prog_cobrados; ?>
<div class="card-ic mt-4" id="sec-resumen-cierre">
    <div class="card-ic-header" style="cursor:pointer" onclick="toggleCollapse('col-resumen-cierre','icon-resumen-cierre')">
        <span class="card-title"><i class="fa fa-flag-checkered"></i> Resumen de Cierre del Día</span>
        <i class="fa fa-chevron-down" id="icon-resumen-cierre"></i>
    </div>
    <div id="col-resumen-cierre">
        <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;padding:12px 0">
            <div class="kpi-card" style="--kpi-color:var(--success)">
                <i class="fa fa-check-circle kpi-icon"></i>
                <div class="kpi-label">Cobrados</div>
                <div class="kpi-value" style="color:var(--success)"><?= $prog_cobrados ?> / <?= $prog_total ?></div>
                <div class="kpi-sub"><?= $prog_pct ?>% del día</div>
            </div>
            <div class="kpi-card" style="--kpi-color:var(--success)">
                <i class="fa fa-hand-holding-dollar kpi-icon"></i>
                <div class="kpi-label">Cobrado Hoy</div>
                <div class="kpi-value" style="font-size:1.1rem;color:var(--success)"><?= formato_pesos($kpi_ingresos_hoy) ?></div>
                <div class="kpi-sub" style="font-size:.72rem">
                    Efc: <?= formato_pesos($kpi_ingresos_efectivo) ?><br>
                    Trf: <?= formato_pesos($kpi_ingresos_transferencia) ?>
                </div>
            </div>
            <?php if ($kpi_mora_cobrada_hoy > 0): ?>
            <div class="kpi-card" style="--kpi-color:var(--warning)">
                <i class="fa fa-exclamation-triangle kpi-icon"></i>
                <div class="kpi-label">Mora Cobrada</div>
                <div class="kpi-value" style="font-size:1.1rem;color:var(--warning)"><?= formato_pesos($kpi_mora_cobrada_hoy) ?></div>
                <div class="kpi-sub">incluida en cobrado hoy</div>
            </div>
            <?php endif; ?>
            <?php if ($pendientes_hoy > 0): ?>
            <div class="kpi-card" style="--kpi-color:var(--danger)">
                <i class="fa fa-clock kpi-icon"></i>
                <div class="kpi-label">Sin Cobrar</div>
                <div class="kpi-value" style="color:var(--danger)"><?= $pendientes_hoy ?></div>
                <div class="kpi-sub">clientes pendientes</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$page_scripts = <<<'JS'
<style>
/* ── Agenda Cards Responsivas ─────────────────────── */
.agenda-list-group { margin: 0 -16px; border-radius: 0; }
.list-group-item.agenda-card {
    background: transparent;
    border: none;
    border-bottom: 1px solid rgba(255,255,255,.08);
    padding: 16px;
    transition: background .2s;
}
.list-group-item.agenda-card:last-child { border-bottom: none; }
.list-group-item.agenda-card--vencida     { border-left: 4px solid var(--danger);  background: rgba(220,53,69,.04) !important; }
.list-group-item.agenda-card--con-vencidas{ border-left: 4px solid var(--danger);  background: rgba(220,53,69,.02) !important; }
.list-group-item.agenda-card--cap-pagada  { border-left: 4px solid var(--warning); background: rgba(245,158,11,.04) !important; }
.list-group-item.agenda-card--adelantado  { border-left: 4px solid #0ea5e9;        background: rgba(14,165,233,.04) !important; }
.list-group-item.agenda-card:hover { background: rgba(255,255,255,.04); }

.agenda-card-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 12px;
}
.agenda-cliente-btn {
    background: transparent; border: none; padding: 0; text-align: left;
    color: inherit; display: flex; flex-direction: column; cursor: pointer;
}
.agenda-nombre {
    font-size: 1.1rem; font-weight: 800; color: var(--primary);
    line-height: 1.2; margin-bottom: 4px; text-decoration: underline dotted;
    text-underline-offset: 2px;
}
.agenda-zona { font-size: 0.8rem; color: var(--text-muted); }
.agenda-badge-prev {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px;
    background: rgba(245,158,11,.12); color: #d97706; font-size: .72rem;
    border-radius: 4px; font-weight: 600; margin-top: 6px;
    border: 1px solid rgba(245,158,11,.2);
}
.agenda-articulo {
    margin-top: 8px; font-size: .85rem; color: var(--warning); display: flex; align-items: center; gap: 6px;
    background: rgba(255,193,7,.1); padding: 6px 10px; border-radius: 6px; width: fit-content;
}

.agenda-card-amounts { text-align: right; }
.agenda-monto { font-size: 1.15rem; font-weight: 800; display: block; }
.agenda-cuota-num { font-size: 0.8rem; color: var(--text-muted); display: block; }
.agenda-mora { font-size: 0.85rem; color: var(--danger); font-weight: 700; display: block; margin-top: 2px; }

/* Thumb zones (Botones de Cobro) */
.agenda-cobro-wrap { display: flex; align-items: stretch; gap: 8px; }
.agenda-cobro-input {
    min-height: 48px; border-radius: 8px;
    font-size: 1.15rem; font-weight: 700; text-align: right;
    background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.15);
    color: inherit;
}
.agenda-cobro-input:focus { outline: none; border-color: var(--success); }
.agenda-cobro-btn {
    min-height: 48px; padding: 0 16px; font-weight: 700; display: flex;
    align-items: center; justify-content: center; border-radius: 8px;
    background: var(--success); color: #fff; border: none; cursor: pointer;
    transition: opacity .15s;
}
.agenda-cobro-btn:hover { opacity: .85; }
</style>

<script>
let cuota_mora    = 0;
let cuota_capital = 0;

// ── Estado de Cuenta — vars de módulo ────────────────────────
let escCuotasPagables = [];
let escClienteNombre  = '';
let escCreditoData    = null;

// ── Búsqueda en tiempo real ──────────────────────────────────
function filtrarAgenda(q) {
    const term = q.trim().toLowerCase();
    const cards = document.querySelectorAll('.agenda-card');

    cards.forEach(card => {
        const nombre = card.dataset.nombre || '';
        card.style.display = (term === '' || nombre.includes(term)) ? '' : 'none';
    });

    // Mostrar/ocultar secciones según resultado de búsqueda
    const secciones = ['sec-hoy', 'sec-vencidas', 'sec-cobrados', 'sec-semanal', 'sec-mensual'];
    if (term === '') {
        // Sin búsqueda → restaurar todas las secciones tal como las renderizó PHP
        secciones.forEach(secId => {
            const sec = document.getElementById(secId);
            if (sec) sec.style.display = '';
        });
    } else {
        // Con búsqueda → ocultar secciones que no tengan ninguna tarjeta visible
        secciones.forEach(secId => {
            const sec = document.getElementById(secId);
            if (!sec) return;
            const visible = sec.querySelectorAll('.agenda-card:not([style*="display: none"])').length;
            sec.style.display = visible === 0 ? 'none' : '';
        });
    }
}

// Checkbox en tarjeta → ajusta el input inline de la tarjeta
function toggleCuotaPura(cb, capital, mora) {
  const wrap = cb.closest('.agenda-card-cobro');
  const inp  = wrap ? wrap.querySelector('.agenda-cobro-input') : null;
  if (!inp) return;
  inp.value = cb.checked ? capital.toFixed(2) : (capital + mora).toFixed(2);
}

// Checkbox DENTRO del modal → actualiza importes y flag
function onModalCuotaPuraChange() {
  const cb     = document.getElementById('modal_pura_cb');
  const esPura = cb && cb.checked ? 1 : 0;
  document.getElementById('inp_cuota_pura').value   = esPura;
  document.getElementById('inp_mora_cobrada').value = esPura ? '0' : cuota_mora.toFixed(2);
  document.getElementById('inp_efectivo').value     = esPura
    ? cuota_capital.toFixed(2)
    : (cuota_capital + cuota_mora).toFixed(2);
  document.getElementById('inp_transfer').value = '0';
  // Mostrar/ocultar la aclaración
  const info = document.getElementById('modal_pura_info');
  if (info) info.style.display = esPura ? 'block' : 'none';
  actualizarTotal();
}

function toggleCollapse(colId, iconId) {
    const col = document.getElementById(colId);
    const icon = document.getElementById(iconId);
    if (col.style.display === 'none') {
        col.style.display = 'block';
        if (icon) {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-down');
        }
    } else {
        col.style.display = 'none';
        if (icon) {
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-right');
        }
    }
}

// Abre modal de pago (desde lista diaria — sin input inline)
function abrirPago(c) {
  _rellenarModal(c, c.total_a_cobrar);
}

// Abre modal desde la vista semanal o inline — lee el input del row
function abrirPagoDesdeRow(c, btn) {
  const wrap   = btn ? btn.closest('.agenda-card-cobro') : null;
  const inp    = wrap ? wrap.querySelector('.agenda-cobro-input') : null;
  const cb     = wrap ? wrap.querySelector('input[type="checkbox"]') : null;
  const monto  = inp ? parseFloat(inp.value) || c.total_a_cobrar : c.total_a_cobrar;
  const esPura = cb ? cb.checked : false;
  _rellenarModal(c, monto, esPura);
}

function _rellenarModal(c, montoInicial, cardCbChecked = false) {
  cuota_mora    = parseFloat(c.mora_calc)   || 0;
  cuota_capital = parseFloat(c.monto_cuota) || 0;

  const esCapPagada   = c.estado === 'CAP_PAGADA';
  const tieneMora     = cuota_mora > 0 && !esCapPagada;
  const esPura        = cardCbChecked ? 1 : 0;

  // Toggle cuota pura en modal
  const wrap    = document.getElementById('modal-cuota-pura-wrap');
  const modalCb = document.getElementById('modal_pura_cb');
  const capSpan = document.getElementById('modal_pura_capital');
  const puraInfo = document.getElementById('modal_pura_info');
  if (wrap) wrap.style.display = tieneMora ? 'block' : 'none';
  if (modalCb) { modalCb.checked = esPura === 1; }
  if (capSpan) capSpan.textContent = formatPesos(cuota_capital);
  if (puraInfo) puraInfo.style.display = esPura ? 'block' : 'none';

  document.getElementById('input_cuota_id').value   = c.id;
  document.getElementById('inp_cuota_pura').value   = esPura;
  document.getElementById('inp_mora_cobrada').value = esPura ? '0' : cuota_mora.toFixed(2);

  const clienteLabel = c.nombres ? c.apellidos + ', ' + c.nombres : c.apellidos;
  let infoHtml = '<strong>' + clienteLabel + '</strong><br>' +
    'Cuota ' + (String(c.numero_cuota).startsWith('#') ? '' : '#') + c.numero_cuota + (c.cant_cuotas ? '/' + c.cant_cuotas : '');
  if (esCapPagada) {
    infoHtml += ' — <span style="color:var(--warning)">Capital pagado</span>' +
      '<br>Mora pendiente (congelada): <strong>' + formatPesos(cuota_mora) + '</strong>';
  } else {
    infoHtml += ' — Capital: ' + formatPesos(cuota_capital) +
      (cuota_mora > 0 ? ' + Mora: ' + formatPesos(cuota_mora) : '');
  }
  infoHtml += (c.articulo ? '<br><small style="color:var(--warning)">📦 ' + c.articulo + '</small>' : '') +
    '<br><strong>Total sugerido: ' + formatPesos(montoInicial) + '</strong>';

  document.getElementById('modal-info').innerHTML = infoHtml;
  document.getElementById('inp_efectivo').value   = montoInicial.toFixed(2);
  document.getElementById('inp_transfer').value   = '0';
  actualizarTotal();
  openModal('modal-pago');
}

function actualizarTotal() {
  const ef = parseFloat(document.getElementById('inp_efectivo').value) || 0;
  const tr = parseFloat(document.getElementById('inp_transfer').value) || 0;
  document.getElementById('total_display').textContent = formatPesos(ef + tr);
}

function toggleArticulo(id) {
  const el = document.getElementById('art-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'flex' : 'none';
}

function switchTabMensual(tab) {
  ['quincenal','mensual'].forEach(t => {
    const panel = document.getElementById('panel-' + t);
    const btn   = document.getElementById('tab-' + t);
    if (panel) panel.style.display = 'none';
    if (btn)   { btn.classList.remove('btn-primary'); btn.classList.add('btn-ghost'); }
  });
  const sel = document.getElementById('panel-' + tab);
  const selBtn = document.getElementById('tab-' + tab);
  if (sel) sel.style.display = 'block';
  if (selBtn) { selBtn.classList.remove('btn-ghost'); selBtn.classList.add('btn-primary'); }
}

function switchDia(n) {
  for (let i = 1; i <= 6; i++) {
    const panel = document.getElementById('panel-dia-' + i);
    const tab   = document.getElementById('tab-dia-' + i);
    if (panel) panel.style.display = 'none';
    if (tab)   { tab.classList.remove('btn-primary'); tab.classList.add('btn-ghost'); }
  }
  const selPanel = document.getElementById('panel-dia-' + n);
  const selTab   = document.getElementById('tab-dia-' + n);
  if (selPanel) selPanel.style.display = 'block';
  if (selTab)   { selTab.classList.remove('btn-ghost'); selTab.classList.add('btn-primary'); }
}

// Si la página cargó con un ?q= pre-cargado, aplicar el filtro inmediatamente
// y restaurar scroll si venimos de un anular
document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('buscador-agenda');
    if (inp && inp.value.trim() !== '') filtrarAgenda(inp.value);

    const savedScroll = sessionStorage.getItem('agenda_scroll');
    if (savedScroll) {
        window.scrollTo({ top: parseInt(savedScroll), behavior: 'instant' });
        sessionStorage.removeItem('agenda_scroll');
    }
});

// ── Estado de Cuenta del cliente ─────────────────────────────
function verEstadoCuenta(creditoId, nombre) {
    escClienteNombre  = nombre;
    escCuotasPagables = [];
    escCreditoData    = null;

    document.getElementById('esc-titulo').textContent = nombre.toUpperCase();
    document.getElementById('esc-subtitulo').textContent = 'Cargando cronograma...';
    document.getElementById('esc-body').innerHTML = '<div class="text-center py-4"><i class="fa fa-spinner fa-spin fa-2x" style="color:var(--primary)"></i></div>';
    document.getElementById('esc-resumen').innerHTML = '';
    document.getElementById('esc-seleccion').style.display = 'none';
    openModal('modal-estado-cuenta');

    fetch('estado_cuenta?credito_id=' + creditoId)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('esc-body').innerHTML = '<p style="color:var(--danger);text-align:center">' + data.error + '</p>';
                return;
            }
            const cr = data.credito;
            escCreditoData    = cr;
            escCuotasPagables = data.cuotas_pagables || [];

            document.getElementById('esc-subtitulo').innerHTML =
                '<i class="fa fa-box-open" style="color:var(--warning)"></i> ' + cr.articulo +
                ' &nbsp;·&nbsp; ' + cr.cant_cuotas + ' cuotas ' + cr.frecuencia + 's' +
                ' &nbsp;·&nbsp; $' + cr.monto_cuota + ' c/u';

            renderSeleccionCuotas(escCuotasPagables);

            let bodyHtml = renderCronograma(data.cuotas);
            if (data.pagos && data.pagos.length > 0) {
                bodyHtml += renderHistorial(data.pagos, data.hist_total);
            }
            document.getElementById('esc-body').innerHTML = bodyHtml;
            const r = data.resumen;
            document.getElementById('esc-resumen').innerHTML =
                '<span style="color:var(--success)">✅ ' + r.pagadas + ' pagada' + (r.pagadas !== 1 ? 's' : '') + '</span>' +
                (r.vencidas  > 0 ? '<span style="color:var(--danger)">🔴 ' + r.vencidas  + ' atrasada' + (r.vencidas !== 1 ? 's' : '') + '</span>' : '') +
                (r.pendientes > 0 ? '<span style="color:#fbbf24">🟡 ' + r.pendientes + ' pendiente' + (r.pendientes !== 1 ? 's' : '') + '</span>' : '') +
                (r.parciales  > 0 ? '<span style="color:#f97316">🟠 ' + r.parciales  + ' parcial' + (r.parciales !== 1 ? 'es' : '') + '</span>' : '');
        })
        .catch(() => {
            document.getElementById('esc-body').innerHTML = '<p style="color:var(--danger);text-align:center">Error al cargar.</p>';
        });
}

function renderHistorial(pagos, hist_total) {
    let html = '<div style="margin-top:18px;border-top:1px solid rgba(255,255,255,.1);padding-top:14px">';
    html += '<div style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px"><i class="fa fa-history"></i> Historial de Pagos Confirmados</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:.8rem">';
    html += '<thead><tr style="color:var(--text-muted);font-size:.7rem;text-transform:uppercase;border-bottom:1px solid rgba(255,255,255,.1)">' +
        '<th style="padding:5px 4px">#</th>' +
        '<th style="padding:5px 4px">Fecha</th>' +
        '<th style="padding:5px 4px;text-align:right">Efectivo</th>' +
        '<th style="padding:5px 4px;text-align:right">Transf.</th>' +
        '<th style="padding:5px 4px;text-align:right">Total</th>' +
        '</tr></thead><tbody>';
    pagos.forEach(p => {
        html += `<tr style="border-bottom:1px solid rgba(255,255,255,.05)">
            <td style="padding:6px 4px;color:var(--text-muted)">#${p.numero_cuota}</td>
            <td style="padding:6px 4px">${p.fecha_fmt}</td>
            <td style="padding:6px 4px;text-align:right">${p.ef_fmt || '—'}</td>
            <td style="padding:6px 4px;text-align:right">${p.tr_fmt || '—'}</td>
            <td style="padding:6px 4px;text-align:right;font-weight:700;color:var(--success)">${p.total_fmt}</td>
        </tr>`;
    });
    html += `</tbody><tfoot><tr style="border-top:1px solid rgba(255,255,255,.15)">
        <td colspan="4" style="padding:6px 4px;font-weight:700;color:var(--text-muted)">TOTAL COBRADO</td>
        <td style="padding:6px 4px;text-align:right;font-weight:800;color:var(--success)">${hist_total}</td>
    </tr></tfoot></table></div>`;
    return html;
}

// ── Anular pago pendiente ────────────────────────────────────
function anularPago(ptId, nombre, btn) {
  document.getElementById('anular-nombre').textContent = nombre;
  const confirmBtn = document.getElementById('anular-confirmar-btn');
  // Clonar para eliminar listeners previos
  const nuevoBtn = confirmBtn.cloneNode(true);
  confirmBtn.parentNode.replaceChild(nuevoBtn, confirmBtn);
  nuevoBtn.onclick = function () {
    nuevoBtn.disabled = true;
    nuevoBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Anulando...';
    const card = btn ? btn.closest('.agenda-card') : null;
    if (card) card.style.opacity = '.4';
    closeModal('modal-anular');
    fetch('anular_pago', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'pt_id=' + ptId
    })
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(d => {
      if (d.ok) {
        sessionStorage.setItem('agenda_scroll', window.scrollY);
        window.location.reload();
      } else {
        if (card) card.style.opacity = '';
        alert('Error: ' + (d.error || 'No se pudo anular.'));
      }
    })
    .catch(e => {
      if (card) card.style.opacity = '';
      alert('Error al anular el pago: ' + e.message);
    });
  };
  openModal('modal-anular');
}

// ── Sección "Cobrar cuotas" en Estado de Cuenta ──────────────

function renderSeleccionCuotas(cuotas) {
    const secEl = document.getElementById('esc-seleccion');
    const listEl = document.getElementById('esc-cuotas-list');

    if (!cuotas || cuotas.length === 0) {
        secEl.style.display = 'none';
        return;
    }

    // Colores por estado
    const estadoStyles = {
        VENCIDA:    { bg: 'rgba(239,68,68,.08)',   border: 'rgba(239,68,68,.3)',   left: '3px solid var(--danger)' },
        PARCIAL:    { bg: 'rgba(249,115,22,.08)',  border: 'rgba(249,115,22,.3)',  left: '3px solid #f97316' },
        CAP_PAGADA: { bg: 'rgba(56,189,248,.08)',  border: 'rgba(56,189,248,.3)',  left: '3px solid #38bdf8' },
        PENDIENTE:  { bg: 'rgba(255,255,255,.04)', border: 'rgba(255,255,255,.1)', left: 'none' },
    };

    const todasBloqueadas = cuotas.every(c => c.pago_pen > 0);
    let html = '';

    if (todasBloqueadas) {
        html += '<div style="text-align:center;padding:10px 12px;color:var(--warning);font-size:.82rem;border-radius:8px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);margin-bottom:8px">' +
            '<i class="fa fa-clock"></i> Todos los pagos están pendientes de aprobación del supervisor.' +
            '</div>';
    }

    cuotas.forEach((c, idx) => {
        const bloqueado = c.pago_pen > 0;
        const st        = estadoStyles[c.estado] || estadoStyles.PENDIENTE;
        const labelEst  = c.estado === 'VENCIDA' ? '🔴' : c.estado === 'CAP_PAGADA' ? '🔵' : c.estado === 'PARCIAL' ? '🟠' : '🟡';
        const montoStr  = formatPesos(c.total);
        const moraStr   = c.mora > 0 && c.estado !== 'CAP_PAGADA'
            ? ' <span style="color:var(--danger);font-size:.72rem">+' + formatPesos(c.mora) + ' mora</span>' : '';
        const bloqStr   = bloqueado
            ? ' <span style="color:var(--warning);font-size:.7rem"><i class="fa fa-lock" style="font-size:.65rem"></i> pendiente</span>' : '';
        html += `<label style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;cursor:${bloqueado ? 'not-allowed' : 'pointer'};background:${st.bg};border:1px solid ${st.border};border-left:${st.left};opacity:${bloqueado ? '.6' : '1'}">
            <input type="checkbox" data-esc-idx="${idx}" onchange="escToggleCuota(${idx})"
                style="width:16px;height:16px;cursor:${bloqueado ? 'not-allowed' : 'pointer'};accent-color:var(--success);flex-shrink:0"
                ${bloqueado ? 'disabled' : ''}>
            <span style="flex:1;font-size:.85rem">
                <strong>#${c.numero_cuota}</strong>
                <span style="color:var(--text-muted);font-size:.78rem"> · ${c.fecha_venc}</span>
                ${labelEst}${moraStr}${bloqStr}
            </span>
            <span style="font-weight:700;color:var(--success);white-space:nowrap">${montoStr}</span>
        </label>`;
    });

    listEl.innerHTML = html;
    secEl.style.display = 'block';

    // Reset estado
    document.getElementById('esc-pura-cb').checked = false;
    document.getElementById('esc-cuota-pura-wrap').style.display = 'none';
    document.getElementById('esc-total-wrap').style.display = 'none';
    document.getElementById('esc-total-val').textContent = formatPesos(0);
}

function escToggleCuota(idx) {
    const checkboxes = document.querySelectorAll('[data-esc-idx]');
    const checked = checkboxes[idx] && checkboxes[idx].checked;

    if (checked) {
        // Al marcar idx → marcar todos 0..idx
        checkboxes.forEach((cb, i) => {
            if (i <= idx && !cb.disabled) cb.checked = true;
        });
    } else {
        // Al desmarcar idx → desmarcar todos idx..end
        checkboxes.forEach((cb, i) => {
            if (i >= idx) cb.checked = false;
        });
    }
    escRecalcular();
}

function escRecalcular() {
    const checkboxes = document.querySelectorAll('[data-esc-idx]');
    const esPura     = document.getElementById('esc-pura-cb').checked;
    let total        = 0;
    let alguna       = false;
    let tieneMora    = false;

    checkboxes.forEach((cb, i) => {
        if (!cb.checked) return;
        alguna = true;
        const c = escCuotasPagables[i];
        if (!c) return;
        if (c.estado === 'CAP_PAGADA') {
            total += c.mora;                     // solo mora congelada
        } else if (esPura) {
            total += c.total - c.mora;           // c.total ya tiene saldo descontado; quitar mora
        } else {
            total += c.total;                    // capital + mora - saldo
        }
        if (c.mora > 0 && c.estado !== 'CAP_PAGADA') tieneMora = true;
    });

    document.getElementById('esc-cuota-pura-wrap').style.display = (alguna && tieneMora) ? 'block' : 'none';
    document.getElementById('esc-total-wrap').style.display = alguna ? 'flex' : 'none';
    document.getElementById('esc-total-val').textContent = formatPesos(total);
}

function escCobrarSeleccionadas() {
    const checkboxes  = document.querySelectorAll('[data-esc-idx]');
    const esPuraBool  = document.getElementById('esc-pura-cb').checked;
    let seleccionadas = [];
    let total         = 0;
    let totalMora     = 0;

    checkboxes.forEach((cb, i) => {
        if (!cb.checked) return;
        const c = escCuotasPagables[i];
        if (!c) return;
        seleccionadas.push(c);
        totalMora += c.mora;
        if (c.estado === 'CAP_PAGADA') {
            total += c.mora;
        } else if (esPuraBool) {
            total += c.total - c.mora;
        } else {
            total += c.total;
        }
    });

    if (seleccionadas.length === 0) {
        alert('Seleccioná al menos una cuota.');
        return;
    }

    const primeraCuota = seleccionadas[0];
    const numLabel = seleccionadas.length > 1
        ? '#' + primeraCuota.numero_cuota + '–' + seleccionadas[seleccionadas.length - 1].numero_cuota
        : '#' + primeraCuota.numero_cuota;

    // Si la primera cuota es CAP_PAGADA pero hay otras regulares, usar el estado de la primera no-CAP_PAGADA
    const estadoPrincipal = seleccionadas.find(c => c.estado !== 'CAP_PAGADA')?.estado || primeraCuota.estado;

    // Construir objeto compatible con _rellenarModal
    const cObj = {
        id:            primeraCuota.id,
        numero_cuota:  numLabel,
        cant_cuotas:   null,
        monto_cuota:   esPuraBool ? total : (total - totalMora),  // capital para display
        mora_calc:     esPuraBool ? 0 : totalMora,
        estado:        estadoPrincipal,
        apellidos:     escClienteNombre,
        nombres:       '',
        articulo:      escCreditoData ? escCreditoData.articulo : '',
        pago_pen:      0,
    };

    closeModal('modal-estado-cuenta');
    _rellenarModal(cObj, total, esPuraBool);
}

function renderCronograma(cuotas) {
    if (!cuotas.length) return '<p style="text-align:center;color:var(--text-muted);padding:20px">Sin cuotas.</p>';

    const iconos = { PAGADA:'✅', PENDIENTE:'🟡', VENCIDA:'🔴', PARCIAL:'🟠', CAP_PAGADA:'🔵' };
    const colores = { PAGADA:'var(--success)', PENDIENTE:'#fbbf24', VENCIDA:'var(--danger)', PARCIAL:'#f97316', CAP_PAGADA:'#38bdf8' };

    let html = '<table style="width:100%;border-collapse:collapse;font-size:.85rem">';
    html += '<thead><tr style="color:var(--text-muted);font-size:.72rem;text-transform:uppercase;border-bottom:1px solid rgba(255,255,255,.1)">' +
        '<th style="padding:6px 4px;width:34px">#</th>' +
        '<th style="padding:6px 4px">Vencimiento</th>' +
        '<th style="padding:6px 4px;text-align:right">Monto</th>' +
        '<th style="padding:6px 4px;text-align:center">Estado</th>' +
        '<th style="padding:6px 4px;font-size:.68rem">Fecha Pago</th>' +
        '</tr></thead><tbody>';

    cuotas.forEach(c => {
        const icono  = iconos[c.estado]  || '⚪';
        const color  = colores[c.estado] || 'var(--text-muted)';
        const opac   = c.estado === 'PAGADA' ? 'opacity:.55' : '';
        const mora   = c.mora_fmt ? ' <span style="color:var(--danger);font-size:.7rem">+$' + c.mora_fmt + ' mora</span>' : '';
        html += `<tr style="${opac};border-bottom:1px solid rgba(255,255,255,.05)">
            <td style="padding:8px 4px;font-weight:700;color:var(--text-muted)">${c.numero_cuota}</td>
            <td style="padding:8px 4px">${c.fecha_vencimiento_fmt}</td>
            <td style="padding:8px 4px;text-align:right;font-weight:700">$${c.monto_fmt}${mora}</td>
            <td style="padding:8px 4px;text-align:center;color:${color};font-weight:700">${icono} ${c.estado_label}</td>
            <td style="padding:8px 4px;font-size:.75rem;color:var(--success)">${c.fecha_pago_fmt ? '✓ ' + c.fecha_pago_fmt : ''}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    return html;
}
</script>
JS;
?>
<!-- Modal No cobré -->
<div class="modal-overlay" id="modal-intento">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-user-slash"></i> No cobré — registrar intento</div>
            <button class="modal-close" onclick="closeModal('modal-intento')">✕</button>
        </div>
        <div id="intento-cliente" style="font-size:.85rem;color:var(--text-muted);margin-bottom:14px"></div>
        <form id="form-intento">
            <input type="hidden" id="intento-cuota-id" name="cuota_id">
            <div class="form-group mb-3">
                <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;display:block">Motivo</label>
                <select name="motivo" id="intento-motivo" style="width:100%;background:var(--dark-input);border:1px solid var(--dark-border);border-radius:6px;color:var(--text-main);padding:9px 12px;font-family:inherit;font-size:.875rem">
                    <option value="no_estaba">No estaba en casa</option>
                    <option value="no_quiso">No quiso pagar</option>
                    <option value="promesa_pago">Prometió pagar</option>
                    <option value="otro">Otro</option>
                </select>
            </div>
            <div id="wrap-fecha-promesa" style="display:none" class="form-group mb-3">
                <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;display:block">Fecha prometida</label>
                <input type="date" name="fecha_promesa" id="intento-fecha"
                       style="width:100%;background:var(--dark-input);border:1px solid var(--dark-border);border-radius:6px;color:var(--text-main);padding:9px 12px;font-family:inherit;font-size:.875rem">
            </div>
            <div class="form-group mb-3">
                <label style="font-size:.75rem;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;display:block">Observación (opcional)</label>
                <textarea name="observacion" rows="2"
                    style="width:100%;background:var(--dark-input);border:1px solid var(--dark-border);border-radius:6px;color:var(--text-main);padding:9px 12px;font-family:inherit;font-size:.875rem;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn-ic btn-warning w-100"><i class="fa fa-save"></i> Registrar</button>
                <button type="button" onclick="closeModal('modal-intento')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<script>
function abrirIntento(cuotaId, nombre) {
    document.getElementById('intento-cuota-id').value = cuotaId;
    document.getElementById('intento-cliente').textContent = nombre;
    document.getElementById('intento-motivo').value = 'no_estaba';
    document.getElementById('wrap-fecha-promesa').style.display = 'none';
    openModal('modal-intento');
}
document.getElementById('intento-motivo').addEventListener('change', function() {
    document.getElementById('wrap-fecha-promesa').style.display = this.value === 'promesa_pago' ? '' : 'none';
});
document.getElementById('form-intento').addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fetch('registrar_intento', {method:'POST', body: fd})
        .then(r=>r.json()).then(d=>{
            if (d.ok) { closeModal('modal-intento'); showToast('Intento registrado', 'success'); }
            else showToast(d.error || 'Error', 'error');
        });
});
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php';
?>