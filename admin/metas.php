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

// ── Semana actual (Lun-Sáb; domingo incluido para cobrado) ──
$dow       = (int) date('N');
$lunes     = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
$sabado    = date('Y-m-d', strtotime($lunes . ' +5 days'));
$domingo   = date('Y-m-d', strtotime($lunes . ' +6 days'));

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
$stmt_cobrado->execute([$lunes, $domingo]);
$cobrado_map = [];
foreach ($stmt_cobrado->fetchAll() as $r) {
    $cobrado_map[(int) $r['cobrador_id']] = (float) $r['total'];
}

// ── Objetivos efectivos y resumen del equipo ─────────────────
// La meta efectiva solo existe en memoria para esta vista: un override manual
// prevalece sobre la automática, sin alterar ningún valor almacenado.
$meta_automatica_equipo = 0.0;
$meta_objetivo_equipo   = 0.0;
$cobrado_atribuido      = 0.0;
foreach ($cobradores as &$cob) {
    $cob['meta_automatica'] = (float) ($metas_auto[(int) $cob['id']] ?? 0.0);
    $cob['es_automatica']   = ($cob['meta_semanal'] === null);
    $cob['meta_efectiva']   = $cob['es_automatica']
        ? $cob['meta_automatica']
        : (float) $cob['meta_semanal'];
    $cob['cobrado_semana']  = (float) ($cobrado_map[(int) $cob['id']] ?? 0.0);
    $cob['cumplimiento_pct'] = $cob['meta_efectiva'] > 0
        ? min(100, round($cob['cobrado_semana'] / $cob['meta_efectiva'] * 100))
        : 0;
    $cob['cumplimiento_color'] = $cob['cumplimiento_pct'] >= 100
        ? '#d4a017'
        : ($cob['cumplimiento_pct'] >= 70
            ? 'var(--success)'
            : ($cob['cumplimiento_pct'] >= 40 ? '#f97316' : 'var(--danger)'));

    $meta_automatica_equipo += $cob['meta_automatica'];
    $meta_objetivo_equipo   += $cob['meta_efectiva'];
    $cobrado_atribuido      += $cob['cobrado_semana'];
}
unset($cob);
$cumplimiento_equipo = $meta_objetivo_equipo > 0
    ? min(100, round($cobrado_atribuido / $meta_objetivo_equipo * 100))
    : 0;

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
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:14px">
        <span class="card-title"><i class="fa fa-users"></i> Resumen del Equipo</span>
        <span class="text-muted" style="font-size:.78rem">Objetivos vigentes de la semana actual</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
        <div style="padding:12px;background:rgba(255,255,255,.04);border-radius:8px">
            <div class="text-muted" style="font-size:.74rem">Meta automática de cartera</div>
            <div style="font-size:1.1rem;font-weight:800;margin-top:4px"><?= formato_pesos($meta_automatica_equipo) ?></div>
        </div>
        <div style="padding:12px;background:rgba(255,255,255,.04);border-radius:8px">
            <div class="text-muted" style="font-size:.74rem">Meta objetivo del equipo</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--primary-light);margin-top:4px"><?= formato_pesos($meta_objetivo_equipo) ?></div>
        </div>
        <div style="padding:12px;background:rgba(255,255,255,.04);border-radius:8px">
            <div class="text-muted" style="font-size:.74rem">Cobrado atribuido</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--success);margin-top:4px"><?= formato_pesos($cobrado_atribuido) ?></div>
        </div>
        <div style="padding:12px;background:rgba(255,255,255,.04);border-radius:8px">
            <div class="text-muted" style="font-size:.74rem">Cumplimiento vs objetivo</div>
            <div style="font-size:1.1rem;font-weight:800;margin-top:4px"><?= $cumplimiento_equipo ?>% <span class="text-muted" style="font-size:.76rem;font-weight:400">de meta</span></div>
        </div>
    </div>
</div>

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
                        <th class="text-right">Meta Objetivo Efectiva</th>
                        <th class="text-right">Cobrado Semana</th>
                        <th style="min-width:200px">Cumplimiento</th>
                        <th class="text-center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cobradores as $cob): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0;color:#fff">
                                    <?= strtoupper(mb_substr($cob['nombre'], 0, 1) . mb_substr($cob['apellido'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:.95rem"><?= e($cob['apellido'] . ', ' . $cob['nombre']) ?></div>
                                    <?php if ($cob['es_automatica']): ?>
                                        <span class="badge-ic badge-primary" style="font-size:.68rem">Automático</span>
                                    <?php else: ?>
                                        <span class="badge-ic badge-muted" style="font-size:.68rem">Manual</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="text-right" style="color:var(--text-muted)">
                            <?= formato_pesos($cob['meta_automatica']) ?>
                        </td>
                        <td>
                            <input type="number" name="meta[<?= $cob['id'] ?>]"
                                value="<?= $cob['es_automatica'] ? '' : (int) $cob['meta_semanal'] ?>"
                                placeholder="Automático" step="10000" min="0" style="width:160px;text-align:right">
                        </td>
                        <td class="text-right" style="font-weight:800">
                            <?= formato_pesos($cob['meta_efectiva']) ?>
                            <div class="text-muted" style="font-size:.7rem;font-weight:400">
                                <?= $cob['es_automatica'] ? 'Automática aplicada' : 'Manual aplicada' ?>
                            </div>
                        </td>
                        <td class="text-right" style="font-weight:700;color:var(--success);font-size:1rem">
                            <?= formato_pesos($cob['cobrado_semana']) ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;background:rgba(255,255,255,.1);border-radius:99px;height:8px;overflow:hidden">
                                    <div style="width:<?= $cob['cumplimiento_pct'] ?>%;height:100%;background:<?= $cob['cumplimiento_color'] ?>;border-radius:99px;transition:width .4s"></div>
                                </div>
                                <span style="font-size:.82rem;font-weight:700;color:<?= $cob['cumplimiento_color'] ?>;min-width:40px;text-align:right"><?= $cob['cumplimiento_pct'] ?>%</span>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php if ($cob['cumplimiento_pct'] >= 100): ?>
                                <span style="color:#d4a017;font-weight:800;font-size:.85rem"><i class="fa fa-trophy"></i> Cumplida</span>
                            <?php elseif ($cob['cumplimiento_pct'] >= 70): ?>
                                <span style="color:var(--success);font-size:.82rem"><i class="fa fa-check"></i> En camino</span>
                            <?php elseif ($cob['cumplimiento_pct'] >= 40): ?>
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
