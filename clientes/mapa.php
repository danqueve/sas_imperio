<?php
// clientes/mapa.php — Mapa de clientes con Leaflet.js
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();

// Solo clientes con coordenadas
$stmt = $pdo->query("
    SELECT c.id, c.nombres, c.apellidos, c.telefono, c.coordenadas, c.zona, c.estado,
           c.cobrador_id,
           (SELECT cr.estado FROM ic_creditos cr WHERE cr.cliente_id=c.id AND cr.estado IN ('EN_CURSO','MOROSO') ORDER BY cr.fecha_alta DESC LIMIT 1) AS credito_estado,
           CONCAT(u.nombre,' ',u.apellido) AS cobrador
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.coordenadas IS NOT NULL AND c.coordenadas != ''
    ORDER BY c.apellidos
");
$clientes_raw = $stmt->fetchAll();

// Parsear coordenadas — formato "lat,lng"
$clientes_mapa = [];
foreach ($clientes_raw as $cl) {
    if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', trim($cl['coordenadas']), $m)) {
        $cl['lat'] = (float)$m[1];
        $cl['lng'] = (float)$m[2];
        $clientes_mapa[] = $cl;
    }
}

// Cobradores con al menos un cliente geolocalizado (para el filtro)
$cobradores_mapa = $pdo->query("
    SELECT DISTINCT u.id, CONCAT(u.nombre,' ',u.apellido) AS nombre
    FROM ic_clientes c
    JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.coordenadas IS NOT NULL AND c.coordenadas != ''
    ORDER BY u.nombre
")->fetchAll();

$page_title   = 'Mapa de Clientes';
$page_current = 'clientes';
require_once __DIR__ . '/../views/layout.php';
?>

<div class="card-ic" style="padding:0;overflow:hidden">
    <div style="padding:16px 20px;border-bottom:1px solid var(--dark-border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <span class="card-title"><i class="fa fa-map-location-dot"></i> Mapa de Clientes
            <span class="text-muted" style="font-weight:400;font-size:.78rem">(<?= count($clientes_mapa) ?> con coordenadas)</span>
        </span>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <select id="filtro-cobrador" onchange="filtrarMarcadores()" style="background:var(--dark-input);border:1px solid var(--dark-border);border-radius:6px;color:var(--text-main);padding:6px 10px;font-size:.82rem;font-family:inherit">
                <option value="">Todos los cobradores</option>
                <?php foreach ($cobradores_mapa as $cob): ?>
                    <option value="<?= $cob['id'] ?>"><?= e($cob['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <a href="index" class="btn-ic btn-ghost btn-sm"><i class="fa fa-list"></i> Ver lista</a>
        </div>
    </div>
    <div id="mapa" style="height:600px;width:100%"></div>
</div>

<!-- Leyenda dinámica generada por JS -->
<div id="leyenda" style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;font-size:.78rem;color:var(--text-muted)"></div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const clientes = <?= json_encode(array_map(fn($cl) => [
    'id'         => $cl['id'],
    'nombre'     => $cl['apellidos'] . ', ' . $cl['nombres'],
    'tel'        => $cl['telefono'],
    'zona'       => $cl['zona'],
    'cobrador'   => $cl['cobrador'],
    'cobrador_id'=> (int)$cl['cobrador_id'],
    'estado_cr'  => $cl['credito_estado'] ?? 'sin_credito',
    'lat'        => $cl['lat'],
    'lng'        => $cl['lng'],
], $clientes_mapa), JSON_UNESCAPED_UNICODE) ?>;

// Paleta de 12 colores distintos — uno por cobrador
const PALETA = [
    '#ef4444', // Rojo
    '#eab308', // Amarillo
    '#22c55e', // Verde
    '#3b82f6', // Azul
    '#f97316', // Naranja
    '#38bdf8', // Celeste
];

// Asignar color fijo a cada cobrador_id (orden de aparición en los datos)
const colorPorCobrador = {};
let palIdx = 0;
clientes.forEach(cl => {
    const key = cl.cobrador_id || 0;
    if (!(key in colorPorCobrador))
        colorPorCobrador[key] = PALETA[palIdx++ % PALETA.length];
});

// Leyenda dinámica
const leyendaHtml = Object.entries(colorPorCobrador).map(([id, color]) => {
    const nombre = clientes.find(d => (d.cobrador_id || 0) == id)?.cobrador || 'Sin cobrador';
    return `<span style="display:flex;align-items:center;gap:5px">
        <span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:${color};border:1.5px solid rgba(255,255,255,.35);flex-shrink:0"></span>
        ${nombre}
    </span>`;
}).join('');
document.getElementById('leyenda').innerHTML = leyendaHtml;

function makeIcon(color) {
    return L.divIcon({
        className: '',
        html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });
}

// Centro en Argentina (Tucumán aproximado)
const map = L.map('mapa').setView([-26.8, -65.2], 11);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
}).addTo(map);

let markers = [];

// Colores de estado del crédito para el popup (badge)
const ESTADO_COLOR = {
    'EN_CURSO':    '#10b981',
    'MOROSO':      '#ef4444',
    'sin_credito': '#6b7280',
};
const ESTADO_LABEL = {
    'EN_CURSO':    'En Curso',
    'MOROSO':      'Moroso',
    'sin_credito': 'Sin crédito activo',
};

function crearMarcadores(lista) {
    markers.forEach(m => map.removeLayer(m));
    markers = [];
    lista.forEach(cl => {
        const color = colorPorCobrador[cl.cobrador_id || 0] || '#64748b';
        const ecColor = ESTADO_COLOR[cl.estado_cr] || '#6b7280';
        const ecLabel = ESTADO_LABEL[cl.estado_cr] || cl.estado_cr;
        const m = L.marker([cl.lat, cl.lng], {icon: makeIcon(color)})
            .bindPopup(`
                <div style="min-width:190px;font-family:Arial,sans-serif;font-size:13px">
                    <strong><a href="../clientes/ver?id=${cl.id}" target="_blank">${cl.nombre}</a></strong><br>
                    <span style="color:#6b7280;font-size:11px">${cl.zona || '—'} · <span style="color:${color};font-weight:700">${cl.cobrador || '—'}</span></span><br><br>
                    <span style="padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;
                          background:${ecColor}22;color:${ecColor}">${ecLabel}</span>
                    ${cl.tel ? `<br><a href="https://wa.me/${cl.tel.replace(/\D/g,'')}">📱 ${cl.tel}</a>` : ''}
                </div>
            `);
        m.addTo(map);
        markers.push(m);
    });
}

function filtrarMarcadores() {
    const val = document.getElementById('filtro-cobrador').value;
    const lista = val ? clientes.filter(cl => cl.cobrador_id == val) : clientes;
    crearMarcadores(lista);
}

crearMarcadores(clientes);
if (clientes.length) {
    const grupo = L.featureGroup(markers);
    map.fitBounds(grupo.getBounds().pad(0.1));
}
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
