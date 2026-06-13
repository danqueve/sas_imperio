<?php
// ============================================================
// admin/migrar_cobrador.php — Migrar clientes y créditos entre cobradores
// Solo accesible para admin
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_usuarios');

$pdo = obtener_conexion();

$cobradores = $pdo->query(
    "SELECT id, nombre, apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY apellido ASC"
)->fetchAll();

$accion    = $_POST['accion'] ?? '';
$desde_id  = (int) ($_POST['cobrador_desde'] ?? 0);
$hasta_id  = (int) ($_POST['cobrador_hasta'] ?? 0);
$preview   = null;
$resultado = null;
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    if (!$desde_id || !$hasta_id) {
        $error = 'Debés seleccionar ambos cobradores.';
    } elseif ($desde_id === $hasta_id) {
        $error = 'El cobrador de origen y destino no pueden ser el mismo.';
    } else {
        $mapa = [];
        foreach ($cobradores as $c) {
            $mapa[$c['id']] = $c['apellido'] . ', ' . $c['nombre'];
        }

        if ($accion === 'preview') {
            $stmt = $pdo->prepare("
                SELECT cl.id, cl.apellidos, cl.nombres, cl.dni,
                       COUNT(cr.id) AS cant_creditos_activos
                FROM ic_clientes cl
                LEFT JOIN ic_creditos cr
                       ON cr.cliente_id = cl.id AND cr.estado IN ('EN_CURSO','MOROSO')
                WHERE cl.cobrador_id = ?
                GROUP BY cl.id, cl.apellidos, cl.nombres, cl.dni
                ORDER BY cl.apellidos, cl.nombres
            ");
            $stmt->execute([$desde_id]);
            $clientes_preview = $stmt->fetchAll();

            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM ic_creditos WHERE cobrador_id = ?");
            $stmt2->execute([$desde_id]);
            $total_creditos = (int) $stmt2->fetchColumn();

            $preview = [
                'desde_nombre'   => $mapa[$desde_id] ?? '—',
                'hasta_nombre'   => $mapa[$hasta_id] ?? '—',
                'clientes'       => $clientes_preview,
                'total_creditos' => $total_creditos,
            ];

        } elseif ($accion === 'ejecutar') {
            try {
                $pdo->beginTransaction();

                $st1 = $pdo->prepare("UPDATE ic_clientes SET cobrador_id = ? WHERE cobrador_id = ?");
                $st1->execute([$hasta_id, $desde_id]);
                $clientes_migrados = $st1->rowCount();

                $st2 = $pdo->prepare("UPDATE ic_creditos SET cobrador_id = ? WHERE cobrador_id = ?");
                $st2->execute([$hasta_id, $desde_id]);
                $creditos_migrados = $st2->rowCount();

                $pdo->commit();

                registrar_log($pdo, (int)$_SESSION['user_id'], 'MIGRACION_COBRADOR', 'sistema', 0,
                    "Migrados {$clientes_migrados} clientes y {$creditos_migrados} créditos "
                    . "del cobrador #{$desde_id} ({$mapa[$desde_id]}) al #{$hasta_id} ({$mapa[$hasta_id]})");

                $resultado = [
                    'ok'           => true,
                    'clientes'     => $clientes_migrados,
                    'creditos'     => $creditos_migrados,
                    'desde_nombre' => $mapa[$desde_id] ?? '—',
                    'hasta_nombre' => $mapa[$hasta_id] ?? '—',
                ];

            } catch (Exception $e) {
                $pdo->rollBack();
                $resultado = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
    }
}

$page_title   = 'Migrar Cobrador';
$page_current = 'usuarios';
$topbar_actions = '<a href="usuarios" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver a Usuarios</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:780px">

<?php if ($error): ?>
    <div class="alert-ic alert-danger">
        <i class="fa fa-circle-xmark"></i> <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($resultado): ?>
    <?php if ($resultado['ok']): ?>
    <div class="alert-ic alert-success">
        <i class="fa fa-circle-check"></i>
        <div>
            <strong>Migración completada.</strong>
            <ul style="margin:8px 0 0 20px">
                <li><?= (int)$resultado['clientes'] ?> cliente<?= $resultado['clientes'] !== 1 ? 's' : '' ?> migrado<?= $resultado['clientes'] !== 1 ? 's' : '' ?></li>
                <li><?= (int)$resultado['creditos'] ?> crédito<?= $resultado['creditos'] !== 1 ? 's' : '' ?> reasignado<?= $resultado['creditos'] !== 1 ? 's' : '' ?></li>
            </ul>
            De <strong><?= e($resultado['desde_nombre']) ?></strong>
            a <strong><?= e($resultado['hasta_nombre']) ?></strong>.
            La operación quedó registrada en el log de actividades.
        </div>
    </div>
    <div style="margin-top:16px">
        <a href="migrar_cobrador" class="btn-ic btn-ghost"><i class="fa fa-rotate-left"></i> Nueva migración</a>
        <a href="usuarios" class="btn-ic btn-ghost" style="margin-left:8px"><i class="fa fa-users"></i> Ir a Usuarios</a>
    </div>
    <?php else: ?>
    <div class="alert-ic alert-danger">
        <i class="fa fa-triangle-exclamation"></i>
        <div>
            <strong>Error — la migración fue revertida.</strong><br>
            <span style="font-size:.875rem"><?= e($resultado['error']) ?></span>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$resultado): ?>

<!-- FORMULARIO -->
<div class="card-ic mb-4">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-right-left"></i> Migrar clientes entre cobradores</span>
    </div>
    <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:20px">
        Todos los clientes y créditos del cobrador de origen pasarán al cobrador de destino.
        Revisá la vista previa antes de confirmar.
    </p>
    <form method="POST" id="form-migrar">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="accion" value="preview" id="input-accion">

        <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:16px;align-items:end;margin-bottom:20px">
            <div>
                <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:6px">
                    <i class="fa fa-circle-arrow-right" style="color:var(--danger)"></i> Cobrador <strong>origen</strong>
                </label>
                <select name="cobrador_desde" required style="width:100%">
                    <option value="">— Seleccioná origen —</option>
                    <?php foreach ($cobradores as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= $desde_id === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['apellido'] . ', ' . $c['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="padding-bottom:6px;color:var(--text-muted);font-size:1.4rem">
                <i class="fa fa-arrow-right"></i>
            </div>

            <div>
                <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:6px">
                    <i class="fa fa-circle-arrow-left" style="color:var(--success)"></i> Cobrador <strong>destino</strong>
                </label>
                <select name="cobrador_hasta" required style="width:100%">
                    <option value="">— Seleccioná destino —</option>
                    <?php foreach ($cobradores as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= $hasta_id === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['apellido'] . ', ' . $c['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn-ic" style="background:var(--primary);color:#fff;border:none">
            <i class="fa fa-magnifying-glass"></i> Ver vista previa
        </button>
    </form>
</div>

<!-- PREVIEW -->
<?php if ($preview): ?>

<?php if (empty($preview['clientes'])): ?>
    <div class="alert-ic alert-warning">
        <i class="fa fa-triangle-exclamation"></i>
        <strong><?= e($preview['desde_nombre']) ?></strong> no tiene clientes asignados. No hay nada que migrar.
    </div>
<?php else: ?>

<div class="alert-ic alert-warning" style="border-left:4px solid var(--warning)">
    <i class="fa fa-triangle-exclamation"></i>
    <div>
        <strong>Revisá antes de confirmar.</strong>
        Se van a migrar <strong><?= count($preview['clientes']) ?> cliente<?= count($preview['clientes']) !== 1 ? 's' : '' ?></strong>
        y <strong><?= $preview['total_creditos'] ?> crédito<?= $preview['total_creditos'] !== 1 ? 's' : '' ?></strong>
        (todos los estados) de
        <strong><?= e($preview['desde_nombre']) ?></strong>
        a
        <strong><?= e($preview['hasta_nombre']) ?></strong>.
    </div>
</div>

<div class="card-ic mb-4" style="padding:0;overflow:hidden">
    <div class="card-ic-header" style="padding:14px 16px">
        <span class="card-title">
            <i class="fa fa-users"></i>
            Clientes a migrar —
            <span style="color:var(--danger)"><?= e($preview['desde_nombre']) ?></span>
            <i class="fa fa-arrow-right" style="font-size:.75rem;margin:0 6px"></i>
            <span style="color:var(--success)"><?= e($preview['hasta_nombre']) ?></span>
        </span>
        <span style="background:rgba(79,70,229,.18);color:var(--primary-light);font-size:.8rem;padding:3px 12px;border-radius:12px;font-weight:700">
            <?= count($preview['clientes']) ?> clientes
        </span>
    </div>
    <div style="overflow-x:auto">
        <table class="table-ic">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>DNI</th>
                    <th style="text-align:center">Créditos activos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview['clientes'] as $cl): ?>
                <tr>
                    <td class="fw-bold"><?= e($cl['apellidos'] . ', ' . $cl['nombres']) ?></td>
                    <td class="text-muted"><?= e($cl['dni']) ?></td>
                    <td style="text-align:center">
                        <?php if ((int)$cl['cant_creditos_activos'] > 0): ?>
                            <span style="background:rgba(245,158,11,.18);color:var(--warning);font-size:.78rem;padding:2px 10px;border-radius:12px;font-weight:700">
                                <?= (int)$cl['cant_creditos_activos'] ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token"      value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="accion"           value="ejecutar">
    <input type="hidden" name="cobrador_desde"   value="<?= $desde_id ?>">
    <input type="hidden" name="cobrador_hasta"   value="<?= $hasta_id ?>">
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:40px">
        <button type="button" class="btn-ic btn-danger" onclick="abrirModal()">
            <i class="fa fa-right-left"></i> Confirmar migración
        </button>
        <a href="migrar_cobrador" class="btn-ic btn-ghost">Cancelar</a>
    </div>
</form>

<!-- Modal confirmación -->
<div id="modal-migrar" style="display:none;position:fixed;inset:0;z-index:9999;
        background:rgba(0,0,0,.72);backdrop-filter:blur(3px);
        align-items:center;justify-content:center">
    <div style="background:var(--dark-card,#1e2130);border:1px solid var(--dark-border,#2d3250);
                border-radius:14px;padding:32px 36px;max-width:440px;width:90%;
                box-shadow:0 20px 60px rgba(0,0,0,.6);text-align:center">

        <div style="width:60px;height:60px;border-radius:50%;background:rgba(245,158,11,.15);
                    display:flex;align-items:center;justify-content:center;margin:0 auto 18px">
            <i class="fa fa-right-left" style="font-size:1.6rem;color:var(--warning)"></i>
        </div>

        <div style="font-size:1.1rem;font-weight:800;margin-bottom:10px">¿Confirmar migración?</div>
        <div style="font-size:.875rem;color:var(--text-muted);line-height:1.6;margin-bottom:6px">
            <strong><?= count($preview['clientes']) ?> clientes</strong> y
            <strong><?= $preview['total_creditos'] ?> créditos</strong><br>
            de <strong style="color:var(--danger)"><?= e($preview['desde_nombre']) ?></strong><br>
            a <strong style="color:var(--success)"><?= e($preview['hasta_nombre']) ?></strong>
        </div>

        <div style="margin:22px 0">
            <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:8px">Podés confirmar en</div>
            <div style="width:56px;height:56px;border-radius:50%;margin:0 auto;
                        border:3px solid var(--dark-border,#2d3250);
                        display:flex;align-items:center;justify-content:center;
                        font-size:1.5rem;font-weight:900;color:var(--warning);position:relative">
                <svg style="position:absolute;inset:0;width:100%;height:100%;transform:rotate(-90deg)" viewBox="0 0 60 60">
                    <circle cx="30" cy="30" r="27" fill="none" stroke="var(--dark-border,#2d3250)" stroke-width="3"/>
                    <circle id="modal-arc" cx="30" cy="30" r="27" fill="none"
                            stroke="var(--warning,#f59e0b)" stroke-width="3"
                            stroke-dasharray="169.6" stroke-dashoffset="0"
                            style="transition:stroke-dashoffset .9s linear"/>
                </svg>
                <span id="modal-num">5</span>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:center">
            <button id="btn-confirmar" class="btn-ic btn-danger" disabled
                    onclick="submitMigrar()"
                    style="opacity:.45;cursor:not-allowed;transition:opacity .3s;min-width:160px">
                <i class="fa fa-right-left"></i> Confirmar
            </button>
            <button class="btn-ic btn-ghost" onclick="cerrarModal()">Cancelar</button>
        </div>
    </div>
</div>

<script>
let _timer = null;
function abrirModal() {
    const modal = document.getElementById('modal-migrar');
    const num   = document.getElementById('modal-num');
    const arc   = document.getElementById('modal-arc');
    const btn   = document.getElementById('btn-confirmar');
    const CIRC  = 169.6;
    let seg = 5;
    num.textContent = seg;
    arc.style.strokeDashoffset = 0;
    arc.style.stroke = 'var(--warning,#f59e0b)';
    btn.disabled = true; btn.style.opacity = '.45'; btn.style.cursor = 'not-allowed';
    modal.style.display = 'flex';
    clearInterval(_timer);
    _timer = setInterval(() => {
        seg--;
        num.textContent = seg;
        arc.style.strokeDashoffset = CIRC * (1 - seg / 5);
        if (seg <= 0) {
            clearInterval(_timer);
            num.textContent  = '✓';
            arc.style.stroke = '#10b981';
            btn.disabled     = false;
            btn.style.opacity = '1';
            btn.style.cursor  = 'pointer';
        }
    }, 1000);
}
function cerrarModal() {
    clearInterval(_timer);
    document.getElementById('modal-migrar').style.display = 'none';
}
function submitMigrar() {
    cerrarModal();
    document.querySelector('form[method="POST"]:last-of-type').submit();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
</script>

<?php endif; // clientes no vacíos ?>
<?php endif; // preview ?>

<?php endif; // !resultado ?>

</div>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
