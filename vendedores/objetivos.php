<?php
// ============================================================
// vendedores/objetivos.php — Objetivo de venta mensual por vendedor
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_usuarios');

$pdo = obtener_conexion();

// ── Guardar objetivos (vacío = sin objetivo / borra el que tenía) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_objetivos') {
    verificar_csrf();
    $objetivos  = $_POST['objetivo'] ?? [];
    $stmt_set   = $pdo->prepare("UPDATE ic_vendedores SET objetivo_mensual = ? WHERE id = ?");
    $stmt_clear = $pdo->prepare("UPDATE ic_vendedores SET objetivo_mensual = NULL WHERE id = ?");
    $count = 0;
    foreach ($objetivos as $vid => $val) {
        $val = trim((string) $val);
        if ($val === '') {
            $stmt_clear->execute([(int) $vid]);
        } else {
            // El input es type="number" (HTML5) — siempre serializa el decimal con
            // punto simple, nunca en formato argentino (miles con punto). Convertir
            // como si fuera texto en formato AR (como hace admin/metas.php con su
            // input entero) corrompía cualquier valor con centavos (ej. 1500.50 -> 150050).
            $monto = max(0, (float) $val);
            $stmt_set->execute([$monto, (int) $vid]);
        }
        $count++;
    }
    registrar_log($pdo, $_SESSION['user_id'], 'OBJETIVOS_VENDEDOR_ACTUALIZADOS', 'vendedor', 0,
        "Objetivos actualizados para $count vendedor(es)");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Objetivos actualizados para $count vendedor(es)."];
    header('Location: objetivos');
    exit;
}

$vendedores = $pdo->query("
    SELECT id, nombre, apellido, objetivo_mensual
    FROM ic_vendedores
    WHERE activo = 1
    ORDER BY apellido, nombre
")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'Objetivos de Venta';
$page_current = 'vendedores_stats';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?>">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="mb-4">
    <a href="estadisticas" class="btn-ic btn-ghost"><i class="fa fa-arrow-left"></i> Volver a Estadísticas</a>
</div>

<form method="POST">
    <?php csrf_input(); ?>
    <input type="hidden" name="accion" value="guardar_objetivos">

    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-bullseye"></i> Objetivo de Venta Mensual por Vendedor</span>
            <button type="submit" class="btn-ic btn-primary btn-sm"><i class="fa fa-save"></i> Guardar Objetivos</button>
        </div>
        <div style="padding:14px 20px;font-size:.85rem;color:var(--text-muted)">
            <i class="fa fa-info-circle"></i>
            Dejá el campo vacío para que ese vendedor no tenga objetivo cargado (no se le va a mostrar comparación
            en Estadísticas). El objetivo se prorratea automáticamente según el período que elijas en Estadísticas
            (ej. para "Último trimestre" se compara contra 3 veces este monto).
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:0 20px 16px">
            <label style="font-size:.82rem;color:var(--text-muted);font-weight:600">Objetivo estándar $</label>
            <input type="number" id="objetivo-estandar" step="0.01" min="0" placeholder="0.00"
                   style="max-width:200px;text-align:right">
            <button type="button" class="btn-ic btn-ghost btn-sm" onclick="aplicarEstandar()">
                <i class="fa fa-arrows-turn-to-dots"></i> Aplicar a todos
            </button>
            <span class="text-muted" style="font-size:.78rem">Completa todos los campos con este valor — después podés editar cualquiera antes de Guardar.</span>
        </div>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>Vendedor</th>
                        <th style="text-align:right">Objetivo Mensual $</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($vendedores)): ?>
                    <tr><td colspan="2" class="text-center text-muted" style="padding:24px">Sin vendedores activos.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendedores as $v): ?>
                    <tr>
                        <td class="fw-bold"><?= e($v['apellido'] . ', ' . $v['nombre']) ?></td>
                        <td style="text-align:right">
                            <input type="number" name="objetivo[<?= $v['id'] ?>]" class="inp-objetivo-vendedor"
                                   value="<?= $v['objetivo_mensual'] !== null ? e((string)(float)$v['objetivo_mensual']) : '' ?>"
                                   step="0.01" min="0" placeholder="Sin objetivo"
                                   style="max-width:200px;text-align:right">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<script>
function aplicarEstandar() {
    const val = document.getElementById('objetivo-estandar').value;
    if (val === '') return;
    document.querySelectorAll('.inp-objetivo-vendedor').forEach(inp => { inp.value = val; });
}
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
