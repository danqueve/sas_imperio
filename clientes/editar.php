<?php
// ============================================================
// clientes/editar.php — Edición de cliente
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('editar_clientes');

$pdo = obtener_conexion();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index');
    exit;
}

$cliente = $pdo->prepare("SELECT * FROM ic_clientes WHERE id=?");
$cliente->execute([$id]);
$c = $cliente->fetch();
if (!$c) {
    header('Location: index');
    exit;
}

$garante = $pdo->prepare("SELECT * FROM ic_garantes WHERE cliente_id=? LIMIT 1");
$garante->execute([$id]);
$g = $garante->fetch() ?: [];

$cobradores = $pdo->query("SELECT id,nombre,apellido FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;
    if (empty($v['nombres']) || empty($v['apellidos']) || empty($v['telefono'])) {
        $error = 'Los campos Nombres, Apellidos y Teléfono son obligatorios.';
    } else {
        $pdo->prepare("
            UPDATE ic_clientes SET
              nombres=?, apellidos=?, dni=?, cuil=?, telefono=?, telefono_alt=?,
              fecha_nacimiento=?, direccion=?, direccion_laboral=?, coordenadas=?,
              cobrador_id=?, dia_cobro=?, zona=?, estado=?, tiene_garante=?
            WHERE id=?
        ")->execute([
                    trim($v['nombres']),
                    trim($v['apellidos']),
                    trim($v['dni'] ?? ''),
                    trim($v['cuil'] ?? ''),
                    trim($v['telefono']),
                    trim($v['telefono_alt'] ?? ''),
                    $v['fecha_nacimiento'] ?: null,
                    trim($v['direccion'] ?? ''),
                    trim($v['direccion_laboral'] ?? ''),
                    trim($v['coordenadas'] ?? ''),
                    ($v['cobrador_id'] ?: null),
                    ($v['dia_cobro'] ?: null),
                    trim($v['zona'] ?? ''),
                    $v['estado'] ?? 'ACTIVO',
                    !empty($v['tiene_garante']) ? 1 : 0,
                    $id,
                ]);

        // Sincronizar cobrador en créditos activos si hubo cambio
        $nuevo_cobrador_id = $v['cobrador_id'] ?: null;
        if ($nuevo_cobrador_id != $c['cobrador_id']) {
            $pdo->prepare("UPDATE ic_creditos SET cobrador_id=? WHERE cliente_id=? AND estado IN ('EN_CURSO', 'MOROSO')")
                ->execute([$nuevo_cobrador_id, $id]);
        }

        // Garante
        if (!empty($v['tiene_garante']) && !empty($v['g_nombres'])) {
            if ($g) {
                $pdo->prepare("UPDATE ic_garantes SET nombres=?,apellidos=?,dni=?,telefono=?,direccion=?,coordenadas=? WHERE cliente_id=?")
                    ->execute([trim($v['g_nombres']), trim($v['g_apellidos']), trim($v['g_dni'] ?? ''), trim($v['g_telefono'] ?? ''), trim($v['g_direccion'] ?? ''), trim($v['g_coordenadas'] ?? ''), $id]);
            } else {
                $pdo->prepare("INSERT INTO ic_garantes (cliente_id,nombres,apellidos,dni,telefono,direccion,coordenadas) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$id, trim($v['g_nombres']), trim($v['g_apellidos']), trim($v['g_dni'] ?? ''), trim($v['g_telefono'] ?? ''), trim($v['g_direccion'] ?? ''), trim($v['g_coordenadas'] ?? '')]);
            }
        } elseif ($g) {
            $pdo->prepare("DELETE FROM ic_garantes WHERE cliente_id=?")->execute([$id]);
        }

        registrar_log($pdo, $_SESSION['user_id'], 'CLIENTE_EDITADO', 'cliente', $id,
            trim($v['apellidos']) . ', ' . trim($v['nombres']));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Cliente actualizado correctamente.'];
        header('Location: ver?id=' . $id);
        exit;
    }
}
$v = $c; // usar datos existentes si no hay POST

$page_title = 'Editar Cliente';
$page_current = 'clientes';
require_once __DIR__ . '/../views/layout.php';
?>

<div style="max-width:900px">
    <?php if ($error): ?>
        <div class="alert-ic alert-danger"><i class="fa fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-ic">
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-user"></i> Datos del Cliente</span>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>Apellidos *</label>
                    <input type="text" name="apellidos" value="<?= e($v['apellidos']) ?>" required>
                </div>
                <div class="form-group"><label>Nombres *</label>
                    <input type="text" name="nombres" value="<?= e($v['nombres']) ?>" required>
                </div>
                <div class="form-group"><label>DNI</label>
                    <input type="text" name="dni" value="<?= e($v['dni'] ?? '') ?>">
                </div>
                <div class="form-group"><label>CUIL / CUIT</label>
                    <input type="text" name="cuil" value="<?= e($v['cuil'] ?? '') ?>">
                </div>
                <div class="form-group"><label>Teléfono *</label>
                    <input type="text" name="telefono" value="<?= e($v['telefono']) ?>" required>
                </div>
                <div class="form-group"><label>Teléfono Alt.</label>
                    <input type="text" name="telefono_alt" value="<?= e($v['telefono_alt'] ?? '') ?>">
                </div>
                <div class="form-group"><label>Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" value="<?= e($v['fecha_nacimiento'] ?? '') ?>">
                </div>
                <div class="form-group"><label>Estado</label>
                    <select name="estado">
                        <?php foreach (['ACTIVO', 'INACTIVO', 'MOROSO'] as $est): ?>
                            <option value="<?= $est ?>" <?= $v['estado'] === $est ? 'selected' : '' ?>>
                                <?= $est ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column:span 2"><label>Dirección</label>
                    <input type="text" name="direccion" value="<?= e($v['direccion'] ?? '') ?>">
                </div>
                <div class="form-group" style="grid-column:span 2"><label>Dirección Laboral</label>
                    <input type="text" name="direccion_laboral" value="<?= e($v['direccion_laboral'] ?? '') ?>">
                </div>
                <div class="form-group"><label>Coordenadas GPS</label>
                    <input type="text" name="coordenadas" id="coordenadas" value="<?= e($v['coordenadas'] ?? '') ?>"
                        placeholder="-34.6037,-58.3816">
                    <small id="coordenadas_fb" style="display:block;margin-top:3px;font-size:.75rem;color:var(--text-muted,#888)">
                        <i class="fa fa-map-location-dot" style="font-size:.7rem"></i> Podés pegar un link de Google Maps
                    </small>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                    <button type="button" onclick="obtenerUbicacion()" class="btn-ic btn-ghost w-100">
                        <i class="fa fa-location-crosshairs"></i> Usar mi ubicación
                    </button>
                </div>
            </div>
        </div>

        <div class="card-ic mb-4">
            <div class="card-ic-header"><span class="card-title"><i class="fa fa-user-tie"></i> Asignación</span></div>
            <div class="form-grid">
                <div class="form-group"><label>Cobrador</label>
                    <select name="cobrador_id">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($cobradores as $cb): ?>
                            <option value="<?= $cb['id'] ?>" <?= $v['cobrador_id'] == $cb['id'] ? 'selected' : '' ?>>
                                <?= e($cb['nombre'] . ' ' . $cb['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Día de Cobro</label>
                    <select name="dia_cobro">
                        <option value="">— Cualquier día —</option>
                        <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'] as $n => $d): ?>
                            <option value="<?= $n ?>" <?= $v['dia_cobro'] == $n ? 'selected' : '' ?>>
                                <?= $d ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Zona</label>
                    <input type="text" name="zona" value="<?= e($v['zona'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-user-shield"></i> Garante</span>
                <div class="d-flex align-center gap-2">
                    <input type="checkbox" name="tiene_garante" id="chk_garante" value="1"
                        <?= $c['tiene_garante'] ? 'checked' : '' ?>>
                    <label for="chk_garante" class="text-muted" style="font-size:.82rem;cursor:pointer">¿Tiene
                        garante?</label>
                </div>
            </div>
            <div id="seccion_garante" style="<?= $c['tiene_garante'] ? '' : 'display:none' ?>">
                <div class="form-grid">
                    <div class="form-group"><label>Apellidos</label>
                        <input type="text" name="g_apellidos" value="<?= e($g['apellidos'] ?? '') ?>">
                    </div>
                    <div class="form-group"><label>Nombres</label>
                        <input type="text" name="g_nombres" value="<?= e($g['nombres'] ?? '') ?>">
                    </div>
                    <div class="form-group"><label>DNI</label>
                        <input type="text" name="g_dni" value="<?= e($g['dni'] ?? '') ?>">
                    </div>
                    <div class="form-group"><label>Teléfono</label>
                        <input type="text" name="g_telefono" value="<?= e($g['telefono'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="grid-column:span 2"><label>Dirección</label>
                        <input type="text" name="g_direccion" value="<?= e($g['direccion'] ?? '') ?>">
                    </div>
                    <div class="form-group"><label>Coordenadas GPS</label>
                        <input type="text" name="g_coordenadas" id="g_coordenadas" value="<?= e($g['coordenadas'] ?? '') ?>"
                            placeholder="-34.6037,-58.3816">
                        <small id="g_coordenadas_fb" style="display:block;margin-top:3px;font-size:.75rem;color:var(--text-muted,#888)">
                            <i class="fa fa-map-location-dot" style="font-size:.7rem"></i> Podés pegar un link de Google Maps
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar Cambios</button>
            <a href="ver?id=<?= $id ?>" class="btn-ic btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php
$page_scripts = <<<JS
<script>
document.getElementById('chk_garante').addEventListener('change', function(){
  document.getElementById('seccion_garante').style.display = this.checked ? '' : 'none';
});
function obtenerUbicacion(){
  if(!navigator.geolocation) return;
  navigator.geolocation.getCurrentPosition(p=>{
    document.getElementById('coordenadas').value=p.coords.latitude.toFixed(6)+','+p.coords.longitude.toFixed(6);
    setMapsFeedback('coordenadas','✓ Ubicación capturada','var(--success,#22c55e)');
    showToast('Ubicación capturada','success');
  });
}

// ── Google Maps URL parser ────────────────────────────────────────────────────
function setMapsFeedback(id, msg, color) {
  var fb = document.getElementById(id + '_fb');
  if (fb) { fb.textContent = msg; fb.style.color = color; }
}

function extractCoordsFromUrl(url) {
  var m;
  m = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
  if (m) return m[1] + ',' + m[2];
  m = url.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/);
  if (m) return m[1] + ',' + m[2];
  m = url.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);
  if (m) return m[1] + ',' + m[2];
  return null;
}

function setupMapsInput(id) {
  var inp = document.getElementById(id);
  if (!inp) return;

  function process(val) {
    val = val.trim();
    if (!val.includes('http')) return;

    var coords = extractCoordsFromUrl(val);
    if (coords) {
      inp.value = coords;
      setMapsFeedback(id, '✓ Coordenadas extraídas correctamente', 'var(--success,#22c55e)');
      return;
    }

    if (val.includes('maps.app.goo.gl') || val.includes('goo.gl/maps')) {
      setMapsFeedback(id, '⏳ Resolviendo URL…', '#999');
      fetch('resolver_maps?url=' + encodeURIComponent(val))
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.lat && d.lng) {
            inp.value = d.lat + ',' + d.lng;
            setMapsFeedback(id, '✓ Coordenadas extraídas correctamente', 'var(--success,#22c55e)');
          } else {
            setMapsFeedback(id, '✗ No se pudieron extraer las coordenadas', 'var(--danger,#ef4444)');
          }
        })
        .catch(function() { setMapsFeedback(id, '✗ Error de conexión', 'var(--danger,#ef4444)'); });
      return;
    }

    setMapsFeedback(id, '✗ Formato de link no reconocido', 'var(--danger,#ef4444)');
  }

  inp.addEventListener('paste', function() {
    setTimeout(function() { process(inp.value); }, 30);
  });
  inp.addEventListener('input', function() {
    var val = inp.value.trim();
    if (val.includes('http')) {
      process(val);
    } else if (/^-?\d+\.\d+,-?\d+\.\d+$/.test(val)) {
      setMapsFeedback(id, '✓ Formato correcto', 'var(--success,#22c55e)');
    } else {
      var fb = document.getElementById(id + '_fb');
      if (fb) { fb.textContent = '\u{1F4CD} Podés pegar un link de Google Maps'; fb.style.color = 'var(--text-muted,#888)'; }
    }
  });
}

setupMapsInput('coordenadas');
setupMapsInput('g_coordenadas');
</script>
JS;
require_once __DIR__ . '/../views/layout_footer.php';
?>