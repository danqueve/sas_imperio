<?php
// ============================================================
// admin/proximos_cerrar.php — Créditos próximos a finalizar
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// Filtros
$cobrador_f = (int)($_GET['cobrador_id'] ?? 0);
$cuotas_f   = max(1, min(5, (int)($_GET['cuotas_max'] ?? 2)));

$cobradores = $pdo->query("
    SELECT id, nombre, apellido FROM ic_usuarios
    WHERE rol IN ('cobrador','supervisor') AND activo=1
    ORDER BY nombre
")->fetchAll();

$params = [$cuotas_f];
$where_cob = '';
if ($cobrador_f) { $where_cob = 'AND cr.cobrador_id = ?'; $params[] = $cobrador_f; }

$stmt = $pdo->prepare("
    SELECT
        cr.id AS credito_id,
        CONCAT(cl.apellidos, ', ', cl.nombres) AS cliente,
        cl.id AS cliente_id,
        cl.telefono,
        cl.puntaje_pago,
        cl.total_creditos_finalizados,
        cl.creditos_sin_mora,
        CONCAT(u.nombre, ' ', u.apellido) AS cobrador,
        COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
        cr.monto_total,
        cr.monto_cuota,
        cr.fecha_alta,
        cr.veces_refinanciado,
        -- Cuotas pendientes
        (SELECT COUNT(*) FROM ic_cuotas
         WHERE credito_id = cr.id AND estado NOT IN ('PAGADA','CANCELADA')) AS cuotas_pendientes,
        -- Total cuotas
        (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id = cr.id) AS total_cuotas,
        -- Fecha del próximo vencimiento
        (SELECT MIN(fecha_vencimiento) FROM ic_cuotas
         WHERE credito_id = cr.id AND estado NOT IN ('PAGADA','CANCELADA')) AS proximo_vencimiento,
        -- Fecha del último vencimiento (para ver cuándo termina)
        (SELECT MAX(fecha_vencimiento) FROM ic_cuotas
         WHERE credito_id = cr.id) AS ultimo_vencimiento,
        -- Monto aún por cobrar
        (SELECT COALESCE(SUM(monto_cuota - COALESCE(saldo_pagado,0)), 0) FROM ic_cuotas
         WHERE credito_id = cr.id AND estado NOT IN ('PAGADA','CANCELADA')) AS saldo_restante
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    JOIN ic_usuarios u ON cr.cobrador_id = u.id
    WHERE cr.estado = 'EN_CURSO'
      $where_cob
    HAVING cuotas_pendientes BETWEEN 1 AND ?
    ORDER BY cuotas_pendientes ASC, proximo_vencimiento ASC
");

// El HAVING usa cuotas_max dos veces (once for WHERE, once in HAVING)
// Rebuild params: [cobrador_id?, cuotas_max(HAVING)]
$params_q = $cobrador_f ? [$cobrador_f, $cuotas_f] : [$cuotas_f];
$stmt->execute($params_q);
$rows = $stmt->fetchAll();

$total = count($rows);
$puntaje_labels = [
    1 => ['⭐⭐⭐ Excelente', 'success', 'Cliente paga siempre a tiempo, sin mora.'],
    2 => ['⭐⭐ Bueno',      'primary', 'Alguna mora menor pero paga regularmente.'],
    3 => ['⭐ Regular',      'warning', 'Mora frecuente o refinanciación.'],
    4 => ['⚠️ Malo',        'danger',  'Mora alta, múltiples refinanciaciones.'],
];

$page_title   = 'Próximos a Cerrar';
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- ── Filtros ──────────────────────────────────────────────── -->
<form method="GET" class="card-ic mb-4" style="padding:14px">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div class="form-group" style="margin:0;min-width:180px">
            <label style="font-size:.78rem">Hasta cuántas cuotas pendientes</label>
            <select name="cuotas_max" class="form-control">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                <option value="<?= $n ?>" <?= $cuotas_f === $n ? 'selected' : '' ?>>
                    <?= $n ?> cuota<?= $n > 1 ? 's' : '' ?> pendiente<?= $n > 1 ? 's' : '' ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;min-width:200px">
            <label style="font-size:.78rem">Cobrador</label>
            <select name="cobrador_id" class="form-control">
                <option value="0">— Todos —</option>
                <?php foreach ($cobradores as $cob): ?>
                <option value="<?= $cob['id'] ?>" <?= $cobrador_f === (int)$cob['id'] ? 'selected' : '' ?>>
                    <?= e($cob['nombre'] . ' ' . $cob['apellido']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-ic btn-primary" style="align-self:flex-end">
            <i class="fa fa-filter"></i> Filtrar
        </button>
    </div>
</form>

<!-- ── Resumen ──────────────────────────────────────────────── -->
<?php
$excelentes = count(array_filter($rows, fn($r) => (int)$r['puntaje_pago'] === 1));
$buenos     = count(array_filter($rows, fn($r) => (int)$r['puntaje_pago'] === 2));
$regulares  = count(array_filter($rows, fn($r) => (int)$r['puntaje_pago'] === 3));
$malos      = count(array_filter($rows, fn($r) => (int)$r['puntaje_pago'] === 4));
$sin_puntaje = count(array_filter($rows, fn($r) => empty($r['puntaje_pago'])));
$saldo_total = array_sum(array_column($rows, 'saldo_restante'));
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px">
    <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(6,182,212,.15);--icon-color:#06b6d4"><i class="fa fa-flag-checkered"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Total próximos a cerrar</div>
            <div class="kpi-value"><?= $total ?></div>
            <div class="kpi-sub"><?= formato_pesos($saldo_total) ?> por cobrar</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(16,185,129,.15);--icon-color:#10b981"><i class="fa fa-star"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">⭐⭐⭐ Excelente</div>
            <div class="kpi-value"><?= $excelentes ?></div>
            <div class="kpi-sub">sin mora histórica</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(99,102,241,.15);--icon-color:#6366f1"><i class="fa fa-star-half-stroke"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">⭐⭐ Bueno / ⭐ Regular</div>
            <div class="kpi-value"><?= $buenos + $regulares ?></div>
            <div class="kpi-sub"><?= $buenos ?> buenos + <?= $regulares ?> regulares</div>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <div class="kpi-icon-box" style="--icon-bg:rgba(239,68,68,.15);--icon-color:#ef4444"><i class="fa fa-triangle-exclamation"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">⚠️ Malo / Sin puntaje</div>
            <div class="kpi-value"><?= $malos + $sin_puntaje ?></div>
            <div class="kpi-sub"><?= $malos ?> malos · <?= $sin_puntaje ?> sin calificación</div>
        </div>
    </div>
</div>

<!-- ── Tabla ──────────────────────────────────────────────────── -->
<?php if (!$rows): ?>
<div class="card-ic" style="padding:50px;text-align:center">
    <i class="fa fa-check-circle fa-3x text-success" style="margin-bottom:14px;display:block"></i>
    <p class="text-muted">No hay créditos con <?= $cuotas_f ?> o menos cuotas pendientes<?= $cobrador_f ? ' para el cobrador seleccionado' : '' ?>.</p>
</div>
<?php else: ?>
<div class="card-ic" style="overflow-x:auto">
    <table class="table-ic" style="font-size:.85rem">
        <thead>
            <tr>
                <th>Puntaje</th>
                <th>Cliente</th>
                <th>Artículo / Crédito</th>
                <th>Cobrador</th>
                <th>Cuotas pend.</th>
                <th>Próx. venc.</th>
                <th>Saldo restante</th>
                <th>Historial</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php
            $pq = $r['puntaje_pago'] ? (int)$r['puntaje_pago'] : null;
            [$punt_label, $punt_color, $punt_tip] = $puntaje_labels[$pq] ?? ['Sin calificación', 'secondary', 'Primera vez o sin historial suficiente.'];
            $cuotas_pend = (int)$r['cuotas_pendientes'];
            $urgencia_style = $cuotas_pend === 1
                ? 'background:rgba(16,185,129,.04);border-left:3px solid var(--success)'
                : '';
            ?>
            <tr style="<?= $urgencia_style ?>">
                <td>
                    <?php if ($pq): ?>
                    <span class="badge-ic badge-<?= $punt_color ?>" title="<?= e($punt_tip) ?>" style="white-space:nowrap;cursor:help">
                        <?= $punt_label ?>
                    </span>
                    <?php else: ?>
                    <span class="badge-ic badge-secondary" title="Sin historial de créditos finalizados">Sin datos</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>clientes/ver?id=<?= (int)$r['cliente_id'] ?>"
                       class="fw-bold" style="color:inherit;text-decoration:underline dotted" target="_blank">
                        <?= e($r['cliente']) ?>
                    </a>
                    <?php if ($r['telefono']): ?>
                    <br>
                    <a href="<?= e(whatsapp_url($r['telefono'])) ?>" target="_blank"
                       class="text-muted" style="font-size:.72rem;text-decoration:none">
                        <i class="fa-brands fa-whatsapp" style="color:#25d366"></i>
                        <?= e($r['telefono']) ?>
                    </a>
                    <?php endif; ?>
                </td>
                <td style="font-size:.80rem">
                    <?= e($r['articulo']) ?>
                    <br><span class="text-muted">#<?= $r['credito_id'] ?> · <?= formato_pesos($r['monto_total']) ?></span>
                </td>
                <td class="text-muted" style="font-size:.80rem"><?= e($r['cobrador']) ?></td>
                <td>
                    <span class="badge-ic <?= $cuotas_pend === 1 ? 'badge-success' : 'badge-warning' ?>"
                          style="font-size:.9rem;font-weight:700">
                        <?= $cuotas_pend ?>
                    </span>
                    <span class="text-muted" style="font-size:.75rem"> de <?= (int)$r['total_cuotas'] ?></span>
                </td>
                <td class="nowrap" style="font-size:.80rem">
                    <?php if ($r['proximo_vencimiento']): ?>
                        <?php
                        $dias_prox = (new DateTime('today'))->diff(new DateTime($r['proximo_vencimiento']))->days;
                        $pasado    = new DateTime($r['proximo_vencimiento']) < new DateTime('today');
                        $color_venc = $pasado ? 'var(--danger)' : ($dias_prox <= 7 ? 'var(--warning)' : 'inherit');
                        ?>
                        <span style="color:<?= $color_venc ?>">
                            <?= date('d/m/Y', strtotime($r['proximo_vencimiento'])) ?>
                            <?php if ($pasado): ?><br><span style="font-size:.70rem">vencida</span>
                            <?php elseif ($dias_prox <= 7): ?><br><span style="font-size:.70rem">en <?= $dias_prox ?> días</span>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="fw-bold">
                    <?= formato_pesos($r['saldo_restante']) ?>
                </td>
                <td style="font-size:.78rem">
                    <?php if ((int)$r['total_creditos_finalizados'] > 0): ?>
                        <span class="text-muted"><?= (int)$r['total_creditos_finalizados'] ?> crédito<?= (int)$r['total_creditos_finalizados'] > 1 ? 's' : '' ?> cerrado<?= (int)$r['total_creditos_finalizados'] > 1 ? 's' : '' ?></span>
                        <?php if ((int)$r['creditos_sin_mora'] > 0): ?>
                        <br><span style="color:var(--success);font-size:.72rem">
                            ✓ <?= (int)$r['creditos_sin_mora'] ?> sin mora
                        </span>
                        <?php endif; ?>
                        <?php if ((int)$r['veces_refinanciado'] > 0): ?>
                        <br><span style="color:var(--warning);font-size:.72rem">
                            ↺ <?= (int)$r['veces_refinanciado'] ?> refinanciación<?= (int)$r['veces_refinanciado'] > 1 ? 'es' : '' ?>
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">Primer crédito</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px">
                        <a href="<?= BASE_URL ?>creditos/ver?id=<?= (int)$r['credito_id'] ?>"
                           class="btn-ic btn-ghost btn-icon btn-sm" title="Ver crédito" target="_blank">
                            <i class="fa fa-eye"></i>
                        </a>
                        <?php if ($r['telefono']): ?>
                        <a href="<?= e(whatsapp_finalizacion_url($r['telefono'], explode(',', $r['cliente'])[1] ?? $r['cliente'], $r['articulo'])) ?>"
                           target="_blank"
                           class="btn-ic btn-ghost btn-icon btn-sm" title="Enviar mensaje de renovación"
                           style="color:#25d366">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
