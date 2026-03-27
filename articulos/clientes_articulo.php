<?php
// ============================================================
// articulos/clientes_articulo.php — Clientes con crédito de un artículo (AJAX)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('alta_creditos');

$id = (int)($_GET['id'] ?? 0);
if (!$id || empty($_GET['ajax'])) {
    header('Location: index');
    exit;
}

$pdo = obtener_conexion();

$stmt = $pdo->prepare("
    SELECT cr.id AS credito_id,
           cr.fecha_alta,
           cr.monto_total,
           cr.estado,
           cl.id  AS cliente_id,
           cl.nombres,
           cl.apellidos,
           cl.dni,
           u.nombre   AS cobrador_nombre,
           u.apellido AS cobrador_apellido
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id  = cl.id
    LEFT JOIN ic_usuarios u ON cr.cobrador_id = u.id
    WHERE cr.articulo_id = ?
    ORDER BY cr.fecha_alta DESC
");
$stmt->execute([$id]);
$creditos = $stmt->fetchAll();

if (empty($creditos)):
?>
<div style="padding:32px;text-align:center;color:var(--text-muted,#888)">
    <i class="fa fa-inbox" style="font-size:2rem;margin-bottom:8px;display:block"></i>
    Sin créditos registrados para este artículo.
</div>
<?php else: ?>
<div style="overflow-x:auto">
    <table class="table-ic" style="margin:0">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>DNI</th>
                <th>Cobrador</th>
                <th class="text-right">Monto Total</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($creditos as $cr): ?>
                <tr>
                    <td class="nowrap text-muted" style="font-size:.85rem">
                        <?= date('d/m/Y', strtotime($cr['fecha_alta'])) ?>
                    </td>
                    <td class="fw-bold">
                        <?= e($cr['apellidos'] . ', ' . $cr['nombres']) ?>
                    </td>
                    <td class="text-muted">
                        <?= e($cr['dni'] ?: '—') ?>
                    </td>
                    <td class="text-muted" style="font-size:.85rem">
                        <?= $cr['cobrador_nombre'] ? e($cr['cobrador_nombre'] . ' ' . $cr['cobrador_apellido']) : '—' ?>
                    </td>
                    <td class="text-right nowrap fw-bold">
                        <?= formato_pesos((float)$cr['monto_total']) ?>
                    </td>
                    <td>
                        <?= badge_estado_credito($cr['estado']) ?>
                    </td>
                    <td class="nowrap">
                        <a href="../creditos/ver?id=<?= (int)$cr['credito_id'] ?>"
                           target="_blank"
                           class="btn-ic btn-ghost btn-sm btn-icon"
                           title="Ver crédito">
                            <i class="fa fa-eye"></i>
                        </a>
                        <a href="../clientes/ver?id=<?= (int)$cr['cliente_id'] ?>"
                           target="_blank"
                           class="btn-ic btn-ghost btn-sm btn-icon"
                           title="Ver cliente">
                            <i class="fa fa-user"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div style="padding:10px 16px;font-size:.8rem;color:var(--text-muted,#888);border-top:1px solid var(--border,#333)">
    <?= count($creditos) ?> crédito<?= count($creditos) !== 1 ? 's' : '' ?> registrado<?= count($creditos) !== 1 ? 's' : '' ?>
</div>
<?php endif; ?>
