<?php
// ============================================================
// creditos/ver.php — Cronograma de cuotas de un crédito
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT cr.*, cl.nombres, cl.apellidos, cl.telefono, cl.id AS cid,
           a.descripcion AS articulo,
           u.nombre AS cobrador_n, u.apellido AS cobrador_a,
           v.nombre AS vendedor_n, v.apellido AS vendedor_a
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id=cl.id
    JOIN ic_articulos a ON cr.articulo_id=a.id
    JOIN ic_usuarios u ON cr.cobrador_id=u.id
    LEFT JOIN ic_usuarios v ON cr.vendedor_id=v.id
    WHERE cr.id=?
");
$stmt->execute([$id]);
$cr = $stmt->fetch();
if (!$cr) {
    header('Location: index.php');
    exit;
}

$cuotas = $pdo->prepare("SELECT * FROM ic_cuotas WHERE credito_id=? ORDER BY numero_cuota");
$cuotas->execute([$id]);
$lista_cuotas = $cuotas->fetchAll();

// Calcular mora actualizada para cada cuota
$hoy = new DateTime('today');
foreach ($lista_cuotas as &$cuota) {
    if ($cuota['estado'] === 'PENDIENTE' || $cuota['estado'] === 'VENCIDA') {
        $dias = dias_atraso_habiles($cuota['fecha_vencimiento']);
        $cuota['dias_atraso_calc'] = $dias;
        $cuota['mora_calc'] = calcular_mora($cuota['monto_cuota'], $dias, $cr['interes_moratorio_pct']);
    } else {
        $cuota['dias_atraso_calc'] = 0;
        $cuota['mora_calc'] = 0;
    }
}
unset($cuota);

$pagadas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PAGADA'));
$pendientes = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'PENDIENTE'));
$vencidas = count(array_filter($lista_cuotas, fn($c) => $c['estado'] === 'VENCIDA' || ($c['estado'] === 'PENDIENTE' && $c['dias_atraso_calc'] > 0)));
$total_cuotas = count($lista_cuotas);
$porc = $total_cuotas > 0 ? round($pagadas / $total_cuotas * 100) : 0;

$total_mora_pendiente = array_sum(array_map(fn($c) => $c['mora_calc'], $lista_cuotas));
$deuda_pendiente = array_sum(array_map(fn($c) => $c['estado'] !== 'PAGADA' ? $c['monto_cuota'] : 0, $lista_cuotas));

$page_title = 'Crédito #' . $id;
$page_current = 'creditos';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- KPI CARDS -->
<div class="kpi-grid mb-4">
    <div class="kpi-card" style="--kpi-color:var(--primary)">
        <i class="fa fa-box-open kpi-icon"></i>
        <div class="kpi-label">Artículo</div>
        <div class="kpi-value" style="font-size:1rem;margin-top:8px">
            <?= e($cr['articulo']) ?>
        </div>
        <div class="kpi-sub">Crédito #
            <?= $id ?> —
            <?= badge_estado_credito($cr['estado']) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--success)">
        <i class="fa fa-check-circle kpi-icon"></i>
        <div class="kpi-label">Pagadas</div>
        <div class="kpi-value">
            <?= $pagadas ?>/
            <?= $total_cuotas ?>
        </div>
        <div class="kpi-sub">
            <?= $porc ?>% completado
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--warning)">
        <i class="fa fa-clock kpi-icon"></i>
        <div class="kpi-label">Deuda Capital</div>
        <div class="kpi-value" style="font-size:1.3rem">
            <?= formato_pesos($deuda_pendiente) ?>
        </div>
        <div class="kpi-sub">
            <?= $pendientes ?> cuota
            <?= $pendientes !== 1 ? 's' : '' ?> pendiente
            <?= $pendientes !== 1 ? 's' : '' ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:var(--danger)">
        <i class="fa fa-fire kpi-icon"></i>
        <div class="kpi-label">Mora Acumulada</div>
        <div class="kpi-value" style="font-size:1.3rem;color:var(--danger)">
            <?= formato_pesos($total_mora_pendiente) ?>
        </div>
        <div class="kpi-sub">
            <?= $vencidas ?> cuota
            <?= $vencidas !== 1 ? 's' : '' ?> vencida
            <?= $vencidas !== 1 ? 's' : '' ?>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">

    <!-- SIDEBAR DEL CRÉDITO -->
    <div>
        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title">Datos del Crédito</span></div>
            <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                <tr>
                    <td class="text-muted" style="padding:5px 0;width=45%">Cliente</td>
                    <td><a href="../clientes/ver.php?id=<?= $cr['cid'] ?>" class="fw-bold">
                            <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                        </a></td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Artículo</td>
                    <td>
                        <?= e($cr['articulo']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Precio art.</td>
                    <td>
                        <?= formato_pesos($cr['precio_articulo']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Interés</td>
                    <td>
                        <?= $cr['interes_pct'] ?>%
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Monto total</td>
                    <td class="fw-bold">
                        <?= formato_pesos($cr['monto_total']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Monto cuota</td>
                    <td>
                        <?= formato_pesos($cr['monto_cuota']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cuotas</td>
                    <td>
                        <?= $cr['cant_cuotas'] ?> (
                        <?= $cr['frecuencia'] ?>)
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Mora/sem.</td>
                    <td>
                        <?= $cr['interes_moratorio_pct'] ?>%
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Alta</td>
                    <td>
                        <?= date('d/m/Y', strtotime($cr['fecha_alta'])) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">1er venc.</td>
                    <td>
                        <?= date('d/m/Y', strtotime($cr['primer_vencimiento'])) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cobrador</td>
                    <td>
                        <?= e($cr['cobrador_n'] . ' ' . $cr['cobrador_a']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Vendedor</td>
                    <td>
                        <?= isset($cr['vendedor_n']) ? e($cr['vendedor_n'] . ' ' . $cr['vendedor_a']) : '<span class="text-muted">No asignado</span>' ?>
                        <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                            <a href="cambiar_vendedor.php?id=<?= $id ?>" class="btn-ic btn-ghost btn-sm" title="Cambiar vendedor" style="padding:2px 5px; font-size:.7rem; margin-left:10px;"><i class="fa fa-edit"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <hr class="divider">
            <!-- Barra de progreso -->
            <div class="text-muted mb-2" style="font-size:.75rem">
                <?= $porc ?>% pagado
            </div>
            <div style="background:var(--dark-border);border-radius:6px;height:8px">
                <div
                    style="width:<?= $porc ?>%;height:100%;background:var(--success);border-radius:6px;transition:width .4s">
                </div>
            </div>
            <hr class="divider">
            <div class="d-flex gap-2">
                <a href="imprimir_cronograma.php?id=<?= $id ?>" target="_blank" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-print"></i> PDF
                </a>
                <a href="../clientes/ver.php?id=<?= $cr['cid'] ?>" class="btn-ic btn-ghost btn-sm">
                    <i class="fa fa-user"></i> Cliente
                </a>
                <?php if (in_array($cr['estado'], ['EN_CURSO', 'MOROSO'])): ?>
                    <a href="finalizar.php?id=<?= $id ?>" class="btn-ic btn-danger btn-sm" title="Finalizar Crédito">
                        <i class="fa fa-power-off"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- CRONOGRAMA -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-calendar-alt"></i> Cronograma de Cuotas</span>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Vencimiento</th>
                        <th>Monto Cuota</th>
                        <th>Días Atraso</th>
                        <th>Mora</th>
                        <th>Total a Pagar</th>
                        <th>Estado</th>
                        <th>Pago</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_cuotas as $q): ?>
                        <?php
                        $esAtrasada = $q['dias_atraso_calc'] > 0;
                        $rowStyle = $esAtrasada && $q['estado'] !== 'PAGADA' ? 'background:rgba(239,68,68,.05)' : '';
                        ?>
                        <tr style="<?= $rowStyle ?>">
                            <td class="text-muted">
                                <?= $q['numero_cuota'] ?>
                            </td>
                            <td class="nowrap <?= $esAtrasada && $q['estado'] !== 'PAGADA' ? 'text-danger' : '' ?>">
                                <?= date('d/m/Y', strtotime($q['fecha_vencimiento'])) ?>
                            </td>
                            <td class="nowrap">
                                <?= formato_pesos($q['monto_cuota']) ?>
                            </td>
                            <td class="text-center <?= $q['dias_atraso_calc'] > 0 ? 'text-warning' : '' ?>">
                                <?= $q['dias_atraso_calc'] > 0 ? $q['dias_atraso_calc'] . ' hábiles' : '—' ?>
                            </td>
                            <td class="nowrap <?= $q['mora_calc'] > 0 ? 'text-danger' : '' ?>">
                                <?= $q['mora_calc'] > 0 ? formato_pesos($q['mora_calc']) : '—' ?>
                            </td>
                            <td class="nowrap fw-bold">
                                <?= formato_pesos($q['monto_cuota'] + $q['mora_calc']) ?>
                            </td>
                            <td>
                                <?php $badgeMap = ['PENDIENTE' => 'badge-warning', 'PAGADA' => 'badge-success', 'VENCIDA' => 'badge-danger', 'PARCIAL' => 'badge-primary']; ?>
                                <span class="badge-ic <?= $badgeMap[$q['estado']] ?? 'badge-muted' ?>">
                                    <?= $q['estado'] ?>
                                </span>
                            </td>
                            <td class="nowrap">
                                <?= $q['fecha_pago'] ? date('d/m/Y', strtotime($q['fecha_pago'])) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>