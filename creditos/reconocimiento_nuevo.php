<?php
// ============================================================
// creditos/reconocimiento_nuevo.php — Crear/Editar Reconocimiento de Deuda
// Integrado al sistema principal, pre-pobla datos del crédito/cliente
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_agenda');

$pdo = obtener_conexion();
$credito_id = (int) ($_GET['credito_id'] ?? 0);
if (!$credito_id) die('ID de crédito inválido.');

// ── Crédito + cliente + garante ───────────────────────────
$stmt = $pdo->prepare("
    SELECT cr.id AS credito_id, cr.monto_total, cr.estado,
           cl.id AS cliente_id, cl.nombres, cl.apellidos, cl.dni, cl.cuil,
           cl.direccion, cl.calle1, cl.calle2, cl.tiene_garante,
           g.id AS garante_id, g.nombres AS g_nombres, g.apellidos AS g_apellidos,
           g.dni AS g_dni, g.cuil AS g_cuil, g.direccion AS g_direccion, g.localidad AS g_localidad
    FROM ic_creditos cr
    JOIN ic_clientes cl ON cr.cliente_id = cl.id
    LEFT JOIN ic_garantes g ON g.cliente_id = cl.id
    WHERE cr.id = ?
");
$stmt->execute([$credito_id]);
$row = $stmt->fetch();
if (!$row) die('Crédito no encontrado.');

// ── Reconocimiento existente (para edición) ───────────────
$rec = $pdo->prepare("SELECT * FROM ic_reconocimientos WHERE credito_id = ?");
$rec->execute([$credito_id]);
$recon = $rec->fetch() ?: [];

$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// Valores del formulario: preferir guardado, luego datos del cliente
$d = [
    'deudor_genero'   => $recon['deudor_genero']   ?? 'El',
    'deudor_cuil'     => $recon['deudor_cuil']     ?? ($row['cuil'] ?? ''),
    'deudor_calle1'   => $recon['deudor_calle1']   ?? ($row['calle1'] ?? ''),
    'deudor_calle2'   => $recon['deudor_calle2']   ?? ($row['calle2'] ?? ''),
    'suma_letras'     => $recon['suma_letras']      ?? '',
    'garante_genero'  => $recon['garante_genero']  ?? 'Sr.',
    'garante_cuil'    => $recon['garante_cuil']    ?? ($row['g_cuil'] ?? ''),
    'garante_localidad'=> $recon['garante_localidad'] ?? ($row['g_localidad'] ?? ''),
    'dia_firma'       => $recon['dia_firma']        ?? (int)date('d'),
    'mes_firma'       => $recon['mes_firma']        ?? $meses[(int)date('m') - 1],
    'anio_firma'      => $recon['anio_firma']       ?? date('Y'),
    'ciudad_firma'    => $recon['ciudad_firma']     ?? 'San Miguel de Tucuman',
    'provincia_firma' => $recon['provincia_firma']  ?? 'Tucuman',
];

$monto_fmt = '$ ' . number_format((float)$row['monto_total'], 2, ',', '.');
$tiene_garante = (int)$row['tiene_garante'];

$page_title = 'Reconocimiento de Deuda — Crédito #' . $credito_id;
$page_current = 'creditos';

$topbar_actions = '<a href="ver?id=' . $credito_id . '" class="btn-ic btn-ghost btn-sm"><i class="fa fa-arrow-left"></i> Volver al crédito</a>';
if ($recon) {
    $topbar_actions .= ' <a href="reconocimiento_pdf.php?credito_id=' . $credito_id . '" target="_blank" class="btn-ic btn-success btn-sm"><i class="fa fa-file-pdf"></i> Ver PDF</a>';
}

require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:900px">

<div class="card-ic mb-4">
  <div class="card-ic-header">
    <span class="card-title"><i class="fa fa-file-contract"></i> Reconocimiento de Deuda — Crédito #<?= $credito_id ?></span>
    <span class="badge-ic badge-<?= $row['estado'] === 'FINALIZADO' ? 'success' : 'primary' ?>"><?= e($row['estado']) ?></span>
  </div>
  <!-- Vista previa -->
  <div style="background:var(--bg-card,#1e293b);border:1px solid var(--border,#334155);border-radius:8px;padding:16px 20px;font-size:.88rem;line-height:1.9;color:var(--text-secondary,#cbd5e1);margin-bottom:0">
    <strong style="color:var(--text-primary,#f1f5f9)">RECONOCIMIENTO DE DEUDA</strong><br><br>
    <span id="pv-genero"><?= e($d['deudor_genero']) ?></span> que suscribe
    <span style="color:var(--warning,#f59e0b);font-style:italic"><?= e($row['apellidos'] . ', ' . $row['nombres']) ?></span>,
    D.N.I. N° <span style="color:var(--warning,#f59e0b)"><?= e($row['dni'] ?? '—') ?></span>,
    CUIL/CUIT <span id="pv-cuil" style="color:var(--warning,#f59e0b);font-style:italic"><?= e($d['deudor_cuil'] ?: '[CUIL]') ?></span>,
    con domicilio real sito en <span style="color:var(--warning,#f59e0b)"><?= e($row['direccion'] ?? '—') ?></span>
    entre calles <span id="pv-calle1" style="color:var(--warning,#f59e0b);font-style:italic"><?= e($d['deudor_calle1'] ?: '[Calle 1]') ?></span> y
    <span id="pv-calle2" style="color:var(--warning,#f59e0b);font-style:italic"><?= e($d['deudor_calle2'] ?: '[Calle 2]') ?></span>,
    constituyendo domicilio especial [...] en mi carácter de principal pagador/a de la deuda
    por la suma total de <strong style="color:var(--success,#22c55e)"><?= $monto_fmt ?></strong>
    (<span id="pv-letras" style="font-style:italic"><?= e($d['suma_letras'] ?: '[monto en palabras]') ?></span>)
    a favor de <strong>Imperio Comercial SAS</strong>, CUIT 30-71907246-8.<br><br>
    <?php if ($tiene_garante): ?>
    Reconozco en este acto, que adeudo [...] en forma solidaria con
    <span id="pv-ggenero"><?= e($d['garante_genero']) ?></span>
    <span style="color:var(--warning,#f59e0b);font-style:italic"><?= e(($row['g_apellidos'] ?? '') . (($row['g_apellidos'] ?? '') ? ', ' . ($row['g_nombres'] ?? '') : '[Garante]')) ?></span>,
    D.N.I. N° <span style="color:var(--warning,#f59e0b)"><?= e($row['g_dni'] ?? '—') ?></span>,
    en la localidad <span id="pv-gloc" style="color:var(--warning,#f59e0b);font-style:italic"><?= e($d['garante_localidad'] ?: '[Localidad]') ?></span>.<br><br>
    <?php endif; ?>
    Se firma en la Ciudad de <span id="pv-ciudad" style="color:var(--warning,#f59e0b)"><?= e($d['ciudad_firma']) ?></span>,
    provincia de <span id="pv-provincia" style="color:var(--warning,#f59e0b)"><?= e($d['provincia_firma']) ?></span>, a los
    <span id="pv-dia" style="color:var(--warning,#f59e0b)"><?= e($d['dia_firma']) ?></span>
    del mes de <span id="pv-mes" style="color:var(--warning,#f59e0b)"><?= e($d['mes_firma']) ?></span>
    del año <span id="pv-anio" style="color:var(--warning,#f59e0b)"><?= e($d['anio_firma']) ?></span>.-
  </div>
</div>

<form method="POST" action="reconocimiento_guardar.php" id="formRecon">
  <input type="hidden" name="credito_id" value="<?= $credito_id ?>">

  <!-- DEUDOR -->
  <div class="card-ic mb-4">
    <div class="card-ic-header">
      <span class="card-title"><i class="fa fa-user"></i> Datos del Deudor</span>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label>Género</label>
        <select name="deudor_genero" onchange="pvSet('pv-genero',this.value)">
          <option value="El"  <?= $d['deudor_genero']==='El'  ? 'selected':'' ?>>El</option>
          <option value="La"  <?= $d['deudor_genero']==='La'  ? 'selected':'' ?>>La</option>
        </select>
      </div>
      <div class="form-group">
        <label>Apellido y Nombre</label>
        <input type="text" value="<?= e($row['apellidos'] . ', ' . $row['nombres']) ?>" readonly
               style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>DNI</label>
        <input type="text" value="<?= e($row['dni'] ?? '') ?>" readonly
               style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>CUIL / CUIT</label>
        <input type="text" name="deudor_cuil" value="<?= e($d['deudor_cuil']) ?>"
               placeholder="20-12345678-0" oninput="pvSet('pv-cuil',this.value||'[CUIL]')">
      </div>
      <div class="form-group" style="grid-column:span 2">
        <label>Domicilio</label>
        <input type="text" value="<?= e($row['direccion'] ?? '') ?>" readonly
               style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>Entre Calle</label>
        <input type="text" name="deudor_calle1" value="<?= e($d['deudor_calle1']) ?>"
               placeholder="Ej: San Martín" oninput="pvSet('pv-calle1',this.value||'[Calle 1]')">
      </div>
      <div class="form-group">
        <label>Y Calle</label>
        <input type="text" name="deudor_calle2" value="<?= e($d['deudor_calle2']) ?>"
               placeholder="Ej: Rivadavia" oninput="pvSet('pv-calle2',this.value||'[Calle 2]')">
      </div>
    </div>
  </div>

  <!-- DEUDA -->
  <div class="card-ic mb-4">
    <div class="card-ic-header">
      <span class="card-title"><i class="fa fa-dollar-sign"></i> Datos de la Deuda</span>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label>Monto Total</label>
        <input type="text" value="<?= $monto_fmt ?>" readonly
               style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>Monto en Letras <span style="color:var(--danger,#ef4444)">*</span></label>
        <input type="text" name="suma_letras" id="suma_letras"
               value="<?= e($d['suma_letras']) ?>"
               placeholder="Ej: pesos ciento cincuenta mil" required
               oninput="pvSet('pv-letras',this.value||'[monto en palabras]')">
        <small style="font-size:.75rem;color:var(--text-muted,#64748b)">
          <a href="#" onclick="autoLetras();return false" style="color:var(--primary,#6366f1)">
            <i class="fa fa-magic"></i> Generar automáticamente
          </a>
        </small>
      </div>
    </div>
  </div>

  <!-- GARANTE -->
  <div class="card-ic mb-4">
    <div class="card-ic-header">
      <span class="card-title"><i class="fa fa-user-shield"></i> Datos del Garante / Deudor Solidario</span>
      <?php if (!$tiene_garante): ?>
        <span class="badge-ic badge-warning"><i class="fa fa-exclamation-triangle"></i> Sin garante registrado</span>
      <?php endif; ?>
    </div>
    <?php if (!$tiene_garante): ?>
      <div class="alert-ic alert-warning" style="margin:12px 16px 0">
        <i class="fa fa-info-circle"></i>
        Este cliente no tiene garante. El párrafo de garante no aparecerá en el PDF.
        Para agregar un garante, <a href="../clientes/editar?id=<?= $row['cliente_id'] ?>">editá el cliente</a>.
      </div>
    <?php endif; ?>
    <div class="form-grid" <?= !$tiene_garante ? 'style="opacity:.45;pointer-events:none"' : '' ?>>
      <div class="form-group">
        <label>Tratamiento</label>
        <select name="garante_genero" <?= !$tiene_garante ? 'disabled' : '' ?>
                onchange="pvSet('pv-ggenero',this.value)">
          <option value="Sr."  <?= $d['garante_genero']==='Sr.'  ? 'selected':'' ?>>Sr.</option>
          <option value="Sra." <?= $d['garante_genero']==='Sra.' ? 'selected':'' ?>>Sra.</option>
        </select>
      </div>
      <div class="form-group">
        <label>Apellido y Nombre</label>
        <input type="text" value="<?= e(($row['g_apellidos'] ?? '') . (($row['g_apellidos'] ?? '') ? ', ' . ($row['g_nombres'] ?? '') : '')) ?>"
               readonly style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>DNI</label>
        <input type="text" value="<?= e($row['g_dni'] ?? '') ?>" readonly
               style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>CUIL / CUIT</label>
        <input type="text" name="garante_cuil" value="<?= e($d['garante_cuil']) ?>"
               placeholder="20-12345678-0" <?= !$tiene_garante ? 'disabled' : '' ?>>
      </div>
      <div class="form-group" style="grid-column:span 2">
        <label>Domicilio</label>
        <input type="text" value="<?= e($row['g_direccion'] ?? '') ?>" readonly
               style="background:var(--bg-input-disabled,#0f172a);color:var(--text-muted,#64748b);cursor:not-allowed">
      </div>
      <div class="form-group">
        <label>Localidad</label>
        <input type="text" name="garante_localidad" value="<?= e($d['garante_localidad']) ?>"
               placeholder="Ej: Tafí Viejo" <?= !$tiene_garante ? 'disabled' : '' ?>
               oninput="pvSet('pv-gloc',this.value||'[Localidad]')">
      </div>
    </div>
  </div>

  <!-- FECHA Y LUGAR -->
  <div class="card-ic mb-4">
    <div class="card-ic-header">
      <span class="card-title"><i class="fa fa-calendar"></i> Fecha y Lugar de Firma</span>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label>Ciudad</label>
        <input type="text" name="ciudad_firma" value="<?= e($d['ciudad_firma']) ?>"
               oninput="pvSet('pv-ciudad',this.value)">
      </div>
      <div class="form-group">
        <label>Provincia</label>
        <input type="text" name="provincia_firma" value="<?= e($d['provincia_firma']) ?>"
               oninput="pvSet('pv-provincia',this.value)">
      </div>
      <div class="form-group">
        <label>Día</label>
        <input type="number" name="dia_firma" min="1" max="31" value="<?= e($d['dia_firma']) ?>"
               oninput="pvSet('pv-dia',this.value)">
      </div>
      <div class="form-group">
        <label>Mes</label>
        <select name="mes_firma" onchange="pvSet('pv-mes',this.value)">
          <?php foreach ($meses as $m): ?>
            <option value="<?= $m ?>" <?= $d['mes_firma']===$m ? 'selected':'' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Año</label>
        <input type="text" name="anio_firma" value="<?= e($d['anio_firma']) ?>"
               oninput="pvSet('pv-anio',this.value)">
      </div>
    </div>
  </div>

  <div class="d-flex gap-3">
    <button type="submit" name="accion" value="guardar" class="btn-ic btn-primary">
      <i class="fa fa-save"></i> Guardar
    </button>
    <button type="button" onclick="guardarYPdf()" class="btn-ic btn-success">
      <i class="fa fa-file-pdf"></i> Guardar y PDF
    </button>
    <a href="ver?id=<?= $credito_id ?>" class="btn-ic btn-ghost">Cancelar</a>
  </div>
</form>

</div>

<?php
$monto_float = (float)$row['monto_total'];
$page_scripts = <<<JS
<script>
const MONTO = {$monto_float};

function pvSet(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
}

// Conversión número a letras (español)
function numeroALetrasBase(n) {
    const u=['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve'];
    const e=['diez','once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve'];
    const d=['','','veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
    const c=['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
    let r='', x=Math.floor(n);
    if(x>=100){r+=c[Math.floor(x/100)]+' ';x%=100;}
    if(x>=20){r+=d[Math.floor(x/10)];x%=10;if(x>0)r+=' y '+u[x];}
    else if(x>=10)r+=e[x-10];
    else if(x>0)r+=u[x];
    return r.trim();
}
function numeroALetras(num) {
    let ent=Math.floor(num), cents=Math.round((num-ent)*100);
    if(ent===0)return'pesos cero';
    let r='';
    if(ent>=1000000){let m=Math.floor(ent/1000000);r+=numeroALetrasBase(m)+(m===1?' millón ':' millones ');ent%=1000000;}
    if(ent>=1000){let m=Math.floor(ent/1000);r+=(m===1?'mil ':numeroALetrasBase(m)+' mil ');ent%=1000;}
    if(ent>0)r+=numeroALetrasBase(ent);
    let s='pesos '+r.trim();
    if(cents>0)s+=' con '+cents+' centavos';
    return s;
}

function autoLetras() {
    var inp = document.getElementById('suma_letras');
    if (inp) {
        inp.value = numeroALetras(MONTO);
        pvSet('pv-letras', inp.value || '[monto en palabras]');
    }
}

function guardarYPdf() {
    var form = document.getElementById('formRecon');
    var data = new FormData(form);
    data.append('accion', 'guardar_pdf');
    fetch('reconocimiento_guardar.php', {
        method: 'POST',
        body: data,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            window.open('reconocimiento_pdf.php?credito_id=' + d.credito_id, '_blank');
            setTimeout(() => { window.location.href = 'ver?id=' + d.credito_id; }, 400);
        } else {
            alert('Error: ' + (d.error || 'No se pudo guardar'));
        }
    })
    .catch(err => alert('Error: ' + err.message));
}

// Auto-generar letras si está vacío al cargar
document.addEventListener('DOMContentLoaded', function() {
    var inp = document.getElementById('suma_letras');
    if (inp && !inp.value.trim()) autoLetras();
});
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>
