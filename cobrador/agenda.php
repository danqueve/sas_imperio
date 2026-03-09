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

// Filtro de cobrador (admin/supervisor pueden ver a otros)
$cobrador_filtro = $is_cobrador ? $user_id : (int) ($_GET['cobrador_id'] ?? $user_id);
$q_busca = trim($_GET['q'] ?? '');

// Cuotas del día: vencen hoy (semanal con dia_cobro = hoy)
// También incluye cuotas vencidas pendientes del cobrador
$condCobrador = 'AND cr.cobrador_id = ?';

$sql = "
    SELECT cu.*, cr.id AS credito_id, cr.frecuencia, cr.interes_moratorio_pct, cr.cobrador_id,
           cr.cant_cuotas,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE cu.estado IN ('PENDIENTE','VENCIDA')
      AND cr.estado = 'EN_CURSO'
      $condCobrador
    ORDER BY cu.fecha_vencimiento ASC, cl.apellidos ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$cobrador_filtro]);
$todas = $stmt->fetchAll();

// Calcular mora y separar: del día vs vencidas
$del_dia = [];
$vencidas = [];
$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

foreach ($todas as $c) {
    $dias_atraso = dias_atraso_habiles($c['fecha_vencimiento']);
    $mora = calcular_mora($c['monto_cuota'], $dias_atraso, $c['interes_moratorio_pct']);
    $c['dias_atraso_calc'] = $dias_atraso;
    $c['mora_calc'] = $mora;
    $c['total_a_cobrar'] = $c['monto_cuota'] + $mora;

    // Filtro búsqueda
    if ($q_busca !== '' && !str_contains(strtolower($c['apellidos'] . ' ' . $c['nombres']), strtolower($q_busca)))
        continue;

    if ($dias_atraso === 0 && $c['fecha_vencimiento'] === $hoy) {
        $del_dia[] = $c;
    } elseif ($dias_atraso > 0) {
        $vencidas[] = $c;
    } elseif ($c['fecha_vencimiento'] === $hoy) {
        $del_dia[] = $c;
    }
}

// ── Guardar totales originales para KPIs (antes de deduplicar) ─
$kpi_del_dia   = count($del_dia);
$kpi_vencidas  = count($vencidas);
$kpi_total_hoy = array_sum(array_map(fn($c) => $c['total_a_cobrar'], $del_dia));
$kpi_mora_venc = array_sum(array_map(fn($c) => $c['mora_calc'], $vencidas));

// KPI INGRESOS HOY (solo pendientes de rendición)
$stmt_ingresos = $pdo->prepare("
    SELECT SUM(monto_total) AS total,
           SUM(monto_efectivo) AS efectivo,
           SUM(monto_transferencia) AS transferencia
    FROM ic_pagos_temporales 
    WHERE cobrador_id = ? AND DATE(fecha_registro) = ? AND estado = 'PENDIENTE'
");
$stmt_ingresos->execute([$cobrador_filtro, $hoy]);
$row_ingresos = $stmt_ingresos->fetch();
$kpi_ingresos_hoy = (float) $row_ingresos['total'];
$kpi_ingresos_efectivo = (float) $row_ingresos['efectivo'];
$kpi_ingresos_transferencia = (float) $row_ingresos['transferencia'];

// ── Lista de Cobrados Hoy ──────────────────────────────────────
$stmt_cobrados = $pdo->prepare("
    SELECT cu.*, cr.id AS credito_id, cr.frecuencia, cr.interes_moratorio_pct, cr.cobrador_id,
           cr.cant_cuotas,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           1 AS pago_pen
    FROM ic_pagos_temporales pt
    JOIN ic_cuotas cu ON pt.cuota_id = cu.id
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE pt.cobrador_id = ? AND DATE(pt.fecha_registro) = ? AND pt.estado = 'PENDIENTE'
    ORDER BY pt.fecha_registro DESC
");
$stmt_cobrados->execute([$cobrador_filtro, $hoy]);
$cobrados_hoy_raw = $stmt_cobrados->fetchAll();

$cobrados_hoy = [];
foreach ($cobrados_hoy_raw as $c) {
    $dias_atraso = dias_atraso_habiles($c['fecha_vencimiento']);
    $mora = calcular_mora($c['monto_cuota'], $dias_atraso, $c['interes_moratorio_pct']);
    $c['dias_atraso_calc'] = $dias_atraso;
    $c['mora_calc'] = $mora;
    $c['total_a_cobrar'] = $c['monto_cuota'] + $mora;
    $cobrados_hoy[] = $c;
}

// ── Deduplicar: un registro por cliente ────────────────────────
// Si un cliente tiene varias cuotas atrasadas, aparece una sola vez
// con un badge que indica cuántas cuotas acumula.
$venc_por_cliente = [];
foreach ($vencidas as $v) {
    $venc_por_cliente[$v['cliente_id']][] = $v;
}

// del_dia: conservar la cuota de hoy, agregar count de atrasadas extras
$del_dia_dedup = [];
foreach ($del_dia as $c) {
    $cid = $c['cliente_id'];
    if (!isset($del_dia_dedup[$cid])) {
        $c['cuotas_atrasadas'] = count($venc_por_cliente[$cid] ?? []);
        $del_dia_dedup[$cid]   = $c;
    }
}
$del_dia = array_values($del_dia_dedup);

// vencidas: excluir clientes ya mostrados en del_dia, deduplicar el resto
$vencidas = [];
foreach ($venc_por_cliente as $cid => $cuotas) {
    if (isset($del_dia_dedup[$cid])) continue; // ya aparece arriba con badge
    $row = $cuotas[0]; // más antigua primero (ORDER BY fecha_vencimiento ASC)
    $row['cuotas_atrasadas'] = count($cuotas);
    $vencidas[] = $row;
}

// ── Vista semanal: clientes por dia_cobro ─────────────────────
$semana_stmt = $pdo->prepare("
    SELECT cu.id,
           cl.id AS cliente_id,
           cl.nombres, cl.apellidos, cl.telefono, cl.zona, cl.dia_cobro,
           cl.coordenadas,
           cr.interes_moratorio_pct, cr.cant_cuotas,
           cu.id AS cuota_id, cu.numero_cuota, cu.fecha_vencimiento, cu.monto_cuota,
           cu.estado AS cuota_estado,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen
    FROM ic_clientes cl
    JOIN ic_creditos cr ON cr.cliente_id  = cl.id AND cr.cobrador_id = ? AND cr.estado = 'EN_CURSO'
    JOIN ic_cuotas  cu ON cu.credito_id   = cr.id AND cu.estado IN ('PENDIENTE','VENCIDA')
    LEFT JOIN ic_articulos a ON a.id           = cr.articulo_id
    WHERE cl.dia_cobro BETWEEN 1 AND 6
    ORDER BY cl.dia_cobro ASC, cl.apellidos ASC, cu.fecha_vencimiento ASC
");
$semana_stmt->execute([$cobrador_filtro]);
$semana_rows_raw = $semana_stmt->fetchAll();

// Calcular mora para cada fila semanal
$semana_rows = [];
$semana_por_cliente = [];

foreach ($semana_rows_raw as $r) {
    $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);
    $mora        = calcular_mora($r['monto_cuota'], $dias_atraso, $r['interes_moratorio_pct']);
    $r['dias_atraso_calc'] = $dias_atraso;
    $r['mora_calc']        = $mora;
    $r['total_a_cobrar']   = $r['monto_cuota'] + $mora;
    $r['pago_pen']         = $r['pago_pen'] ?? 0;
    
    // Agrupar por cliente para deduplicar
    $semana_por_cliente[$r['cliente_id']][] = $r;
}

// Extraer solo 1 cuota por cliente para la vista semanal y calcular el total de cuotas atrasadas de ese crédito
foreach ($semana_por_cliente as $cid => $cuotas_cl) {
    $row = $cuotas_cl[0]; // La primera es la más antigua según el ORDER BY
    $row['cuotas_atrasadas'] = count($cuotas_cl);
    
    // Si la cuota ya está vencida en la semana actual y también figuraba en $venc_por_cliente, el número de cuotas total
    // atrasadas será el de la totalidad de su historial. Nos aseguramos de cruzar ese dato.
    if (isset($venc_por_cliente[$cid]) && count($venc_por_cliente[$cid]) > count($cuotas_cl)) {
       $row['cuotas_atrasadas'] = count($venc_por_cliente[$cid]);
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
           cr.cant_cuotas,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.telefono, cl.coordenadas, cl.zona,
           COALESCE(cr.articulo_desc, art.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_pagos_temporales pt WHERE pt.cuota_id=cu.id AND pt.estado='PENDIENTE') AS pago_pen
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos art ON cr.articulo_id = art.id
    WHERE cu.estado IN ('PENDIENTE', 'VENCIDA')
      AND cr.estado = 'EN_CURSO'
      AND cr.frecuencia IN ('quincenal', 'mensual')
      AND cr.cobrador_id = ?
    ORDER BY cu.fecha_vencimiento ASC, cl.apellidos ASC
");
$mensual_stmt->execute([$cobrador_filtro]);
$mensual_rows_raw = $mensual_stmt->fetchAll();

$mensual_rows = [];
foreach ($mensual_rows_raw as $r) {
    if ($q_busca !== '' && !str_contains(strtolower($r['apellidos'] . ' ' . $r['nombres']), strtolower($q_busca)))
        continue;
    $dias_atraso = dias_atraso_habiles($r['fecha_vencimiento']);
    $mora        = calcular_mora($r['monto_cuota'], $dias_atraso, $r['interes_moratorio_pct']);
    $r['dias_atraso_calc'] = $dias_atraso;
    $r['mora_calc']        = $mora;
    $r['total_a_cobrar']   = $r['monto_cuota'] + $mora;
    $r['pago_pen']         = (int)($r['pago_pen'] ?? 0);
    $r['cuotas_atrasadas'] = 0;
    $mensual_rows[] = $r;
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
        <input type="text" name="q" value="<?= e($q_busca) ?>" placeholder="🔍 Buscar cliente...">
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
        <form id="form-agenda-pdf" target="_blank" action="agenda_print.php" method="GET">
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
            <div class="form-group mb-4">
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
        <div class="kpi-sub">cuotas sin cobrar acumuladas</div>
    </div>
</div>

<?php
// Helper: fecha relativa
function fecha_relativa(string $fecha_str): string {
    $hoy  = new DateTime('today');
    $venc = new DateTime($fecha_str);
    $diff = (int) $hoy->diff($venc)->days;
    $fut  = $venc >= $hoy;
    if ($diff === 0) return '<span style="color:var(--warning)">hoy</span>';
    if (!$fut)      return '<span style="color:var(--danger)">hace ' . $diff . ' día' . ($diff > 1 ? 's' : '') . '</span>';
    if ($diff === 1) return '<span style="color:var(--success)">mañana</span>';
    if ($diff < 7)  return '<span style="color:var(--success)">en ' . $diff . ' días</span>';
    $sem = (int) ceil($diff / 7);
    return '<span style="color:var(--text-muted)">en ' . $sem . ' semana' . ($sem > 1 ? 's' : '') . '</span>';
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
    <div class="list-group-item agenda-card <?= $mora_pos ? 'agenda-card--vencida' : '' ?>" id="row-<?= $c['id'] ?>">
        
        <div class="agenda-card-header">
            <div class="agenda-card-client">
                <button class="agenda-cliente-btn" onclick="toggleArticulo(<?= $c['id'] ?>)" title="Ver artículo">
                    <span class="agenda-nombre"><?= e(strtoupper($c['apellidos'] . ' ' . $c['nombres'])) ?></span>
                    <span class="agenda-zona">Zona: <?= e($c['zona'] ?: '—') ?></span>
                </button>
                <?php if (!empty($c['cuotas_atrasadas']) && $c['cuotas_atrasadas'] > 0): ?>
                    <span class="agenda-badge-danger">
                        <i class="fa fa-triangle-exclamation"></i>
                        <?= $c['cuotas_atrasadas'] ?> u. atrasadas
                    </span>
                <?php endif; ?>
                <div class="agenda-articulo" id="art-<?= $c['id'] ?>" style="display:none">
                    <i class="fa fa-box-open"></i> <?= e($c['articulo']) ?>
                </div>
            </div>
            
            <div class="agenda-card-amounts text-end" style="text-align: right;">
                <span class="agenda-monto"><?= formato_pesos($c['monto_cuota']) ?></span>
                <span class="agenda-cuota-num">Cuota <?= $c['numero_cuota'] ?>/<?= $c['cant_cuotas'] ?></span>
                <?php if ($mora_pos): ?>
                    <span class="agenda-mora">+Mora: <?= formato_pesos($c['mora_calc']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="agenda-card-body">
            <div class="agenda-card-cobro">
                <?php if ($c['pago_pen'] == 0): ?>
                    <div class="agenda-cobro-wrap" style="display: flex; gap: 8px; width: 100%;">
                        <input type="number" class="agenda-cobro-input" style="flex: 1;" id="inp-<?= $c['id'] ?>"
                            value="<?= number_format($c['total_a_cobrar'], 2, '.', '') ?>"
                            step="0.01" min="0" placeholder="0.00">
                        <button class="agenda-cobro-btn" onclick="abrirPagoDesdeRow(<?= $data ?>)"
                            title="Registrar pago">
                            <i class="fa fa-check"></i> <span style="margin-left:4px;">Cobrar</span>
                        </button>
                    </div>
                <?php else: ?>
                    <div style="width: 100%; text-align: center; padding: 12px 0; background: rgba(255,193,7,.1); border-radius: 8px;">
                        <span style="color: var(--warning); font-weight: 700;"><i class="fa fa-clock"></i> Pago Registrado</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="agenda-card-footer" style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 16px;">
            <div class="agenda-vencimiento" style="line-height: 1.4;">
                <span style="color: var(--text-muted); font-size: .8rem;">Venció:</span> <strong style="color: var(--text)"><?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></strong><br>
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
            </div>
        </div>

    </div>
<?php endforeach;
    echo '</div>';
    return ob_get_clean();
}
?>

<!-- CUOTAS DEL DÍA -->
<div class="card-ic mb-4">
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
    <div class="card-ic mb-4">
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
    <div class="card-ic mb-4">
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
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-calendar-week"></i> Vista Semanal</span>
        <span class="text-muted" style="font-size:.82rem">
            <?= count($semana_rows) ?> cuota(s) pendientes esta semana
        </span>
    </div>

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
</div>

<!-- ── CRÉDITOS QUINCENALES Y MENSUALES ──────────────────────── -->
<?php if (!empty($mensual_rows)): ?>
<div class="card-ic mb-4">
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
    <div id="col-mensual">
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

<!-- MODAL REGISTRAR PAGO -->
<div class="modal-overlay" id="modal-pago">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fa fa-dollar-sign"></i> Registrar Pago</div>
            <button class="modal-close" onclick="closeModal('modal-pago')">✕</button>
        </div>
        <div id="modal-info"
            style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px;margin-bottom:16px;font-size:.875rem"></div>
        <form method="POST" action="registrar_pago" class="form-ic" id="form-pago">
            <input type="hidden" name="cuota_id" id="input_cuota_id">
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
            <div class="d-flex gap-3">
                <button type="submit" class="btn-ic btn-success w-100" style="justify-content:center">
                    <i class="fa fa-save"></i> Confirmar Pago
                </button>
                <button type="button" onclick="closeModal('modal-pago')" class="btn-ic btn-ghost">Cancelar</button>
            </div>
        </form>
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
.list-group-item.agenda-card--vencida { border-left: 4px solid var(--danger); background: rgba(220,53,69,.04) !important; }
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
.agenda-badge-danger {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px;
    background: rgba(220,53,69,.15); color: var(--danger); font-size: .75rem;
    border-radius: 4px; font-weight: 700; margin-top: 6px;
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
let cuota_mora = 0;

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
function abrirPagoDesdeRow(c) {
  const inp = document.getElementById('inp-' + c.id);
  const monto = inp ? parseFloat(inp.value) || c.total_a_cobrar : c.total_a_cobrar;
  _rellenarModal(c, monto);
}

function _rellenarModal(c, montoInicial) {
  cuota_mora = c.mora_calc || 0;
  document.getElementById('input_cuota_id').value = c.id;
  document.getElementById('modal-info').innerHTML =
    '<strong>' + c.apellidos + ', ' + c.nombres + '</strong><br>' +
    'Cuota #' + c.numero_cuota + (c.cant_cuotas ? '/' + c.cant_cuotas : '') +
    ' — Capital: ' + formatPesos(c.monto_cuota) +
    (cuota_mora > 0 ? ' + Mora: ' + formatPesos(cuota_mora) : '') +
    (c.articulo ? '<br><small style="color:var(--warning)">📦 ' + c.articulo + '</small>' : '') +
    '<br><strong>Total sugerido: ' + formatPesos(c.total_a_cobrar) + '</strong>';
  document.getElementById('inp_efectivo').value  = montoInicial.toFixed(2);
  document.getElementById('inp_transfer').value  = '0';
  document.getElementById('inp_mora_cobrada').value = cuota_mora.toFixed(2);
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
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>