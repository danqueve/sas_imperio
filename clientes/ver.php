<?php
// ============================================================
// clientes/ver.php — Ficha del cliente (diseño compacto)
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index'); exit; }

$stmt = $pdo->prepare("
    SELECT c.*, u.nombre AS cobrador_nombre, u.apellido AS cobrador_apellido
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) { header('Location: index'); exit; }

$garante = $pdo->prepare("SELECT * FROM ic_garantes WHERE cliente_id=? LIMIT 1");
$garante->execute([$id]);
$g = $garante->fetch();

$creditos = $pdo->prepare("
    SELECT cr.*,
           COALESCE(cr.articulo_desc, a.descripcion) AS articulo,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS cuotas_pagadas,
           (SELECT COUNT(*) FROM ic_cuotas WHERE credito_id=cr.id) AS total_cuotas,
           (SELECT SUM(monto_cuota) FROM ic_cuotas WHERE credito_id=cr.id AND estado='PAGADA') AS total_cobrado
    FROM ic_creditos cr
    LEFT JOIN ic_articulos a ON cr.articulo_id = a.id
    WHERE cr.cliente_id = ?
    ORDER BY cr.fecha_alta DESC
");
$creditos->execute([$id]);
$listado_creditos = $creditos->fetchAll();

// Calcular el total pagado histórico
$total_pagado_historico = array_sum(array_column(
    array_filter($listado_creditos, fn($r) => $r['estado'] === 'FINALIZADO' || $r['estado'] === 'EN_CURSO'),
    'total_cobrado'
));

$page_title    = e($c['apellidos'] . ', ' . $c['nombres']);
$page_current  = 'clientes';
$topbar_actions = '
  <a href="ficha_pdf?id=' . $id . '" target="_blank" class="btn-ic btn-ghost btn-sm"><i class="fa fa-file-pdf"></i> PDF</a>
  <a href="editar?id=' . $id . '" class="btn-ic btn-primary btn-sm"><i class="fa fa-pencil"></i> Editar</a>';
require_once __DIR__ . '/../views/layout.php';
?>

<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert-ic alert-<?= e($_SESSION['flash']['type']) ?> mb-3">
        <?= e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:start">

    <!-- ── Columna izquierda ──────────────────────────────── -->
    <div class="card-ic" style="font-size:.83rem">

        <!-- Cabecera compacta -->
        <div style="display:flex;align-items:center;gap:10px;padding-bottom:10px;border-bottom:1px solid var(--dark-border);margin-bottom:10px">
            <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">
                <i class="fa fa-user" style="color:#fff;opacity:.8"></i>
            </div>
            <div style="min-width:0">
                <div class="fw-bold" style="font-size:.95rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= e($c['apellidos'] . ', ' . $c['nombres']) ?>
                </div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
                    <span class="text-muted" style="font-size:.7rem">#<?= $c['id'] ?></span>
                    <?= badge_estado_cliente($c['estado']) ?>
                </div>
                <?php if (!empty($c['puntaje_pago'])): ?>
                <?php
                    $pmap = [1=>['Excelente','var(--success)',100],2=>['Bueno','var(--primary)',70],3=>['Regular','var(--warning)',40],4=>['Malo','var(--danger)',15]];
                    [$plbl,$pclr,$ppct] = $pmap[(int)$c['puntaje_pago']] ?? ['—','var(--text-muted)',0];
                ?>
                <div style="margin-top:5px">
                    <div style="display:flex;justify-content:space-between;font-size:.68rem;margin-bottom:3px">
                        <span style="color:<?= $pclr ?>;font-weight:700"><?= $plbl ?></span>
                        <span class="text-muted"><?= $c['creditos_sin_mora'] ?? 0 ?>/<?= $c['total_creditos_finalizados'] ?? 0 ?> sin mora</span>
                    </div>
                    <div style="height:5px;background:var(--dark-border);border-radius:3px;overflow:hidden">
                        <div style="height:100%;width:<?= $ppct ?>%;background:<?= $pclr ?>;border-radius:3px;transition:width .4s"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Datos en grid de 2 columnas -->
        <div style="display:grid;grid-template-columns:auto 1fr;gap:3px 10px">

            <?php
            $rows = [
                ['DNI',         e($c['dni'] ?: '—')],
                ['CUIL',        e($c['cuil'] ?: '—')],
                ['Nacimiento',  $c['fecha_nacimiento'] ? date('d/m/Y', strtotime($c['fecha_nacimiento'])) : '—'],
                ['Zona',        e($c['zona'] ?: '—')],
                ['Cobrador',    $c['cobrador_nombre'] ? e($c['cobrador_nombre'].' '.$c['cobrador_apellido']) : '—'],
                ['Día cobro',   $c['dia_cobro'] ? nombre_dia($c['dia_cobro']) : '—'],
                ['Alta',        date('d/m/Y', strtotime($c['created_at']))],
            ];
            foreach ($rows as [$lbl, $val]):
            ?>
            <span class="text-muted" style="white-space:nowrap;padding:2px 0"><?= $lbl ?></span>
            <span style="padding:2px 0;word-break:break-word"><?= $val ?></span>
            <?php endforeach; ?>

            <!-- Teléfono con WA -->
            <span class="text-muted" style="padding:2px 0">Teléfono</span>
            <span style="padding:2px 0;display:flex;align-items:center;gap:5px">
                <?= e($c['telefono']) ?>
                <a href="<?= whatsapp_url($c['telefono']) ?>" target="_blank"
                   class="btn-ic btn-success btn-icon btn-sm" style="padding:2px 6px;font-size:.7rem">
                    <i class="fa-brands fa-whatsapp"></i>
                </a>
            </span>

            <?php if ($c['telefono_alt']): ?>
            <span class="text-muted" style="padding:2px 0">Tel. Alt.</span>
            <span style="padding:2px 0"><?= e($c['telefono_alt']) ?></span>
            <?php endif; ?>

            <span class="text-muted" style="padding:2px 0">Dirección</span>
            <span style="padding:2px 0"><?= e($c['direccion'] ?: '—') ?></span>

            <?php if ($c['direccion_laboral']): ?>
            <span class="text-muted" style="padding:2px 0">Dir. Lab.</span>
            <span style="padding:2px 0"><?= e($c['direccion_laboral']) ?></span>
            <?php endif; ?>

            <?php if ($c['coordenadas']): ?>
            <span class="text-muted" style="padding:2px 0">GPS</span>
            <span style="padding:2px 0">
                <a href="<?= maps_url($c['coordenadas']) ?>" target="_blank" class="btn-ic btn-accent btn-sm" style="padding:2px 8px;font-size:.72rem">
                    <i class="fa fa-map-marker-alt"></i> Maps
                </a>
            </span>
            <?php endif; ?>

        </div>

        <div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--dark-border);font-size:.75rem">
            <div class="text-muted" style="margin-bottom:6px">🔗 Portal del cliente</div>
            <?php if ($c['token_acceso']): ?>
                <?php
                $portal_url = APP_URL . 'p/' . $c['token_acceso'];
                $wa_tel     = preg_replace('/\D/', '', $c['telefono'] ?? '');
                $wa_msg     = urlencode("Hola {$c['nombres']}, podés ver el estado de tu crédito en: {$portal_url}");
                $wa_link    = "https://wa.me/{$wa_tel}?text={$wa_msg}";
                ?>
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                    <code style="flex:1;background:rgba(0,0,0,.3);border-radius:6px;padding:5px 8px;
                                 font-size:.74rem;word-break:break-all">
                        <?= e($portal_url) ?>
                    </code>
                    <button onclick="navigator.clipboard.writeText('<?= e($portal_url) ?>').then(()=>{this.innerHTML='<i class=\'fa fa-check\'></i>';setTimeout(()=>this.innerHTML='<i class=\'fa fa-copy\'></i>',1500)})"
                            class="btn-ic btn-ghost btn-sm btn-icon" title="Copiar link" style="flex-shrink:0">
                        <i class="fa fa-copy"></i>
                    </button>
                    <?php if ($wa_tel): ?>
                    <a href="<?= e($wa_link) ?>" target="_blank"
                       class="btn-ic btn-success btn-sm btn-icon" title="Enviar por WhatsApp" style="flex-shrink:0">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="POST" action="generar_token_portal">
                    <input type="hidden" name="cliente_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn-ic btn-accent btn-sm">
                        <i class="fa fa-link"></i> Generar link de acceso
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Garante (si existe) -->
        <?php if ($g): ?>
        <div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--dark-border)">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:6px">
                <i class="fa fa-user-shield"></i> Garante
            </div>
            <div style="display:grid;grid-template-columns:auto 1fr;gap:2px 10px">
                <span class="text-muted">Nombre</span>
                <span class="fw-bold"><?= e($g['apellidos'] . ', ' . $g['nombres']) ?></span>
                <span class="text-muted">DNI</span>
                <span><?= e($g['dni'] ?: '—') ?></span>
                <span class="text-muted">Teléfono</span>
                <span style="display:flex;align-items:center;gap:5px">
                    <?= e($g['telefono'] ?: '—') ?>
                    <?php if ($g['telefono']): ?>
                    <a href="<?= whatsapp_url($g['telefono']) ?>" target="_blank"
                       class="btn-ic btn-success btn-icon btn-sm" style="padding:2px 6px;font-size:.7rem">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                </span>
                <?php if ($g['direccion']): ?>
                <span class="text-muted">Dirección</span>
                <span><?= e($g['direccion']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── Columna derecha: créditos ─────────────────────── -->
    <div class="card-ic">
        <div class="card-ic-header">
            <span class="card-title"><i class="fa fa-file-invoice-dollar"></i> Créditos
                <span class="text-muted" style="font-weight:400;font-size:.78rem">(<?= count($listado_creditos) ?>)</span>
            </span>
            <?php if (!es_cobrador()): ?>
                <a href="../creditos/nuevo?cliente_id=<?= $id ?>" class="btn-ic btn-primary btn-sm">
                    <i class="fa fa-plus"></i> Nuevo
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($listado_creditos)): ?>
            <p class="text-muted text-center" style="padding:24px">Sin créditos registrados.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="table-ic">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Artículo</th>
                        <th>Total</th>
                        <th>Cuota / Frec.</th>
                        <th>Avance</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($listado_creditos as $cr):
                    $pct = $cr['total_cuotas'] > 0 ? round($cr['cuotas_pagadas'] / $cr['total_cuotas'] * 100) : 0;
                ?>
                <tr>
                    <td class="text-muted" style="font-size:.78rem">#<?= $cr['id'] ?></td>
                    <td style="font-size:.82rem"><?= e($cr['articulo']) ?></td>
                    <td class="nowrap fw-bold"><?= formato_pesos($cr['monto_total']) ?></td>
                    <td class="nowrap" style="font-size:.82rem">
                        <?= formato_pesos($cr['monto_cuota']) ?>
                        <span class="text-muted" style="font-size:.7rem">/ <?= ucfirst($cr['frecuencia']) ?></span>
                    </td>
                    <td class="nowrap" style="font-size:.78rem">
                        <?= $cr['cuotas_pagadas'] ?>/<?= $cr['total_cuotas'] ?>
                        <div style="width:60px;height:3px;background:var(--dark-border);border-radius:2px;margin-top:3px;display:inline-block;vertical-align:middle">
                            <div style="width:<?= $pct ?>%;height:100%;background:var(--success);border-radius:2px"></div>
                        </div>
                    </td>
                    <td><?= badge_estado_credito($cr['estado']) ?></td>
                    <td>
                        <a href="../creditos/ver?id=<?= $cr['id'] ?>" class="btn-ic btn-ghost btn-sm btn-icon" title="Ver cronograma">
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

<?php $puede_notas = in_array(usuario_actual()['rol'], ['admin','supervisor']); ?>
<div class="card-ic" style="margin-top:16px">
    <div class="card-ic-header">
        <span class="card-title"><i class="fa fa-note-sticky"></i> Notas internas</span>
    </div>
    <div id="notas-lista" style="margin-bottom:14px;display:flex;flex-direction:column;gap:8px">
        <p class="text-muted" style="font-size:.82rem;padding:8px 0">Cargando notas...</p>
    </div>
    <?php if ($puede_notas): ?>
    <form id="form-nota" style="display:flex;gap:8px;align-items:flex-end">
        <textarea id="inp-nota" rows="2" placeholder="Agregar nota interna..."
            style="flex:1;background:var(--dark-input);border:1px solid var(--dark-border);border-radius:6px;
                   color:var(--text-main);padding:8px 12px;font-family:inherit;font-size:.875rem;resize:vertical;outline:none"></textarea>
        <button type="submit" class="btn-ic btn-primary btn-sm" style="align-self:flex-end">
            <i class="fa fa-paper-plane"></i> Guardar
        </button>
    </form>
    <?php endif; ?>
</div>

<script>
(function(){
    const cid = <?= $id ?>;
    const puedeEscribir = <?= $puede_notas ? 'true' : 'false' ?>;
    const lista = document.getElementById('notas-lista');

    function renderNotas(notas) {
        if (!notas.length) {
            lista.innerHTML = '<p class="text-muted" style="font-size:.82rem;padding:8px 0">Sin notas registradas.</p>';
            return;
        }
        lista.innerHTML = notas.map(n => `
            <div style="background:rgba(0,0,0,.2);border-radius:6px;padding:10px 14px;border-left:3px solid var(--primary)">
                <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:4px;display:flex;justify-content:space-between;align-items:center">
                    <span><strong style="color:var(--text-body)">${n.autor}</strong> · ${n.autor_rol} · ${n.created_at.substring(0,16).replace('T',' ')}</span>
                    ${puedeEscribir ? `<button onclick="eliminarNota(${n.id})" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.78rem;padding:0 4px" title="Eliminar"><i class="fa fa-xmark"></i></button>` : ''}
                </div>
                <div style="font-size:.85rem;color:var(--text-main);white-space:pre-wrap">${n.nota.replace(/</g,'&lt;')}</div>
            </div>`).join('');
    }

    function cargarNotas() {
        fetch(`notas_ajax?cliente_id=${cid}`)
            .then(r=>r.json()).then(d=>{ if(d.ok) renderNotas(d.notas); });
    }

    window.eliminarNota = function(nid) {
        if (!confirm('¿Eliminar esta nota?')) return;
        fetch(`notas_ajax?cliente_id=${cid}`, {method:'DELETE',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`nota_id=${nid}`})
            .then(r=>r.json()).then(d=>{ if(d.ok) cargarNotas(); });
    };

    const form = document.getElementById('form-nota');
    if (form) {
        form.addEventListener('submit', e => {
            e.preventDefault();
            const nota = document.getElementById('inp-nota').value.trim();
            if (!nota) return;
            const fd = new FormData(); fd.append('cliente_id', cid); fd.append('nota', nota);
            fetch('notas_ajax', {method:'POST', body: fd})
                .then(r=>r.json()).then(d=>{ if(d.ok){ document.getElementById('inp-nota').value=''; cargarNotas(); }});
        });
    }

    cargarNotas();
})();
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
