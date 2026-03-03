<?php
// ============================================================
// clientes/ver.php — Ficha completa del cliente
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) {
    header('Location: index.php');
    exit;
}

$garante = $pdo->prepare("SELECT * FROM ic_garantes WHERE cliente_id=? LIMIT 1");
$garante->execute([$id]);
$g = $garante->fetch();

$creditos = $pdo->prepare("
    SELECT cr.*, COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id) AS total_cuotas
    FROM ic_creditos cr
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cr.cliente_id = ?
    ORDER BY cr.fecha_alta DESC
");
$creditos->execute([$id]);
$listado_creditos = $creditos->fetchAll();

$page_title = 'Ficha Cliente — ' . $c['apellidos'] . ', ' . $c['nombres'];
$page_current = 'clientes';
$topbar_actions = '<a href="editar.php?id=' . $id . '" class="btn-ic btn-primary btn-sm"><i class="fa fa-pencil"></i> Editar</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start">

    <!-- COLUMNA IZQUIERDA -->
    <div>
        <div class="card-ic mb-4">
            <div style="text-align:center;padding:8px 0 16px">
                <div
                    style="width:70px;height:70px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 12px">
                    👤
                </div>
                <div style="font-size:1.2rem;font-weight:800">
                    <?= e($c['apellidos']) ?>,
                    <?= e($c['nombres']) ?>
                </div>
                <div class="text-muted" style="font-size:.82rem">#
                    <?= $c['id'] ?>
                </div>
                <div class="mt-2">
                    <?= badge_estado_cliente($c['estado']) ?>
                </div>
            </div>
            <hr class="divider">
            <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                <tr>
                    <td class="text-muted" style="padding:5px 0;width:40%">DNI</td>
                    <td>
                        <?= e($c['dni'] ?: '—') ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">CUIL</td>
                    <td>
                        <?= e($c['cuil'] ?: '—') ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Nacimiento</td>
                    <td>
                        <?= $c['fecha_nacimiento'] ? date('d/m/Y', strtotime($c['fecha_nacimiento'])) : '—' ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Teléfono</td>
                    <td>
                        <?= e($c['telefono']) ?>
                        <a href="<?= whatsapp_url($c['telefono']) ?>" target="_blank"
                            class="btn-ic btn-success btn-icon btn-sm" style="display:inline-flex;margin-left:4px">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                    </td>
                </tr>
                <?php if ($c['telefono_alt']): ?>
                    <tr>
                        <td class="text-muted" style="padding:5px 0">Tel. Alt.</td>
                        <td>
                            <?= e($c['telefono_alt']) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Dirección</td>
                    <td>
                        <?= e($c['direccion'] ?: '—') ?>
                    </td>
                </tr>
                <?php if ($c['direccion_laboral']): ?>
                    <tr>
                        <td class="text-muted" style="padding:5px 0">Dir. Laboral</td>
                        <td>
                            <?= e($c['direccion_laboral']) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($c['coordenadas']): ?>
                    <tr>
                        <td class="text-muted" style="padding:5px 0">GPS</td>
                        <td>
                            <a href="<?= maps_url($c['coordenadas']) ?>" target="_blank" class="btn-ic btn-accent btn-sm">
                                <i class="fa fa-map-marker-alt"></i> Ver en Maps
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Zona</td>
                    <td>
                        <?= e($c['zona'] ?: '—') ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Cobrador</td>
                    <td>
                        <?= $c['cobrador_nombre'] ? e($c['cobrador_nombre'] . ' ' . $c['cobrador_apellido']) : '—' ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Día cobro</td>
                    <td>
                        <?= $c['dia_cobro'] ? nombre_dia($c['dia_cobro']) : '—' ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted" style="padding:5px 0">Alta</td>
                    <td>
                        <?= date('d/m/Y', strtotime($c['created_at'])) ?>
                    </td>
                </tr>
            </table>

            <?php if ($c['token_acceso']): ?>
                <hr class="divider">
                <div style="font-size:.75rem">
                    <div class="text-muted mb-2">🔗 Portal del cliente:</div>
                    <div
                        style="background:rgba(0,0,0,.3);border-radius:8px;padding:8px;word-break:break-all;font-size:.7rem">
                        <?= BASE_URL ?>clientes/portal.php?token=
                        <?= e($c['token_acceso']) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- GARANTE -->
        <?php if ($g): ?>
            <div class="card-ic">
                <div class="card-title mb-3"><i class="fa fa-user-shield"></i> Garante</div>
                <table style="width:100%;font-size:.875rem;border-collapse:collapse">
                    <tr>
                        <td class="text-muted" style="padding:4px 0;width:40%">Nombre</td>
                        <td class="fw-bold">
                            <?= e($g['apellidos'] . ', ' . $g['nombres']) ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding:4px 0">DNI</td>
                        <td>
                            <?= e($g['dni'] ?: '—') ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding:4px 0">Teléfono</td>
                        <td>
                            <?= e($g['telefono'] ?: '—') ?>
                            <?php if ($g['telefono']): ?>
                                <a href="<?= whatsapp_url($g['telefono']) ?>" target="_blank"
                                    class="btn-ic btn-success btn-icon btn-sm" style="display:inline-flex;margin-left:4px">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted" style="padding:4px 0">Dirección</td>
                        <td>
                            <?= e($g['direccion'] ?: '—') ?>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- COLUMNA DERECHA: Créditos -->
    <div>
        <div class="card-ic">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Créditos</span>
                <?php if (!es_cobrador()): ?>
                    <a href="../creditos/nuevo.php?cliente_id=<?= $id ?>" class="btn-ic btn-primary btn-sm">
                        <i class="fa fa-plus"></i> Nuevo Crédito
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($listado_creditos)): ?>
                <p class="text-muted text-center" style="padding:30px">Sin créditos registrados.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="table-ic">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Artículo</th>
                                <th>Monto Total</th>
                                <th>Cuota</th>
                                <th>Frecuencia</th>
                                <th>Avance</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($listado_creditos as $cr): ?>
                                <tr>
                                    <td class="text-muted">#
                                        <?= $cr['id'] ?>
                                    </td>
                                    <td>
                                        <?= e($cr['articulo']) ?>
                                    </td>
                                    <td class="nowrap fw-bold">
                                        <?= formato_pesos($cr['monto_total']) ?>
                                    </td>
                                    <td class="nowrap">
                                        <?= formato_pesos($cr['monto_cuota']) ?>
                                    </td>
                                    <td>
                                        <?= ucfirst($cr['frecuencia']) ?>
                                    </td>
                                    <td class="nowrap">
                                        <?= $cr['cuotas_pagadas'] ?>/
                                        <?= $cr['total_cuotas'] ?>
                                        <div
                                            style="width:80px;height:4px;background:var(--dark-border);border-radius:4px;margin-top:4px;display:inline-block;vertical-align:middle">
                                            <div
                                                style="width:<?= $cr['total_cuotas'] > 0 ? round($cr['cuotas_pagadas'] / $cr['total_cuotas'] * 100) : 0 ?>%;height:100%;background:var(--success);border-radius:4px">
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?= badge_estado_credito($cr['estado']) ?>
                                    </td>
                                    <td>
                                        <a href="../creditos/ver.php?id=<?= $cr['id'] ?>"
                                            class="btn-ic btn-ghost btn-sm btn-icon" title="Ver cronograma">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /grid -->

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>