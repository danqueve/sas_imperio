<?php
// tickets/nuevo.php — Crear un Reclamo (sobre un crédito) o una Posventa
// (artículo a reparar), siempre ligado a un cliente y a uno de sus créditos.
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('gestionar_reclamos');

$pdo = obtener_conexion();
$is_cobrador = es_cobrador();

// ── Clientes disponibles (scoping: cobrador solo ve los suyos) ─────
$where_cli = "c.estado = 'ACTIVO'";
$params_cli = [];
if ($is_cobrador) {
    $where_cli .= ' AND c.cobrador_id = ?';
    $params_cli[] = $_SESSION['user_id'];
}
$clientes_stmt = $pdo->prepare("
    SELECT c.id, c.nombres, c.apellidos
    FROM ic_clientes c
    WHERE $where_cli
    ORDER BY c.apellidos, c.nombres
");
$clientes_stmt->execute($params_cli);
$clientes = $clientes_stmt->fetchAll();

// ── Créditos de esos mismos clientes (todos, no solo activos — una
// posventa puede ser sobre un artículo de un crédito ya finalizado) ──
$creditos_map = [];
if (!empty($clientes)) {
    $cred_stmt = $pdo->prepare("
        SELECT cr.id, cr.cliente_id, cr.fecha_alta, cr.estado,
               COALESCE(cr.articulo_desc, a.descripcion, 'Sin artículo') AS articulo
        FROM ic_creditos cr
        JOIN ic_clientes c ON c.id = cr.cliente_id
        LEFT JOIN ic_articulos a ON a.id = cr.articulo_id
        WHERE $where_cli
        ORDER BY cr.cliente_id, cr.fecha_alta DESC
    ");
    $cred_stmt->execute($params_cli);
    foreach ($cred_stmt->fetchAll() as $cr) {
        $creditos_map[(int) $cr['cliente_id']][] = [
            'id'    => (int) $cr['id'],
            'label' => '#' . $cr['id'] . ' — ' . $cr['articulo'] . ' — '
                     . date('d/m/Y', strtotime($cr['fecha_alta'])) . ' — ' . ucfirst(strtolower($cr['estado'])),
        ];
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title   = 'Nuevo Reclamo / Posventa';
$page_current = 'tickets';
$topbar_actions = '<a href="index" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<style>
.tipo-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 22px; }
.tipo-wrap { display: block; margin: 0; text-transform: none; font-weight: 400; }
.tipo-radio { position: absolute; opacity: 0; pointer-events: none; }
.tipo-option {
    display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px;
    background: var(--dark-input); border: 2px solid var(--dark-border); border-radius: var(--radius);
    padding: 22px 14px; cursor: pointer; transition: all .15s ease;
}
.tipo-option:hover { border-color: var(--primary); }
.tipo-option i { font-size: 1.6rem; color: var(--text-muted); }
.tipo-option .tipo-label { font-weight: 700; font-size: .88rem; color: var(--text-main); }
.tipo-option .tipo-desc { font-size: .74rem; color: var(--text-muted); }
.tipo-radio:checked + .tipo-option { border-color: var(--primary); background: rgba(60,80,224,.08); }
.tipo-radio:checked + .tipo-option i,
.tipo-radio:checked + .tipo-option .tipo-label { color: var(--primary); }
body.theme-cobrador .tipo-option { background: #f9fafb; border-color: #e5e7eb; }

.nt-empty { text-align: center; padding: 32px 12px; color: var(--text-muted); }
.nt-empty i { font-size: 1.8rem; opacity: .35; display: block; margin-bottom: 10px; }
.nt-titulo-label { font-weight: 700; margin-bottom: 8px; display: block; color: var(--text-main); }
.nt-actions { display: flex; gap: 10px; margin-top: 6px; }
.nt-actions .btn-ic { flex: 1; justify-content: center; }
</style>

<div class="card-ic" style="max-width:680px;margin:0 auto">
    <?php if ($flash): ?>
        <div class="alert-ic alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (empty($clientes)): ?>
        <div class="nt-empty">
            <i class="fa fa-user-slash"></i>
            No tenés clientes asignados todavía — no se puede crear un reclamo o posventa.
        </div>
    <?php else: ?>
    <form method="POST" action="procesar_ticket" class="form-ic" id="form-nuevo-ticket">
        <?php csrf_input(); ?>

        <span class="nt-titulo-label">¿Qué tipo de caso es?</span>
        <div class="tipo-grid">
            <label class="tipo-wrap">
                <input type="radio" name="tipo" value="reclamo" class="tipo-radio" checked>
                <div class="tipo-option">
                    <i class="fa fa-triangle-exclamation"></i>
                    <span class="tipo-label">Reclamo</span>
                    <span class="tipo-desc">Sobre un crédito del cliente</span>
                </div>
            </label>
            <label class="tipo-wrap">
                <input type="radio" name="tipo" value="posventa" class="tipo-radio">
                <div class="tipo-option">
                    <i class="fa fa-screwdriver-wrench"></i>
                    <span class="tipo-label">Posventa</span>
                    <span class="tipo-desc">Artículo a reparar</span>
                </div>
            </label>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Cliente *</label>
                <select name="cliente_id" id="cliente_id_sel" required onchange="actualizarCreditos()">
                    <option value="">— Seleccioná un cliente —</option>
                    <?php foreach ($clientes as $cl): ?>
                        <option value="<?= (int) $cl['id'] ?>"><?= e($cl['apellidos'] . ', ' . $cl['nombres']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Crédito *</label>
                <select name="credito_id" id="credito_id_sel" required disabled>
                    <option value="">— Elegí primero un cliente —</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Título / Asunto *</label>
            <input type="text" name="titulo" id="titulo_input" maxlength="200" required
                   placeholder="Ej: El monto de la cuota no coincide con lo pactado">
        </div>

        <div class="form-group">
            <label>Descripción *</label>
            <textarea name="descripcion" rows="5" required
                      placeholder="Contá con el mayor detalle posible qué reclama o qué le pasa al artículo..."></textarea>
        </div>

        <div class="form-group" style="max-width:220px">
            <label>Prioridad</label>
            <select name="prioridad">
                <option value="baja">Baja</option>
                <option value="media" selected>Media</option>
                <option value="alta">Alta</option>
            </select>
        </div>

        <div class="nt-actions">
            <button type="submit" class="btn-ic btn-primary">
                <i class="fa fa-paper-plane"></i> Crear Caso
            </button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
const creditosMap = <?= json_encode($creditos_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function actualizarCreditos() {
    const cid = document.getElementById('cliente_id_sel').value;
    const sel = document.getElementById('credito_id_sel');
    sel.innerHTML = '';

    const lista = creditosMap[cid] || [];
    if (!cid || lista.length === 0) {
        sel.disabled = true;
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = cid ? 'Este cliente no tiene créditos' : '— Elegí primero un cliente —';
        sel.appendChild(opt);
        return;
    }

    sel.disabled = false;
    const optPlaceholder = document.createElement('option');
    optPlaceholder.value = '';
    optPlaceholder.textContent = '— Seleccioná el crédito —';
    sel.appendChild(optPlaceholder);

    lista.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.label;
        sel.appendChild(opt);
    });
}

// Placeholder del título según el tipo elegido, sin pisar texto ya escrito a mano
document.querySelectorAll('input[name="tipo"]').forEach(r => {
    r.addEventListener('change', function () {
        const titulo = document.getElementById('titulo_input');
        if (titulo.value.trim() !== '') return;
        titulo.placeholder = this.value === 'posventa'
            ? 'Ej: El artículo hace ruido y no enfría bien'
            : 'Ej: El monto de la cuota no coincide con lo pactado';
    });
});
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
