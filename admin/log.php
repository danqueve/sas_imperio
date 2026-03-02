<?php
// ============================================================
// admin/log.php — Historial de actividades de usuarios
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_reportes');

$pdo = obtener_conexion();

// ── Filtros ──────────────────────────────────────────────────
$f_usuario = (int) ($_GET['usuario_id'] ?? 0);
$f_accion  = trim($_GET['accion'] ?? '');
$f_desde   = $_GET['desde'] ?? '';
$f_hasta   = $_GET['hasta'] ?? '';
$pagina    = max(1, (int) ($_GET['pag'] ?? 1));
$por_pagina = 20;

// Construir WHERE dinámico
$where  = [];
$params = [];

if ($f_usuario) {
    $where[]  = 'l.usuario_id = ?';
    $params[] = $f_usuario;
}
if ($f_accion !== '') {
    $where[]  = 'l.accion = ?';
    $params[] = $f_accion;
}
if ($f_desde !== '') {
    $where[]  = 'DATE(l.fecha) >= ?';
    $params[] = $f_desde;
}
if ($f_hasta !== '') {
    $where[]  = 'DATE(l.fecha) <= ?';
    $params[] = $f_hasta;
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total registros
$total = (int) $pdo->prepare("SELECT COUNT(*) FROM ic_log_actividades l $sql_where")
    ->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM ic_log_actividades l $sql_where") : 0;
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM ic_log_actividades l $sql_where");
$cnt_stmt->execute($params);
$total = (int) $cnt_stmt->fetchColumn();

$total_paginas = max(1, (int) ceil($total / $por_pagina));
$pagina = min($pagina, $total_paginas);
$offset = ($pagina - 1) * $por_pagina;

// Registros de la página
$stmt = $pdo->prepare("
    SELECT l.*, u.nombre, u.apellido
    FROM ic_log_actividades l
    JOIN ic_usuarios u ON l.usuario_id = u.id
    $sql_where
    ORDER BY l.fecha DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Listas para filtros
$usuarios = $pdo->query("SELECT id, nombre, apellido FROM ic_usuarios ORDER BY apellido, nombre")->fetchAll();
$acciones = $pdo->query("SELECT DISTINCT accion FROM ic_log_actividades ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);

// ── Badge color por acción ────────────────────────────────────
function badge_accion(string $accion): string {
    if (str_ends_with($accion, '_CREADO') || str_ends_with($accion, '_REGISTRADO') || str_ends_with($accion, '_APROBADA')) {
        $clase = 'badge-success';
    } elseif (str_ends_with($accion, '_EDITADO')) {
        $clase = 'badge-primary';
    } elseif (str_ends_with($accion, '_ELIMINADO') || str_ends_with($accion, '_RECHAZADO')) {
        $clase = 'badge-danger';
    } else {
        $clase = 'badge-muted';
    }
    return '<span class="badge-ic ' . $clase . '">' . e($accion) . '</span>';
}

// ── URL helper para paginación manteniendo filtros ────────────
function page_url(int $p): string {
    $q = $_GET;
    $q['pag'] = $p;
    return '?' . http_build_query($q);
}

$page_title   = 'Actividad';
$page_current = 'log';
require_once __DIR__ . '/../views/layout.php';
?>

<!-- Filtros -->
<div class="card-ic mb-4">
    <form method="GET" class="filter-bar" style="flex-wrap:wrap;gap:12px">
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Usuario</label>
            <select name="usuario_id" style="min-width:160px">
                <option value="0">— Todos —</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $f_usuario === $u['id'] ? 'selected' : '' ?>>
                        <?= e($u['apellido'] . ', ' . $u['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Acción</label>
            <select name="accion" style="min-width:180px">
                <option value="">— Todas —</option>
                <?php foreach ($acciones as $ac): ?>
                    <option value="<?= e($ac) ?>" <?= $f_accion === $ac ? 'selected' : '' ?>>
                        <?= e($ac) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Desde</label>
            <input type="date" name="desde" value="<?= e($f_desde) ?>" style="min-width:140px">
        </div>
        <div>
            <label style="font-size:.78rem;color:var(--text-muted);display:block;margin-bottom:4px">Hasta</label>
            <input type="date" name="hasta" value="<?= e($f_hasta) ?>" style="min-width:140px">
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px">
            <button type="submit" class="btn-ic btn-ghost"><i class="fa fa-filter"></i> Filtrar</button>
            <a href="log.php" class="btn-ic btn-ghost"><i class="fa fa-times"></i></a>
        </div>
    </form>
</div>

<!-- Tabla -->
<div class="card-ic">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-history"></i> Actividad del Sistema</span>
        <span class="text-muted" style="font-size:.82rem"><?= number_format($total) ?> registro<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($logs)): ?>
        <p class="text-muted text-center" style="padding:30px">Sin registros para los filtros seleccionados.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Entidad</th>
                        <th class="text-right">ID</th>
                        <th>Detalle</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td class="nowrap text-muted" style="font-size:.8rem">
                                <?= date('d/m/Y H:i', strtotime($row['fecha'])) ?>
                            </td>
                            <td class="fw-bold">
                                <?= e($row['apellido'] . ', ' . $row['nombre']) ?>
                            </td>
                            <td class="nowrap">
                                <?= badge_accion($row['accion']) ?>
                            </td>
                            <td class="text-muted">
                                <?= e($row['entidad']) ?>
                            </td>
                            <td class="text-right text-muted" style="font-size:.82rem">
                                <?= $row['entidad_id'] ?? '—' ?>
                            </td>
                            <td style="font-size:.82rem;max-width:260px;white-space:normal">
                                <?= e($row['detalle'] ?? '') ?>
                            </td>
                            <td class="text-muted" style="font-size:.78rem">
                                <?= e($row['ip'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_paginas > 1): ?>
            <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap">
                <?php if ($pagina > 1): ?>
                    <a href="<?= page_url($pagina - 1) ?>" class="btn-ic btn-ghost btn-sm">‹ Anterior</a>
                <?php endif; ?>
                <?php
                $inicio = max(1, $pagina - 2);
                $fin    = min($total_paginas, $pagina + 2);
                for ($p = $inicio; $p <= $fin; $p++): ?>
                    <a href="<?= page_url($p) ?>"
                       class="btn-ic btn-sm <?= $p === $pagina ? 'btn-primary' : 'btn-ghost' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
                <?php if ($pagina < $total_paginas): ?>
                    <a href="<?= page_url($pagina + 1) ?>" class="btn-ic btn-ghost btn-sm">Siguiente ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
