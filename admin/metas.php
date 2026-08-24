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

// ── Guardar metas (override manual opcional; vacío = automático) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_metas') {
    verificar_csrf();
    $metas   = $_POST['meta'] ?? [];
    $stmt_set   = $pdo->prepare("UPDATE ic_usuarios SET meta_semanal = ? WHERE id = ? AND rol = 'cobrador'");
    $stmt_clear = $pdo->prepare("UPDATE ic_usuarios SET meta_semanal = NULL WHERE id = ? AND rol = 'cobrador'");
    $count = 0;
    foreach ($metas as $uid => $val) {
        $val = trim((string) $val);
        if ($val === '') {
            $stmt_clear->execute([(int) $uid]);
        } else {
            $monto = max(0, (float) str_replace(['.', ','], ['', '.'], $val));
            $stmt_set->execute([$monto, (int) $uid]);
        }
        $count++;
    }
    registrar_log($pdo, $_SESSION['user_id'], 'METAS_ACTUALIZADAS', 'usuario', 0,
        "Metas actualizadas para $count cobrador(es)");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Metas actualizadas para $count cobrador(es)."];
    header('Location: metas');
    exit;
}

// ── Snapshot manual de la semana pasada (respaldo si el cron no corrió) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'snapshot_manual') {
    verificar_csrf();
    $semana_pasada = new DateTimeImmutable('-7 days');
    $ids_activos   = $pdo->query("SELECT id FROM ic_usuarios WHERE rol = 'cobrador' AND activo = 1")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids_activos as $cid) {
        registrar_snapshot_metas_semana($pdo, (int) $cid, $semana_pasada);
    }
    $dow_ref   = (int) $semana_pasada->format('N');
    $lunes_ref = $semana_pasada->modify('-' . ($dow_ref - 1) . ' days')->format('Y-m-d');
    registrar_log($pdo, $_SESSION['user_id'], 'SNAPSHOT_METAS_MANUAL', 'usuario', 0,
        'Snapshot manual registrado para la semana del ' . $lunes_ref . ' — ' . count($ids_activos) . ' cobrador(es)');
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Snapshot de la semana del ' . date('d/m', strtotime($lunes_ref)) . ' registrado para ' . count($ids_activos) . ' cobrador(es).'];
    header('Location: metas');
    exit;
}

// ── Semana actual (Lun-Sáb) ────────────────────────────────
$dow       = (int) date('N');
$lunes     = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
$sabado    = date('Y-m-d', strtotime($lunes . ' +5 days'));

// ── Cobradores activos con su meta (override manual, nullable) ──
$cobradores = $pdo->query("
    SELECT id, nombre, apellido, meta_semanal
    FROM ic_usuarios
    WHERE rol = 'cobrador' AND activo = 1
    ORDER BY apellido, nombre
")->fetchAll();

// ── Meta automática por cobrador (cuotas con vencimiento esta semana) ──
$metas_auto = calcular_metas_semanales_auto($pdo, array_column($cobradores, 'id'));

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
$topbar_actions = '<a href="historial_metas" class="btn-ic btn-ghost btn-sm"><i class="fa fa-history"></i> Historial de Metas</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="card-ic mb-4" style="padding:16px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div style="font-size:.85rem;color:var(--text-muted)">
            <i class="fa fa-info-circle"></i>
            Semana actual: <strong><?= date('d/m', strtotime($lunes)) ?> — <?= date('d/m', strtotime($sabado)) ?></strong>
            · Los montos cobrados solo incluyen pagos registrados por los cobradores (no manuales).
            · La meta se calcula sola: semanales por vencimiento dentro de esta semana, quincenales/mensuales/diarios por toda la cartera ya vencida. Dejá el campo vacío para usar el cálculo automático, o cargá un monto para fijarlo manualmente.
        </div>
        <form method="POST" style="flex-shrink:0">
            <?php csrf_input(); ?>
            <input type="hidden" name="accion" value="snapshot_manual">
            <button type="submit" class="btn-ic btn-ghost btn-sm" style="white-space:nowrap"
                onclick="return confirm('Esto guarda en el historial las metas y lo cobrado de la semana pasada. Si ya existe un snapshot de esa semana, se actualiza. ¿Continuar?')">
                <i class="fa fa-camera"></i> Snapshot semana pasada
            </button>
        </form>
    </div>
</div>

<form method="POST">
    <?php csrf_input(); ?>
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
                        <th class="text-right">Meta Automática</th>
                        <th style="width:180px">Override Manual ($)</th>
                        <th class="text-right">Cobrado Semana</th>
                        <th style="min-width:200px">Cumplimiento</th>
                        <th class="text-center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cobradores as $cob): ?>
                    <?php
                    $meta_auto     = $metas_auto[(int) $cob['id']] ?? 0.0;
                    $meta_manual   = $cob['meta_semanal']; // nullable: override opcional
                    $es_automatico = ($meta_manual === null);
                    $meta          = $es_automatico ? $meta_auto : (float) $meta_manual;
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
                                    <?php if ($es_automatico): ?>
                                        <span class="badge-ic badge-primary" style="font-size:.68rem">Automático</span>
                                    <?php else: ?>
                                        <span class="badge-ic badge-muted" style="font-size:.68rem">Manual</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="text-right" style="color:var(--text-muted)">
                            <?= formato_pesos($meta_auto) ?>
                        </td>
                        <td>
                            <input type="number" name="meta[<?= $cob['id'] ?>]"
                                value="<?= $es_automatico ? '' : (int) $meta_manual ?>"
                                placeholder="Automático" step="10000" min="0" style="width:160px;text-align:right">
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
