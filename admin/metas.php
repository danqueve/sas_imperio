<?php
// ============================================================
// admin/metas.php — Configuración de metas semanales por cobrador
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_usuarios');

$pdo = obtener_conexion();

// ── Guardar metas ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_metas') {
    $metas = $_POST['meta'] ?? [];
    $stmt  = $pdo->prepare("UPDATE ic_usuarios SET meta_semanal = ? WHERE id = ? AND rol = 'cobrador'");
    $count = 0;
    foreach ($metas as $uid => $val) {
        $monto = max(0, (float) str_replace(['.', ','], ['', '.'], $val));
        $stmt->execute([$monto, (int) $uid]);
        $count++;
    }
    registrar_log($pdo, $_SESSION['user_id'], 'METAS_ACTUALIZADAS', 'usuario', 0,
        "Metas actualizadas para $count cobrador(es)");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Metas actualizadas para $count cobrador(es)."];
    header('Location: metas');
    exit;
}

// ── Semana actual (Lun-Sáb) ────────────────────────────────
$dow       = (int) date('N');
$lunes     = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
$sabado    = date('Y-m-d', strtotime($lunes . ' +5 days'));

// ── Cobradores activos con su meta ─────────────────────────
$cobradores = $pdo->query("
    SELECT id, nombre, apellido, meta_semanal
    FROM ic_usuarios
    WHERE rol = 'cobrador' AND activo = 1
    ORDER BY apellido, nombre
")->fetchAll();

// ── Cobrado esta semana por cobrador (solo origen='cobrador') ─
$stmt_cobrado = $pdo->prepare("
    SELECT cobrador_id, SUM(monto_total) AS total
    FROM ic_pagos_temporales
    WHERE fecha_jornada BETWEEN ? AND ?
      AND estado IN ('PENDIENTE', 'APROBADO')
      AND origen = 'cobrador'
    GROUP BY cobrador_id
");
$stmt_cobrado->execute([$lunes, $sabado]);
$cobrado_map = [];
foreach ($stmt_cobrado->fetchAll() as $r) {
    $cobrado_map[(int) $r['cobrador_id']] = (float) $r['total'];
}

// ── Layout ─────────────────────────────────────────────────
$page_title   = 'Metas Semanales';
$page_current = 'metas';
$topbar_actions = '';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="card-ic mb-4" style="padding:16px">
    <div style="font-size:.85rem;color:var(--text-muted)">
        <i class="fa fa-info-circle"></i>
        Semana actual: <strong><?= date('d/m', strtotime($lunes)) ?> — <?= date('d/m', strtotime($sabado)) ?></strong>
        · Los montos cobrados solo incluyen pagos registrados por los cobradores (no manuales).
    </div>
</div>

<form method="POST">
    <input type="hidden" name="accion" value="guardar_metas">

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-bullseye"></i> Metas por Cobrador</span>
            <button type="submit" class="btn-ic btn-primary btn-sm"><i class="fa fa-save"></i> Guardar Metas</button>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>Cobrador</th>
                        <th style="width:180px">Meta Semanal ($)</th>
                        <th class="text-right">Cobrado Semana</th>
                        <th style="min-width:200px">Cumplimiento</th>
                        <th class="text-center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cobradores as $cob): ?>
                    <?php
                    $meta    = (float) ($cob['meta_semanal'] ?: 500000);
                    $cobrado = $cobrado_map[(int) $cob['id']] ?? 0.0;
                    $pct     = $meta > 0 ? min(100, round($cobrado / $meta * 100)) : 0;
                    $color   = $pct >= 100 ? '#d4a017' : ($pct >= 70 ? 'var(--success)' : ($pct >= 40 ? '#f97316' : 'var(--danger)'));
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0;color:#fff">
                                    <?= strtoupper(mb_substr($cob['nombre'], 0, 1) . mb_substr($cob['apellido'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:.95rem"><?= e($cob['apellido'] . ', ' . $cob['nombre']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <input type="number" name="meta[<?= $cob['id'] ?>]" value="<?= (int) $meta ?>"
                                step="10000" min="0" style="width:160px;text-align:right">
                        </td>
                        <td class="text-right" style="font-weight:700;color:var(--success);font-size:1rem">
                            <?= formato_pesos($cobrado) ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;background:rgba(255,255,255,.1);border-radius:99px;height:8px;overflow:hidden">
                                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:99px;transition:width .4s"></div>
                                </div>
                                <span style="font-size:.82rem;font-weight:700;color:<?= $color ?>;min-width:40px;text-align:right"><?= $pct ?>%</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php if ($pct >= 100): ?>
                                <span style="color:#d4a017;font-weight:800;font-size:.85rem"><i class="fa fa-trophy"></i> Cumplida</span>
                            <?php elseif ($pct >= 70): ?>
                                <span style="color:var(--success);font-size:.82rem"><i class="fa fa-check"></i> En camino</span>
                            <?php elseif ($pct >= 40): ?>
                                <span style="color:#f97316;font-size:.82rem"><i class="fa fa-clock"></i> Regular</span>
                            <?php else: ?>
                                <span style="color:var(--danger);font-size:.82rem"><i class="fa fa-arrow-down"></i> Bajo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar Metas</button>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
