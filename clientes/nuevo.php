<?php
// ============================================================
// clientes/nuevo.php — Alta de nuevo cliente
// ============================================================
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('editar_clientes');

$pdo = obtener_conexion();
$cobradores = $pdo->query("SELECT id, nombre, apellido, usuario FROM ic_usuarios WHERE rol='cobrador' AND activo=1 ORDER BY nombre")->fetchAll();

// Zona predeterminada por cobrador (según username)
$zona_por_usuario = [
    'santizalazar' => 'Zona 1',
    'jpbicego'     => 'Zona 2',
    'enzoteceira'  => 'Zona 3',
    'masanchez'    => 'Zona 4-6',
];
$cob_zona_map = [];
foreach ($cobradores as $c) {
    $cob_zona_map[(int)$c['id']] = $zona_por_usuario[$c['usuario']] ?? '';
}

$error = '';
$v = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;

    // Validación básica
    if (empty($v['nombres']) || empty($v['apellidos']) || empty($v['telefono'])) {
        $error = 'Los campos Nombres, Apellidos y Teléfono son obligatorios.';
    } elseif (!empty($v['tiene_garante']) && (empty($v['g_nombres']) || empty($v['g_apellidos']))) {
        $error = 'Si el cliente tiene garante, debe completar Nombres y Apellidos del garante.';
    } else {
        // Insertar cliente
        $token = generar_token();
        $stmt = $pdo->prepare("
            INSERT INTO ic_clientes
              (nombres, apellidos, dni, cuil, telefono, telefono_alt, fecha_nacimiento,
               direccion, direccion_laboral, coordenadas, cobrador_id, dia_cobro,
               zona, estado, token_acceso, tiene_garante)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
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
            $token,
            !empty($v['tiene_garante']) ? 1 : 0,
        ]);
        $cliente_id = (int) $pdo->lastInsertId();
        registrar_log($pdo, $_SESSION['user_id'], 'CLIENTE_CREADO', 'cliente', $cliente_id,
            trim($v['apellidos']) . ', ' . trim($v['nombres']));

        // Garante opcional
        if (!empty($v['tiene_garante']) && !empty($v['g_nombres']) && !empty($v['g_apellidos'])) {
            $pdo->prepare("
                INSERT INTO ic_garantes (cliente_id, nombres, apellidos, dni, telefono, direccion, coordenadas)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                        $cliente_id,
                        trim($v['g_nombres']),
                        trim($v['g_apellidos']),
                        trim($v['g_dni'] ?? ''),
                        trim($v['g_telefono'] ?? ''),
                        trim($v['g_direccion'] ?? ''),
                        trim($v['g_coordenadas'] ?? ''),
                    ]);
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Cliente agregado correctamente.'];
        header('Location: ver?id=' . $cliente_id);
        exit;
    }
}

$page_title = 'Nuevo Cliente';
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

        <!-- DATOS DEL CLIENTE -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-user"></i> Datos del Cliente</span>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Apellidos *</label>
                    <input type="text" name="apellidos" value="<?= e($v['apellidos'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Nombres *</label>
                    <input type="text" name="nombres" value="<?= e($v['nombres'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>DNI</label>
                    <input type="text" name="dni" value="<?= e($v['dni'] ?? '') ?>" placeholder="12.345.678">
                </div>
                <div class="form-group">
                    <label>CUIL / CUIT</label>
                    <input type="text" name="cuil" value="<?= e($v['cuil'] ?? '') ?>" placeholder="20-12345678-0">
                </div>
                <div class="form-group">
                    <label>Teléfono Personal *</label>
                    <input type="text" name="telefono" value="<?= e($v['telefono'] ?? '') ?>" placeholder="+54 9 11 ..."
                        required>
                </div>
                <div class="form-group">
                    <label>Teléfono Alternativo</label>
                    <input type="text" name="telefono_alt" value="<?= e($v['telefono_alt'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Fecha de Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" value="<?= e($v['fecha_nacimiento'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado">
                        <?php foreach (['ACTIVO', 'INACTIVO', 'MOROSO'] as $est): ?>
                            <option value="<?= $est ?>" <?= ($v['estado'] ?? 'ACTIVO') === $est ? 'selected' : '' ?>>
                                <?= $est ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr class="divider">

            <div class="form-grid">
                <div class="form-group" style="grid-column:span 2">
                    <label>Dirección (domicilio)</label>
                    <input type="text" name="direccion" value="<?= e($v['direccion'] ?? '') ?>"
                        placeholder="Calle 123, Localidad">
                </div>
                <div class="form-group" style="grid-column:span 2">
                    <label>Dirección Laboral</label>
                    <input type="text" name="direccion_laboral" value="<?= e($v['direccion_laboral'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Coordenadas GPS</label>
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

        <!-- ASIGNACIÓN -->
        <div class="card-ic mb-4">
            <div class="card-ic-header">
                <span class="card-title"><i class="fa fa-user-tie"></i> Asignación</span>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Cobrador Asignado</label>
                    <select name="cobrador_id" id="cobrador_id" onchange="autoZona()">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($cobradores as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($v['cobrador_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['nombre'] . ' ' . $c['apellido']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Día de Cobro</label>
                    <select name="dia_cobro">
                        <option value="">— Cualquier día —</option>
                        <?php foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'] as $n => $d): ?>
                            <option value="<?= $n ?>" <?= ($v['dia_cobro'] ?? '') == $n ? 'selected' : '' ?>>
                                <?= $d ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Zona</label>
                    <input type="text" name="zona" value="<?= e($v['zona'] ?? '') ?>" placeholder="Ej: ZONA 1, Norte...">
                </div>
            </div>
        </div>

        <!-- GARANTE (toggle) -->
        <div class="card-ic mb-4">
            <div class="card-ic-header" style="cursor:pointer" onclick="toggleGarante()">
                <span class="card-title"><i class="fa fa-user-shield"></i> Garante</span>
                <div class="d-flex align-center gap-2">
                    <input type="checkbox" name="tiene_garante" id="chk_garante" value="1"
                        <?= !empty($v['tiene_garante']) ? 'checked' : '' ?>
                    onclick="event.stopPropagation();toggleGarante()">
                    <label for="chk_garante" class="text-muted" style="font-size:.82rem;cursor:pointer">¿Tiene
                        garante?</label>
                </div>
            </div>
            <div id="seccion_garante" style="<?= !empty($v['tiene_garante']) ? '' : 'display:none' ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Apellidos del Garante</label>
                        <input type="text" name="g_apellidos" value="<?= e($v['g_apellidos'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Nombres del Garante</label>
                        <input type="text" name="g_nombres" value="<?= e($v['g_nombres'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>DNI Garante</label>
                        <input type="text" name="g_dni" value="<?= e($v['g_dni'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Teléfono Garante</label>
                        <input type="text" name="g_telefono" value="<?= e($v['g_telefono'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="grid-column:span 2">
                        <label>Dirección Garante</label>
                        <input type="text" name="g_direccion" value="<?= e($v['g_direccion'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Coordenadas GPS Garante</label>
                        <input type="text" name="g_coordenadas" id="g_coordenadas" value="<?= e($v['g_coordenadas'] ?? '') ?>"
                            placeholder="-34.6037,-58.3816">
                        <small id="g_coordenadas_fb" style="display:block;margin-top:3px;font-size:.75rem;color:var(--text-muted,#888)">
                            <i class="fa fa-map-location-dot" style="font-size:.7rem"></i> Podés pegar un link de Google Maps
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3">
            <button type="submit" class="btn-ic btn-primary"><i class="fa fa-save"></i> Guardar Cliente</button>
            <a href="index" class="btn-ic btn-ghost">Cancelar</a>
        </div>

    </form>
</div>

<?php
$cob_zona_json = json_encode($cob_zona_map, JSON_UNESCAPED_UNICODE);

$page_scripts = <<<JS
<script>
const cobZonaMap = $cob_zona_json;

function autoZona() {
    const cid  = parseInt(document.getElementById('cobrador_id').value) || 0;
    const zona = cobZonaMap[cid] || '';
    const inp  = document.querySelector('[name=zona]');
    if (zona) inp.value = zona;
}

function toggleGarante() {
  const chk = document.getElementById('chk_garante');
  const sec = document.getElementById('seccion_garante');
  chk.checked = !chk.checked;
  sec.style.display = chk.checked ? '' : 'none';
}
// Fix: checkbox click toggles correctly
document.getElementById('chk_garante').addEventListener('change', function(){
  document.getElementById('seccion_garante').style.display = this.checked ? '' : 'none';
});
function obtenerUbicacion() {
  if (!navigator.geolocation) return alert('Tu navegador no soporta geolocalización.');
  navigator.geolocation.getCurrentPosition(function(pos) {
    document.getElementById('coordenadas').value =
      pos.coords.latitude.toFixed(6) + ',' + pos.coords.longitude.toFixed(6);
    setMapsFeedback('coordenadas', '✓ Ubicación capturada', 'var(--success,#22c55e)');
    showToast('Ubicación capturada', 'success');
  }, function() { showToast('No se pudo obtener la ubicación', 'error'); });
}

// ── Google Maps URL parser ────────────────────────────────────────────────────
function setMapsFeedback(id, msg, color) {
  var fb = document.getElementById(id + '_fb');
  if (fb) { fb.textContent = msg; fb.style.color = color; }
}

function extractCoordsFromUrl(url) {
  var m;
  // /@lat,lng,zoom
  m = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
  if (m) return m[1] + ',' + m[2];
  // ?q=lat,lng
  m = url.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/);
  if (m) return m[1] + ',' + m[2];
  // !3dlat!4dlng
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

    // URL corta: resolver server-side
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