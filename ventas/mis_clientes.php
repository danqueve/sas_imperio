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
            cr.cant_cuotas,
            cr.fecha_alta,
            COALESCE(cr.articulo_desc, a.descripcion, '—')                        AS articulo,
            SUM(cu.estado IN ('PAGADA','CAP_PAGADA'))                              AS cuotas_pagadas,
            SUM(cu.estado NOT IN ('PAGADA','CANCELADA'))                           AS cuotas_pendientes
        FROM ic_creditos cr
        JOIN ic_clientes cl  ON cr.cliente_id = cl.id
        JOIN ic_cuotas cu    ON cu.credito_id = cr.id
        LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
        WHERE cr.vendedor_id = :vid AND cr.estado IN ('EN_CURSO','MOROSO')
        GROUP BY cr.id, cl.id
        ORDER BY cl.apellidos, cl.nombres
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

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="fa fa-users" style="color:var(--primary-light)"></i>
            <?= $nombre_vendedor ? 'Clientes de ' . $nombre_vendedor : 'Mis Clientes' ?>
        </h1>
        <div class="page-subtitle">Créditos activos de tu cartera</div>
    </div>
</div>

<?php if (!$mi_vendedor_id): ?>
    <div class="card-ic" style="text-align:center;padding:48px 20px;color:var(--text-muted)">
        <i class="fa fa-user-slash" style="font-size:2.5rem;margin-bottom:12px;display:block"></i>
        Seleccioná un vendedor para ver su cartera.
    </div>
<?php else: ?>

<!-- KPI cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">

    <div class="card-ic" style="padding:18px 20px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
            <div style="width:36px;height:36px;border-radius:8px;background:rgba(139,153,245,.15);
                        display:flex;align-items:center;justify-content:center">
                <i class="fa fa-users" style="color:var(--primary-light);font-size:.9rem"></i>
            </div>
            <span style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Total Clientes</span>
        </div>
        <div style="font-size:1.8rem;font-weight:800;line-height:1"><?= (int)$kpis['total_clientes'] ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">con créditos activos</div>
    </div>

    <div class="card-ic" style="padding:18px 20px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
            <div style="width:36px;height:36px;border-radius:8px;background:rgba(33,150,83,.15);
                        display:flex;align-items:center;justify-content:center">
                <i class="fa fa-file-invoice-dollar" style="color:var(--success);font-size:.9rem"></i>
            </div>
            <span style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Créditos Activos</span>
        </div>
        <div style="font-size:1.8rem;font-weight:800;line-height:1"><?= (int)$kpis['creditos_activos'] ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">en curso o morosos</div>
    </div>

    <div class="card-ic" style="padding:18px 20px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
            <div style="width:36px;height:36px;border-radius:8px;background:rgba(211,64,83,.15);
                        display:flex;align-items:center;justify-content:center">
                <i class="fa fa-triangle-exclamation" style="color:var(--danger);font-size:.9rem"></i>
            </div>
            <span style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">En Mora</span>
        </div>
        <div style="font-size:1.8rem;font-weight:800;line-height:1"><?= (int)$kpis['clientes_mora'] ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">créditos morosos</div>
    </div>

    <div class="card-ic" style="padding:18px 20px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
            <div style="width:36px;height:36px;border-radius:8px;background:rgba(255,167,11,.15);
                        display:flex;align-items:center;justify-content:center">
                <i class="fa fa-hourglass-end" style="color:var(--warning);font-size:.9rem"></i>
            </div>
            <span style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Por Cerrar</span>
        </div>
        <div style="font-size:1.8rem;font-weight:800;line-height:1"><?= (int)$kpis['por_cerrar'] ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">≤ 3 cuotas restantes</div>
    </div>

</div>

<!-- Tabla de clientes -->
<div class="card-ic">
    <div class="card-header-ic">
        <h2 class="card-title-ic"><i class="fa fa-list"></i> Cartera Activa</h2>
    </div>

    <?php if (empty($lista)): ?>
        <div style="text-align:center;padding:48px 20px;color:var(--text-muted)">
            <i class="fa fa-inbox" style="font-size:2.5rem;margin-bottom:12px;display:block"></i>
            Aún no tenés clientes con créditos activos.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Artículo</th>
                    <th>Estado</th>
                    <th>Progreso</th>
                    <th style="text-align:right">Cuotas</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lista as $row): ?>
                <?php
                    $pct = $row['cant_cuotas'] > 0
                        ? round($row['cuotas_pagadas'] / $row['cant_cuotas'] * 100)
                        : 0;
                    $bar_color = $row['credito_estado'] === 'MOROSO' ? 'var(--danger)' : 'var(--success)';
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600"><?= e($row['cliente_nombre']) ?></div>
                        <?php if ($row['telefono']): ?>
                            <div style="font-size:.75rem;color:var(--text-muted)"><?= e($row['telefono']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--text-body);max-width:200px">
                        <?= e($row['articulo']) ?>
                    </td>
                    <td><?= badge_estado_credito($row['credito_estado']) ?></td>
                    <td style="min-width:140px">
                        <div class="progress" style="height:6px;background:var(--dark-border)">
                            <div class="progress-bar" role="progressbar"
                                 style="width:<?= $pct ?>%;background:<?= $bar_color ?>"
                                 aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                    </td>
                    <td style="text-align:right;white-space:nowrap;font-size:.82rem;color:var(--text-muted)">
                        <?= (int)$row['cuotas_pagadas'] ?> / <?= (int)$row['cant_cuotas'] ?>
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

<?php require __DIR__ . '/../views/layout_footer.php'; ?>
