<?php
// ============================================================
// admin/pendientes_condonar.php — Cuotas CAP_PAGADA / PARCIAL
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('aprobar_rendiciones');

$pdo         = obtener_conexion();
$cobrador_id = (int) ($_GET['cobrador_id'] ?? 0);

if (!$cobrador_id) {
    header('Location: rendiciones');
    exit;
}

// Nombre del cobrador
$sc = $pdo->prepare("SELECT nombre, apellido FROM ic_usuarios WHERE id = ?");
$sc->execute([$cobrador_id]);
$cob = $sc->fetch();
if (!$cob) {
    header('Location: rendiciones');
    exit;
}
$nombre_cobrador = $cob['nombre'] . ' ' . $cob['apellido'];

// Cuotas pendientes
$stmt = $pdo->prepare("
    SELECT
        cl.apellidos, cl.nombres,
        COALESCE(cr.articulo_desc, a.descripcion, '—') AS articulo,
        cu.id AS cuota_id, cu.numero_cuota, cr.cant_cuotas,
        cu.monto_cuota, cu.monto_mora, cu.saldo_pagado, cu.estado,
        cr.id AS credito_id
    FROM ic_cuotas cu
    JOIN ic_creditos cr ON cu.credito_id = cr.id
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cu.estado IN ('CAP_PAGADA','PARCIAL')
      AND cr.cobrador_id = ?
      AND cr.estado IN ('EN_CURSO','MOROSO')
    ORDER BY cu.estado DESC, cl.apellidos
");
$stmt->execute([$cobrador_id]);
$cuotas = $stmt->fetchAll();

$cant_cap   = count(array_filter($cuotas, fn($r) => $r['estado'] === 'CAP_PAGADA'));
$cant_parc  = count(array_filter($cuotas, fn($r) => $r['estado'] === 'PARCIAL'));

$page_title     = 'Pendientes — ' . $nombre_cobrador;
$page_current   = 'rendiciones';
$topbar_actions = '<a href="rendiciones?cobrador_id=' . $cobrador_id . '" class="btn-ic btn-ghost btn-sm">'
    . '<i class="fa fa-arrow-left"></i> Volver a Rendiciones</a>';

require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="card-ic" style="margin-bottom:16px">
    <div class="card-ic-header">
        <div>
            <span class="card-title">
                <i class="fa fa-triangle-exclamation" style="color:#f59e0b"></i>
                Cuotas pendientes de revisión
            </span>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                Cobrador: <strong><?= e($nombre_cobrador) ?></strong>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($cant_cap > 0): ?>
                <span style="background:#dbeafe;color:#1e40af;font-size:.78rem;padding:4px 12px;border-radius:12px;font-weight:600">
                    <i class="fa fa-circle-check"></i> Capital Pagado: <?= $cant_cap ?>
                </span>
            <?php endif; ?>
            <?php if ($cant_parc > 0): ?>
                <span style="background:#ffedd5;color:#9a3412;font-size:.78rem;padding:4px 12px;border-radius:12px;font-weight:600">
                    <i class="fa fa-circle-half-stroke"></i> Parcial: <?= $cant_parc ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (empty($cuotas)): ?>
    <div class="card-ic">
        <p class="text-muted text-center" style="padding:40px">
            <i class="fa fa-circle-check" style="font-size:2rem;color:var(--success);display:block;margin-bottom:12px"></i>
            No hay cuotas CAP_PAGADA ni PARCIAL para este cobrador.
        </p>
    </div>
<?php else: ?>
<div class="card-ic" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Artículo</th>
                    <th>Cuota</th>
                    <th style="text-align:right">Monto</th>
                    <th style="text-align:right">Abonado</th>
                    <th style="text-align:right">Mora</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cuotas as $r): ?>
                <tr>
                    <td class="fw-bold"><?= e($r['apellidos'] . ', ' . $r['nombres']) ?></td>
                    <td><?= e($r['articulo']) ?></td>
                    <td>#<?= (int) $r['numero_cuota'] ?>/<?= (int) $r['cant_cuotas'] ?></td>
                    <td style="text-align:right"><?= formato_pesos($r['monto_cuota']) ?></td>
                    <td style="text-align:right;color:var(--success);font-weight:700"><?= formato_pesos($r['saldo_pagado']) ?></td>
                    <td style="text-align:right;<?= $r['monto_mora'] > 0 ? 'color:var(--danger);font-weight:600' : '' ?>">
                        <?= $r['monto_mora'] > 0 ? formato_pesos($r['monto_mora']) : '—' ?>
                    </td>
                    <td>
                        <?php if ($r['estado'] === 'CAP_PAGADA'): ?>
                            <span style="background:#dbeafe;color:#1e40af;font-size:.72rem;padding:3px 10px;border-radius:12px;font-weight:700;white-space:nowrap">Capital Pagado</span>
                        <?php else: ?>
                            <span style="background:#ffedd5;color:#9a3412;font-size:.72rem;padding:3px 10px;border-radius:12px;font-weight:700;white-space:nowrap">Parcial</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($r['estado'] === 'CAP_PAGADA'): ?>
                            <form method="POST" action="../creditos/condonar_mora" style="display:inline">
                                <input type="hidden" name="cuota_id"   value="<?= (int) $r['cuota_id'] ?>">
                                <input type="hidden" name="credito_id" value="<?= (int) $r['credito_id'] ?>">
                                <input type="hidden" name="redirect"   value="../admin/pendientes_condonar?cobrador_id=<?= $cobrador_id ?>">
                                <button type="submit" class="btn-ic btn-sm"
                                    style="background:#4f46e5;color:#fff;border:none"
                                    data-confirm="¿Condonar la mora de <?= e(addslashes($r['apellidos'] . ', ' . $r['nombres'])) ?>?">
                                    <i class="fa fa-circle-check"></i> Condonar Mora
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="../creditos/ver?id=<?= (int) $r['credito_id'] ?>" target="_blank"
                               class="btn-ic btn-ghost btn-sm">
                                <i class="fa fa-eye"></i> Ver Crédito
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
