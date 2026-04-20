<?php
// clientes/mapa.php — Mapa de clientes con Leaflet.js
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();

// Solo clientes con coordenadas y crédito activo o moroso
$stmt = $pdo->query("
    SELECT c.id, c.nombres, c.apellidos, c.telefono, c.coordenadas, c.zona, c.estado,
           (SELECT cr.estado FROM ic_creditos cr WHERE cr.cliente_id=c.id AND cr.estado IN ('EN_CURSO','MOROSO') ORDER BY cr.fecha_alta DESC LIMIT 1) AS credito_estado,
           CONCAT(u.nombre,' ',u.apellido) AS cobrador
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.coordenadas IS NOT NULL AND c.coordenadas != ''
    ORDER BY c.apellidos
");
$clientes_raw = $stmt->fetchAll();

// Parsear coordenadas — formato "lat,lng" o URL de google maps ya resuelta
$clientes_mapa = [];
foreach ($clientes_raw as $cl) {
    if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', trim($cl['coordenadas']), $m)) {
        $cl['lat'] = (float)$m[1];
        $cl['lng'] = (float)$m[2];
        $clientes_mapa[] = $cl;
    }
}

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
            <select id="filtro-estado" onchange="filtrarMarcadores()" style="background:var(--dark-input);border:1px solid var(--dark-border);border-radius:6px;color:var(--text-main);padding:6px 10px;font-size:.82rem;font-family:inherit">
                <option value="">Todos los estados</option>
                <option value="EN_CURSO">En Curso</option>
                <option value="MOROSO">Moroso</option>
                <option value="sin_credito">Sin crédito activo</option>
            </select>
            <a href="index" class="btn-ic btn-ghost btn-sm"><i class="fa fa-list"></i> Ver lista</a>
        </div>
    </div>
    <div id="mapa" style="height:600px;width:100%"></div>
</div>

<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:.78rem;color:var(--text-muted)">
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;margin-right:4px"></span>En Curso</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;margin-right:4px"></span>Moroso</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#6b7280;margin-right:4px"></span>Sin crédito activo</span>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const clientes = <?= json_encode(array_map(fn($cl) => [
    'id'      => $cl['id'],
    'nombre'  => $cl['apellidos'] . ', ' . $cl['nombres'],
    'tel'     => $cl['telefono'],
    'zona'    => $cl['zona'],
    'cobrador'=> $cl['cobrador'],
    'estado_cr'=> $cl['credito_estado'] ?? 'sin_credito',
    'lat'     => $cl['lat'],
    'lng'     => $cl['lng'],
], $clientes_mapa), JSON_UNESCAPED_UNICODE) ?>;

const COLORES = {
    'EN_CURSO':    '#10b981',
    'MOROSO':      '#ef4444',
    'sin_credito': '#6b7280',
};

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

function crearMarcadores(lista) {
    markers.forEach(m => map.removeLayer(m));
    markers = [];
    lista.forEach(cl => {
        const color = COLORES[cl.estado_cr] || COLORES['sin_credito'];
        const m = L.marker([cl.lat, cl.lng], {icon: makeIcon(color)})
            .bindPopup(`
                <div style="min-width:180px;font-family:Arial,sans-serif;font-size:13px">
                    <strong><a href="../clientes/ver?id=${cl.id}" target="_blank">${cl.nombre}</a></strong><br>
                    <span style="color:#6b7280;font-size:11px">${cl.zona || '—'} · ${cl.cobrador || '—'}</span><br><br>
                    <span style="padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;
                          background:${color}22;color:${color}">${cl.estado_cr}</span>
                    ${cl.tel ? `<br><a href="https://wa.me/${cl.tel.replace(/\D/g,'')}">📱 ${cl.tel}</a>` : ''}
                </div>
            `);
        m._estadoCr = cl.estado_cr;
        m.addTo(map);
        markers.push(m);
    });
}

function filtrarMarcadores() {
    const filtro = document.getElementById('filtro-estado').value;
    const lista = filtro ? clientes.filter(cl => cl.estado_cr === filtro) : clientes;
    crearMarcadores(lista);
}

crearMarcadores(clientes);
if (clientes.length) {
    const grupo = L.featureGroup(markers);
    map.fitBounds(grupo.getBounds().pad(0.1));
}
</script>

<?php require_once __DIR__ . '/../views/layout_footer.php'; ?>
