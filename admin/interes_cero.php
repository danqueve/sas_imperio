<?php
// ============================================================
// admin/interes_cero.php — Operación masiva: interés moratorio → 0
// Opción B: cero tasa futura + borra mora acumulada en cuotas activas
// Solo accesible para admin
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_usuarios'); // solo admin

$pdo = obtener_conexion();

// ── Preview: estadísticas antes de ejecutar ──────────────────
$stats_cr = $pdo->query("
    SELECT
        COUNT(*)                          AS total_creditos,
        SUM(estado = 'EN_CURSO')          AS en_curso,
        SUM(estado = 'MOROSO')            AS morosos,
        ROUND(AVG(interes_moratorio_pct),2) AS avg_tasa,
        MIN(interes_moratorio_pct)        AS min_tasa,
        MAX(interes_moratorio_pct)        AS max_tasa
    FROM ic_creditos
    WHERE estado IN ('EN_CURSO','MOROSO')
      AND interes_moratorio_pct > 0
")->fetch(PDO::FETCH_ASSOC);

$stats_cu = $pdo->query("
    SELECT COUNT(*) AS total_cuotas,
           COALESCE(SUM(monto_mora), 0) AS total_mora
    FROM ic_cuotas
    WHERE estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
      AND monto_mora > 0
      AND credito_id IN (
          SELECT id FROM ic_creditos WHERE estado IN ('EN_CURSO','MOROSO')
      )
")->fetch(PDO::FETCH_ASSOC);

// Desglose por cobrador
$por_cobrador = $pdo->query("
    SELECT u.nombre, u.apellido,
           COUNT(cr.id) AS cant,
           ROUND(AVG(cr.interes_moratorio_pct),2) AS avg_tasa,
           COALESCE(SUM(cu_mora.mora), 0) AS mora_total
    FROM ic_creditos cr
    JOIN ic_usuarios u ON u.id = cr.cobrador_id
    LEFT JOIN (
        SELECT cu.credito_id, SUM(cu.monto_mora) AS mora
        FROM ic_cuotas cu
        WHERE cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
          AND cu.monto_mora > 0
        GROUP BY cu.credito_id
    ) cu_mora ON cu_mora.credito_id = cr.id
    WHERE cr.estado IN ('EN_CURSO','MOROSO')
      AND cr.interes_moratorio_pct > 0
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY cant DESC
")->fetchAll(PDO::FETCH_ASSOC);

$ya_ejecutado = ((int)$stats_cr['total_creditos'] === 0);

// ── POST: ejecutar ────────────────────────────────────────────
$resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'ejecutar' && !$ya_ejecutado) {
    try {
        $pdo->beginTransaction();

        // 1. Backup: guardar snapshot en log antes de modificar
        $ids_cr = $pdo->query("
            SELECT id, interes_moratorio_pct FROM ic_creditos
            WHERE estado IN ('EN_CURSO','MOROSO') AND interes_moratorio_pct > 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        $ids_cu = $pdo->query("
            SELECT cu.id, cu.monto_mora FROM ic_cuotas cu
            JOIN ic_creditos cr ON cu.credito_id = cr.id
            WHERE cr.estado IN ('EN_CURSO','MOROSO')
              AND cu.estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
              AND cu.monto_mora > 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        $backup = json_encode([
            'fecha'    => date('Y-m-d H:i:s'),
            'usuario'  => $_SESSION['user_id'],
            'creditos' => $ids_cr,   // [{id, interes_moratorio_pct}]
            'cuotas'   => $ids_cu,   // [{id, monto_mora}]
        ]);
        registrar_log($pdo, (int)$_SESSION['user_id'], 'INTERES_CERO_BACKUP', 'sistema', 0,
            'Backup previo: ' . count($ids_cr) . ' créditos, ' . count($ids_cu) . ' cuotas. JSON: ' . $backup);

        // 2. Cero tasa moratorio en créditos activos
        $cr_afectados = $pdo->exec("
            UPDATE ic_creditos
            SET interes_moratorio_pct = 0
            WHERE estado IN ('EN_CURSO','MOROSO') AND interes_moratorio_pct > 0
        ");

        // 3. Cero mora acumulada en cuotas no pagadas
        $cu_afectadas = $pdo->exec("
            UPDATE ic_cuotas
            SET monto_mora = 0
            WHERE estado IN ('PENDIENTE','VENCIDA','PARCIAL','CAP_PAGADA')
              AND monto_mora > 0
              AND credito_id IN (
                  SELECT id FROM ic_creditos WHERE estado IN ('EN_CURSO','MOROSO')
              )
        ");

        $pdo->commit();

        registrar_log($pdo, (int)$_SESSION['user_id'], 'INTERES_CERO_EJECUTADO', 'sistema', 0,
            "Operación completada: {$cr_afectados} créditos con tasa → 0%, {$cu_afectadas} cuotas con mora → $0");

        $resultado = ['ok' => true, 'creditos' => $cr_afectados, 'cuotas' => $cu_afectadas,
                      'mora_condonada' => (float)$stats_cu['total_mora']];

    } catch (Exception $e) {
        $pdo->rollBack();
        $resultado = ['ok' => false, 'error' => $e->getMessage()];
    }
}

$page_title   = 'Operación: Interés a Cero';
$page_current = 'interes_cero';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:860px">

<?php if ($resultado): ?>
    <?php if ($resultado['ok']): ?>
    <div class="alert-ic alert-success">
        <i class="fa fa-circle-check"></i>
        <strong>Operación completada exitosamente.</strong>
        <ul style="margin:8px 0 0 20px">
            <li><?= $resultado['creditos'] ?> créditos con <code>interes_moratorio_pct → 0%</code></li>
            <li><?= $resultado['cuotas'] ?> cuotas con <code>monto_mora → $0</code></li>
            <li>Mora condonada total: <strong><?= formato_pesos($resultado['mora_condonada']) ?></strong></li>
        </ul>
        El backup de los valores originales quedó guardado en el log de actividades.
    </div>
    <?php else: ?>
    <div class="alert-ic alert-danger">
        <i class="fa fa-exclamation-circle"></i>
        <strong>Error:</strong> <?= e($resultado['error']) ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($ya_ejecutado && !$resultado): ?>
    <div class="alert-ic alert-success">
        <i class="fa fa-circle-check"></i>
        <strong>Ya ejecutado.</strong> Todos los créditos activos tienen <code>interes_moratorio_pct = 0</code>.
        No hay créditos pendientes de actualizar.
    </div>
<?php endif; ?>

<!-- ADVERTENCIA -->
<?php if (!$ya_ejecutado): ?>
<div class="alert-ic alert-warning" style="border-left:4px solid var(--danger)">
    <i class="fa fa-triangle-exclamation" style="color:var(--danger)"></i>
    <div>
        <strong>Operación irreversible desde la UI.</strong>
        Esta acción modifica masivamente la base de datos. El backup se guarda en el log de actividades,
        pero revertir requiere intervención manual en la DB.
    </div>
</div>
<?php endif; ?>

<!-- PREVIEW STATS -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-magnifying-glass-chart"></i> Vista previa — Impacto de la operación</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;padding:4px 0 8px">

        <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--primary)">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Créditos afectados</div>
            <div class="fw-bold" style="font-size:1.5rem;color:var(--primary);margin-top:4px">
                <?= number_format((int)$stats_cr['total_creditos']) ?>
            </div>
            <div class="text-muted" style="font-size:.78rem">
                <?= $stats_cr['en_curso'] ?> EN CURSO · <?= $stats_cr['morosos'] ?> MOROSO
            </div>
        </div>

        <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--warning)">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Tasa moratorio actual</div>
            <div class="fw-bold" style="font-size:1.5rem;color:var(--warning);margin-top:4px">
                <?= $stats_cr['avg_tasa'] ?>%
            </div>
            <div class="text-muted" style="font-size:.78rem">
                promedio · rango <?= $stats_cr['min_tasa'] ?>% – <?= $stats_cr['max_tasa'] ?>%
            </div>
        </div>

        <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--danger)">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Mora acumulada a condonar</div>
            <div class="fw-bold" style="font-size:1.2rem;color:var(--danger);margin-top:4px">
                <?= formato_pesos((float)$stats_cu['total_mora']) ?>
            </div>
            <div class="text-muted" style="font-size:.78rem">
                en <?= $stats_cu['total_cuotas'] ?> cuotas con mora > $0
            </div>
        </div>

        <div style="background:var(--dark-bg);border-radius:8px;padding:14px;border-left:3px solid var(--success)">
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.6px">Resultado final</div>
            <div class="fw-bold" style="font-size:1.1rem;color:var(--success);margin-top:4px">0%</div>
            <div class="text-muted" style="font-size:.78rem">tasa moratorio · mora congelada</div>
        </div>
    </div>
</div>

<!-- DESGLOSE POR COBRADOR -->
<?php if ($por_cobrador): ?>
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-users"></i> Desglose por cobrador</span>
    </div>
    <table class="table-ic" style="font-size:.875rem">
        <thead>
            <tr>
                <th>Cobrador</th>
                <th class="text-center">Créditos</th>
                <th class="text-center">Tasa prom.</th>
                <th class="text-right">Mora a condonar</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($por_cobrador as $row): ?>
            <tr>
                <td><?= e($row['apellido'] . ', ' . $row['nombre']) ?></td>
                <td class="text-center"><?= $row['cant'] ?></td>
                <td class="text-center"><?= $row['avg_tasa'] ?>%</td>
                <td class="text-right"><?= $row['mora_total'] > 0 ? formato_pesos((float)$row['mora_total']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- BOTÓN EJECUTAR -->
<?php if (!$ya_ejecutado && (!$resultado || !$resultado['ok'])): ?>
<form method="POST">
    <input type="hidden" name="accion" value="ejecutar">
    <div class="d-flex gap-3 mb-5">
        <button type="button" class="btn-ic btn-danger" onclick="abrirModal()">
            <i class="fa fa-bolt"></i> Ejecutar — Poner intereses a cero
        </button>
        <a href="dashboard" class="btn-ic btn-ghost">Cancelar</a>
    </div>
</form>
<?php else: ?>
<div class="d-flex gap-3 mb-5">
    <a href="dashboard" class="btn-ic btn-ghost"><i class="fa fa-arrow-left"></i> Volver al dashboard</a>
</div>
<?php endif; ?>

</div>

<!-- ── Modal confirmación con cuenta regresiva ─────────────────── -->
<div id="modal-interes" style="
        display:none;position:fixed;inset:0;z-index:9999;
        background:rgba(0,0,0,.7);backdrop-filter:blur(3px);
        align-items:center;justify-content:center">
    <div style="
            background:var(--dark-card,#1e2130);border:1px solid var(--dark-border,#2d3250);
            border-radius:14px;padding:32px 36px;max-width:460px;width:90%;
            box-shadow:0 20px 60px rgba(0,0,0,.6);text-align:center">

        <div style="width:64px;height:64px;border-radius:50%;background:rgba(211,64,83,.15);
                    display:flex;align-items:center;justify-content:center;margin:0 auto 18px">
            <i class="fa fa-triangle-exclamation" style="font-size:1.8rem;color:var(--danger)"></i>
        </div>

        <div style="font-size:1.15rem;font-weight:800;margin-bottom:8px">¿Confirmar operación masiva?</div>
        <div style="font-size:.875rem;color:var(--text-muted,#94a3b8);margin-bottom:6px;line-height:1.5">
            Se modificarán <strong><?= number_format((int)$stats_cr['total_creditos']) ?> créditos</strong>
            y se condonarán <strong><?= formato_pesos((float)$stats_cu['total_mora']) ?></strong> en mora.<br>
            <strong style="color:var(--danger)">Esta acción no se puede deshacer desde la UI.</strong>
        </div>

        <div id="modal-countdown-wrap" style="margin:20px 0">
            <div style="font-size:.78rem;color:var(--text-muted,#94a3b8);margin-bottom:8px">Podés confirmar en</div>
            <div style="width:60px;height:60px;border-radius:50%;margin:0 auto;
                        border:3px solid var(--dark-border,#2d3250);
                        display:flex;align-items:center;justify-content:center;
                        font-size:1.6rem;font-weight:900;color:var(--danger);position:relative">
                <svg style="position:absolute;inset:0;width:100%;height:100%;transform:rotate(-90deg)" viewBox="0 0 60 60">
                    <circle cx="30" cy="30" r="27" fill="none" stroke="var(--dark-border,#2d3250)" stroke-width="3"/>
                    <circle id="modal-arc" cx="30" cy="30" r="27" fill="none"
                            stroke="var(--danger,#d34053)" stroke-width="3"
                            stroke-dasharray="169.6" stroke-dashoffset="0"
                            style="transition:stroke-dashoffset .9s linear"/>
                </svg>
                <span id="modal-num">10</span>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:center">
            <button id="btn-confirmar" class="btn-ic btn-danger" disabled
                    onclick="submitForm()"
                    style="min-width:180px;opacity:.45;cursor:not-allowed;transition:opacity .3s">
                <i class="fa fa-bolt"></i> Confirmar
            </button>
            <button class="btn-ic btn-ghost" onclick="cerrarModal()">Cancelar</button>
        </div>
    </div>
</div>

<script>
let _timer = null;

function abrirModal() {
    const modal = document.getElementById('modal-interes');
    const num   = document.getElementById('modal-num');
    const arc   = document.getElementById('modal-arc');
    const btn   = document.getElementById('btn-confirmar');
    const CIRC  = 169.6;
    let seg = 10;

    num.textContent = seg;
    arc.style.strokeDashoffset = 0;
    arc.style.stroke = 'var(--danger, #d34053)';
    btn.disabled = true;
    btn.style.opacity  = '.45';
    btn.style.cursor   = 'not-allowed';
    modal.style.display = 'flex';
    clearInterval(_timer);

    _timer = setInterval(() => {
        seg--;
        num.textContent = seg;
        arc.style.strokeDashoffset = CIRC * (1 - seg / 10);
        if (seg <= 0) {
            clearInterval(_timer);
            num.textContent    = '✓';
            arc.style.stroke   = '#10b981';
            btn.disabled       = false;
            btn.style.opacity  = '1';
            btn.style.cursor   = 'pointer';
        }
    }, 1000);
}

function cerrarModal() {
    clearInterval(_timer);
    document.getElementById('modal-interes').style.display = 'none';
}

function submitForm() {
    cerrarModal();
    document.querySelector('form').submit();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
