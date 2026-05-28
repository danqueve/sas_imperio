<?php
// ============================================================
// ventas/mis_clientes.php — Cartera de clientes del vendedor
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes_vendedor');

$pdo = obtener_conexion();

// Resolver vendedor_id del usuario logueado
$mi_vendedor_id = null;
if (es_vendedor()) {
    $stmt = $pdo->prepare("SELECT id FROM ic_vendedores WHERE usuario_id=? AND activo=1 LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $mi_vendedor_id = $stmt->fetchColumn() ?: null;
    if (!$mi_vendedor_id) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Tu usuario no tiene un perfil de vendedor activo.'];
        header('Location: ' . BASE_URL . 'ventas/index');
        exit;
    }
} else {
    // Admin/supervisor pueden ver por ?vendedor_id=
    $mi_vendedor_id = (int)($_GET['vendedor_id'] ?? 0) ?: null;
}

// ── KPIs ─────────────────────────────────────────────────────
$kpis = ['total_clientes' => 0, 'creditos_activos' => 0, 'clientes_mora' => 0, 'por_cerrar' => 0];

if ($mi_vendedor_id) {
    $kpi_stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT cr.cliente_id)                                           AS total_clientes,
            SUM(cr.estado IN ('EN_CURSO','MOROSO'))                                 AS creditos_activos,
            SUM(cr.estado = 'MOROSO')                                               AS clientes_mora,
            SUM(
                (SELECT COUNT(*) FROM ic_cuotas cu
                 WHERE cu.credito_id = cr.id
                   AND cu.estado NOT IN ('PAGADA','CANCELADA')) BETWEEN 1 AND 3
            )                                                                       AS por_cerrar
        FROM ic_creditos cr
        WHERE cr.vendedor_id = :vid
          AND cr.estado IN ('EN_CURSO','MOROSO')
    ");
    $kpi_stmt->execute([':vid' => $mi_vendedor_id]);
    $kpis = $kpi_stmt->fetch() ?: $kpis;
}

// ── Lista de créditos activos ─────────────────────────────────
$lista = [];
if ($mi_vendedor_id) {
    $lista_stmt = $pdo->prepare("
        SELECT
            cl.id                                                                   AS cliente_id,
            CONCAT(cl.apellidos, ', ', cl.nombres)                                 AS cliente_nombre,
            cl.telefono,
            cr.id                                                                  AS credito_id,
            cr.estado                                                              AS credito_estado,
            cr.motivo_finalizacion,
            cr.cant_cuotas,
            cr.fecha_alta,
            COALESCE(cr.articulo_desc, a.descripcion, '—')                        AS articulo,
            SUM(cu.estado IN ('PAGADA','CAP_PAGADA'))                              AS cuotas_pagadas,
            SUM(cu.estado NOT IN ('PAGADA','CANCELADA'))                           AS cuotas_pendientes
        FROM ic_creditos cr
        JOIN ic_clientes cl  ON cr.cliente_id = cl.id
        JOIN ic_cuotas cu    ON cu.credito_id = cr.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.vendedor_id = :vid
          AND (
              cr.estado IN ('EN_CURSO','MOROSO')
              OR (cr.estado = 'FINALIZADO' AND cr.motivo_finalizacion IN ('PAGO_COMPLETO','PAGO_COMPLETO_CON_MORA','RETIRO_PRODUCTO'))
          )
        GROUP BY cr.id, cl.id
        ORDER BY CASE cr.estado WHEN 'MOROSO' THEN 0 WHEN 'EN_CURSO' THEN 1 ELSE 2 END,
                 cl.apellidos, cl.nombres
    ");
    $lista_stmt->execute([':vid' => $mi_vendedor_id]);
    $lista = $lista_stmt->fetchAll();
}

// ── Nombre del vendedor (para admin/supervisor) ───────────────
$nombre_vendedor = null;
if ($mi_vendedor_id && !es_vendedor()) {
    $vend = $pdo->prepare("SELECT nombre, apellido FROM ic_vendedores WHERE id=?");
    $vend->execute([$mi_vendedor_id]);
    $row_v = $vend->fetch();
    if ($row_v) {
        $nombre_vendedor = e($row_v['apellido'] . ', ' . $row_v['nombre']);
    }
}

$page_title   = 'Mis Clientes';
$page_current = 'mis_clientes';
require __DIR__ . '/../views/layout.php';
?>

<style>
@media (max-width: 600px) {
    .hide-mobile { display: none; }
    .table-ic td, .table-ic th { padding: 10px 8px; }
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="fa fa-users" style="color:var(--primary-light)"></i>
            <?= $nombre_vendedor ? 'Clientes de ' . $nombre_vendedor : 'Mis Clientes' ?>
        </h1>
        <div class="page-subtitle">Créditos activos e historial de tu cartera</div>
    </div>
</div>

<?php if (!$mi_vendedor_id): ?>
    <div class="card-ic" style="text-align:center;padding:60px 20px;color:var(--text-muted)">
        <i class="fa fa-user-slash" style="font-size:3rem;margin-bottom:16px;display:block;color:var(--dark-border)"></i>
        <div style="font-size:1rem;font-weight:600;color:var(--text-body);margin-bottom:6px">Sin vendedor seleccionado</div>
        <div style="font-size:.83rem">Seleccioná un vendedor para ver su cartera.</div>
    </div>
<?php else: ?>

<!-- KPI cards -->
<div class="db-kpi-grid" style="margin-bottom:24px">

    <div class="kpi-card" style="--kpi-color:var(--primary);--icon-bg:rgba(60,80,224,.12);--icon-color:var(--primary-light)">
        <div class="kpi-icon-box"><i class="fa fa-users"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Total Clientes</div>
            <div class="kpi-value"><?= (int)$kpis['total_clientes'] ?></div>
            <div class="kpi-sub">con créditos activos</div>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--success);--icon-bg:rgba(33,150,83,.12);--icon-color:#34D399">
        <div class="kpi-icon-box"><i class="fa fa-file-invoice-dollar"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Créditos Activos</div>
            <div class="kpi-value"><?= (int)$kpis['creditos_activos'] ?></div>
            <div class="kpi-sub">en curso o morosos</div>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--danger);--icon-bg:rgba(211,64,83,.12);--icon-color:var(--danger-light)">
        <div class="kpi-icon-box"><i class="fa fa-triangle-exclamation"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">En Mora</div>
            <div class="kpi-value"><?= (int)$kpis['clientes_mora'] ?></div>
            <div class="kpi-sub">requieren atención</div>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--warning);--icon-bg:rgba(255,167,11,.12);--icon-color:var(--warning)">
        <div class="kpi-icon-box"><i class="fa fa-hourglass-end"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Por Cerrar</div>
            <div class="kpi-value"><?= (int)$kpis['por_cerrar'] ?></div>
            <div class="kpi-sub">≤ 3 cuotas pendientes</div>
        </div>
    </div>

</div>

<!-- Tabla de clientes -->
<div class="card-ic">
    <div class="card-ic-header" style="flex-wrap:wrap;gap:10px">
        <span class="card-title"><i class="fa fa-list"></i> Mi Cartera</span>
        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:200px">
            <div style="position:relative;flex:1;max-width:280px">
                <i class="fa fa-search" style="position:absolute;left:10px;top:50%;
                   transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none"></i>
                <input type="text" id="buscador-clientes"
                       placeholder="Buscar cliente o artículo..."
                       style="width:100%;background:var(--dark-input);border:1px solid var(--dark-border);
                              border-radius:var(--radius-sm);padding:8px 12px 8px 30px;
                              color:var(--text-main);font-size:.83rem;min-height:44px">
            </div>
            <span id="contador-resultados" style="font-size:.78rem;color:var(--text-muted);white-space:nowrap"></span>
        </div>
    </div>

    <?php if (empty($lista)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
            <i class="fa fa-inbox" style="font-size:3rem;margin-bottom:16px;display:block;color:var(--dark-border)"></i>
            <div style="font-size:1rem;font-weight:600;color:var(--text-body);margin-bottom:6px">Sin clientes activos</div>
            <div style="font-size:.83rem">Aún no tenés créditos asignados como vendedor.</div>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table-ic" id="tabla-clientes">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th class="hide-mobile">Artículo</th>
                    <th>Estado</th>
                    <th>Progreso</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lista as $row): ?>
                <?php
                    $finalizado = $row['credito_estado'] === 'FINALIZADO';
                    $motivo     = $row['motivo_finalizacion'] ?? '';
                    $es_pagado  = in_array($motivo, ['PAGO_COMPLETO','PAGO_COMPLETO_CON_MORA']);
                    $es_retiro  = $motivo === 'RETIRO_PRODUCTO';

                    $pct = $row['cant_cuotas'] > 0
                        ? round($row['cuotas_pagadas'] / $row['cant_cuotas'] * 100)
                        : 0;
                    if ($es_pagado) {
                        $pct       = 100;
                        $bar_color = 'var(--success)';
                    } elseif ($es_retiro) {
                        $bar_color = 'var(--text-muted)';
                    } elseif ($row['credito_estado'] === 'MOROSO') {
                        $bar_color = 'var(--danger)';
                    } elseif ($pct >= 75) {
                        $bar_color = 'var(--success)';
                    } elseif ($pct >= 40) {
                        $bar_color = 'var(--primary-light)';
                    } else {
                        $bar_color = 'var(--text-muted)';
                    }

                    $motivo_badge = match($motivo) {
                        'PAGO_COMPLETO'          => '<span style="font-size:.65rem;background:rgba(33,150,83,.18);color:#34D399;border-radius:4px;padding:1px 6px;margin-left:4px">Pagado</span>',
                        'PAGO_COMPLETO_CON_MORA' => '<span style="font-size:.65rem;background:rgba(33,150,83,.18);color:#34D399;border-radius:4px;padding:1px 6px;margin-left:4px">Pagado c/mora</span>',
                        'RETIRO_PRODUCTO'        => '<span style="font-size:.65rem;background:rgba(255,167,11,.15);color:var(--warning);border-radius:4px;padding:1px 6px;margin-left:4px">Retiro art.</span>',
                        default                  => '',
                    };

                    $cuotas_rest = !$finalizado ? (int)$row['cuotas_pendientes'] : 0;
                    $por_cerrar  = $cuotas_rest >= 1 && $cuotas_rest <= 3;
                    [$pc_color, $pc_borde, $pc_label] = match(true) {
                        $cuotas_rest === 1 => ['var(--danger)',  'rgba(239,68,68,.9)',   '¡Última cuota!'],
                        $cuotas_rest === 2 => ['var(--warning)', 'rgba(255,167,11,.9)',  '2 cuotas restantes'],
                        $cuotas_rest === 3 => ['#63B3ED',        'rgba(99,179,237,.9)',  '3 cuotas restantes'],
                        default            => ['', '', ''],
                    };

                    if ($finalizado)   $tr_style = 'opacity:.72';
                    elseif ($por_cerrar) $tr_style = "border-left:3px solid {$pc_borde}";
                    else               $tr_style = '';
                ?>
                <tr data-searchable="<?= e(strtolower($row['cliente_nombre'] . ' ' . $row['articulo'])) ?>"
                    <?= $tr_style ? 'style="' . $tr_style . '"' : '' ?>>
                    <td>
                        <div style="font-weight:600"><?= e($row['cliente_nombre']) ?></div>
                        <?php if ($row['telefono']): ?>
                            <a href="tel:<?= e($row['telefono']) ?>"
                               style="font-size:.75rem;color:var(--text-muted);text-decoration:none">
                                <?= e($row['telefono']) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="hide-mobile" style="color:var(--text-body);max-width:200px">
                        <?= e($row['articulo']) ?>
                    </td>
                    <td><?= badge_estado_credito($row['credito_estado']) ?><?= $motivo_badge ?></td>
                    <td style="min-width:140px">
                        <div style="background:rgba(255,255,255,.08);border-radius:99px;height:7px;overflow:hidden;margin-bottom:3px">
                            <div style="width:<?= $pct ?>%;height:100%;border-radius:99px;background:<?= $bar_color ?>;transition:width .4s ease"></div>
                        </div>
                        <div style="font-size:.7rem;color:var(--text-muted)">
                            <?= (int)$row['cuotas_pagadas'] ?> / <?= (int)$row['cant_cuotas'] ?>
                            <span style="float:right;color:<?= $bar_color ?>;font-weight:700"><?= $pct ?>%</span>
                        </div>
                        <?php if ($por_cerrar): ?>
                        <div style="margin-top:4px;font-size:.68rem;font-weight:700;color:<?= $pc_color ?>">
                            <i class="fa fa-flag-checkered" style="margin-right:3px;font-size:.6rem"></i><?= $pc_label ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        <a href="<?= BASE_URL ?>ventas/ver_credito?id=<?= (int)$row['credito_id'] ?>"
                           class="btn-ic btn-ghost btn-sm">
                            <i class="fa fa-eye"></i> Ver
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script>
(function() {
    const input = document.getElementById('buscador-clientes');
    const contador = document.getElementById('contador-resultados');
    if (!input) return;
    input.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        const filas = document.querySelectorAll('#tabla-clientes tbody tr[data-searchable]');
        let visibles = 0;
        filas.forEach(function(tr) {
            const match = !q || tr.dataset.searchable.includes(q);
            tr.style.display = match ? '' : 'none';
            if (match) visibles++;
        });
        contador.textContent = q ? visibles + ' resultado' + (visibles !== 1 ? 's' : '') : '';
    });
})();
</script>

<?php require __DIR__ . '/../views/layout_footer.php'; ?>
