<?php
// clientes/mapa_full.php — Mapa fullscreen sin layout
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/funciones.php';
verificar_sesion();
verificar_permiso('ver_clientes');

$pdo = obtener_conexion();

$stmt = $pdo->query("
    SELECT c.id, c.nombres, c.apellidos, c.telefono, c.coordenadas, c.zona,
           c.cobrador_id,
           (SELECT cr.estado FROM ic_creditos cr WHERE cr.cliente_id=c.id AND cr.estado IN ('EN_CURSO','MOROSO') ORDER BY cr.fecha_alta DESC LIMIT 1) AS credito_estado,
           CONCAT(u.nombre,' ',u.apellido) AS cobrador
    FROM ic_clientes c
    LEFT JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.coordenadas IS NOT NULL AND c.coordenadas != ''
    ORDER BY c.apellidos
");
$clientes_raw = $stmt->fetchAll();

$clientes_mapa = [];
foreach ($clientes_raw as $cl) {
    if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', trim($cl['coordenadas']), $m)) {
        $cl['lat'] = (float)$m[1];
        $cl['lng'] = (float)$m[2];
        $clientes_mapa[] = $cl;
    }
}

$cobradores_mapa = $pdo->query("
    SELECT DISTINCT u.id, CONCAT(u.nombre,' ',u.apellido) AS nombre,
           COUNT(c.id) AS total_clientes
    FROM ic_clientes c
    JOIN ic_usuarios u ON c.cobrador_id = u.id
    WHERE c.coordenadas IS NOT NULL AND c.coordenadas != ''
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY u.nombre
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mapa de Cobradores — Imperio Comercial</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { height: 100%; font-family: 'Segoe UI', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }

  #mapa { position: fixed; inset: 0; z-index: 1; }

  /* Panel flotante superior */
  #panel {
    position: fixed;
    top: 14px; left: 50%;
    transform: translateX(-50%);
    z-index: 10;
    background: rgba(15,23,42,.92);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    padding: 14px 18px;
    min-width: 320px;
    max-width: calc(100vw - 32px);
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
  }
  #panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    gap: 10px;
  }
  #panel-title {
    font-size: .85rem;
    font-weight: 700;
    color: #e2e8f0;
    display: flex;
    align-items: center;
    gap: 7px;
  }
  #contador-sel {
    font-size: .72rem;
    color: #94a3b8;
    margin-left: 4px;
    font-weight: 400;
  }
  #panel-btns { display: flex; gap: 6px; }
  .btn-panel {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.12);
    color: #cbd5e1;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: .72rem;
    cursor: pointer;
    transition: background .15s;
    font-family: inherit;
  }
  .btn-panel:hover { background: rgba(255,255,255,.14); }

  #lista-cobradores {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .cobrador-chip {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 12px;
    border: 1.5px solid rgba(255,255,255,.15);
    border-radius: 20px;
    cursor: pointer;
    user-select: none;
    transition: all .15s;
    font-size: .8rem;
    color: #cbd5e1;
    white-space: nowrap;
  }
  .cobrador-chip:hover { background: rgba(255,255,255,.06); }
  .chip-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,.3);
  }
  .chip-count {
    font-size: .68rem;
    color: #64748b;
  }

  #aviso-max {
    display: none;
    margin-top: 10px;
    font-size: .75rem;
    color: #f59e0b;
  }

  /* Leyenda inferior */
  #leyenda {
    position: fixed;
    bottom: 24px; left: 50%;
    transform: translateX(-50%);
    z-index: 10;
    background: rgba(15,23,42,.88);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 10px;
    padding: 10px 16px;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    justify-content: center;
    font-size: .75rem;
    color: #94a3b8;
    max-width: calc(100vw - 32px);
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
  }

  /* Info cruces */
  #info-cruces {
    display: none;
    position: fixed;
    bottom: 80px; right: 16px;
    z-index: 10;
    background: rgba(15,23,42,.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(99,102,241,.3);
    border-radius: 10px;
    padding: 10px 14px;
    font-size: .75rem;
    color: #94a3b8;
    max-width: 260px;
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
  }

  /* Botón cerrar */
  #btn-cerrar {
    position: fixed;
    top: 14px; right: 16px;
    z-index: 10;
    background: rgba(15,23,42,.88);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    padding: 8px 12px;
    color: #94a3b8;
    font-size: .8rem;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background .15s;
  }
  #btn-cerrar:hover { background: rgba(255,255,255,.08); color: #e2e8f0; }
</style>
</head>
<body>

<div id="mapa"></div>

<!-- Panel de cobradores -->
<div id="panel">
    <div id="panel-header">
        <div id="panel-title">
            <i class="fa fa-map-location-dot" style="color:#6366f1"></i>
            Cobradores
            <span id="contador-sel">0 de 4</span>
        </div>
        <div id="panel-btns">
            <button class="btn-panel" onclick="seleccionarTodos()">Ver todos</button>
            <button class="btn-panel" onclick="limpiarSeleccion()">Limpiar</button>
        </div>
    </div>
    <div id="lista-cobradores">
        <?php foreach ($cobradores_mapa as $cob): ?>
        <div class="cobrador-chip" data-id="<?= $cob['id'] ?>" onclick="toggleCobrador(<?= $cob['id'] ?>, this)">
            <span class="chip-dot"></span>
            <span><?= e($cob['nombre']) ?></span>
            <span class="chip-count"><?= $cob['total_clientes'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div id="aviso-max">
        <i class="fa fa-triangle-exclamation"></i> Máximo 4 cobradores. Deseleccioná uno primero.
    </div>
</div>

<!-- Botón cerrar / volver -->
<a id="btn-cerrar" href="mapa">
    <i class="fa fa-arrow-left"></i> Volver
</a>

<!-- Leyenda -->
<div id="leyenda"></div>

<!-- Info cruces -->
<div id="info-cruces">
    <i class="fa fa-circle-info" style="color:#6366f1"></i>
    <strong style="color:#e2e8f0"> Zonas de cruce:</strong>
    Las áreas superpuestas indican cobradores que visitan la misma zona.
</div>

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

const PALETA = ['#ef4444','#eab308','#22c55e','#3b82f6','#f97316','#38bdf8'];

const colorPorCobrador = {};
let palIdx = 0;
clientes.forEach(cl => {
    const key = cl.cobrador_id || 0;
    if (!(key in colorPorCobrador))
        colorPorCobrador[key] = PALETA[palIdx++ % PALETA.length];
});

// Aplicar colores a chips
document.querySelectorAll('.cobrador-chip').forEach(chip => {
    const id = parseInt(chip.dataset.id);
    const color = colorPorCobrador[id] || '#64748b';
    chip.querySelector('.chip-dot').style.background = color;
    chip.querySelector('.chip-dot').style.borderColor = color + '88';
    chip.dataset.color = color;
});

// ── Mapa ─────────────────────────────────────────────────
const map = L.map('mapa', { zoomControl: true }).setView([-26.8, -65.2], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19,
}).addTo(map);

let markers = [], polygons = [], seleccionados = [];

const ESTADO_COLOR = { 'EN_CURSO':'#10b981','MOROSO':'#ef4444','sin_credito':'#6b7280' };
const ESTADO_LABEL = { 'EN_CURSO':'En Curso','MOROSO':'Moroso','sin_credito':'Sin crédito' };

function makeIcon(color) {
    return L.divIcon({
        className: '',
        html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.5)"></div>`,
        iconSize: [14,14], iconAnchor: [7,7],
    });
}

function convexHull(pts) {
    if (pts.length < 3) return pts;
    const s = [...pts].sort((a,b) => a[0]-b[0]||a[1]-b[1]);
    const cross = (o,a,b) => (a[0]-o[0])*(b[1]-o[1])-(a[1]-o[1])*(b[0]-o[0]);
    const lo=[], hi=[];
    for (const p of s) {
        while (lo.length>=2 && cross(lo.at(-2),lo.at(-1),p)<=0) lo.pop();
        lo.push(p);
    }
    for (let i=s.length-1;i>=0;i--) {
        const p=s[i];
        while (hi.length>=2 && cross(hi.at(-2),hi.at(-1),p)<=0) hi.pop();
        hi.push(p);
    }
    hi.pop(); lo.pop();
    return lo.concat(hi);
}

function renderizar() {
    markers.forEach(m => map.removeLayer(m));
    polygons.forEach(p => map.removeLayer(p));
    markers = []; polygons = [];

    const lista = seleccionados.length
        ? clientes.filter(cl => seleccionados.includes(cl.cobrador_id))
        : clientes;

    lista.forEach(cl => {
        const color   = colorPorCobrador[cl.cobrador_id||0] || '#64748b';
        const ecColor = ESTADO_COLOR[cl.estado_cr] || '#6b7280';
        const ecLabel = ESTADO_LABEL[cl.estado_cr] || cl.estado_cr;
        const m = L.marker([cl.lat, cl.lng], { icon: makeIcon(color) })
            .bindPopup(`
                <div style="min-width:190px;font-family:Arial,sans-serif;font-size:13px">
                    <strong><a href="../clientes/ver?id=${cl.id}" target="_blank">${cl.nombre}</a></strong><br>
                    <span style="color:#6b7280;font-size:11px">${cl.zona||'—'} · <span style="color:${color};font-weight:700">${cl.cobrador||'—'}</span></span><br><br>
                    <span style="padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;background:${ecColor}22;color:${ecColor}">${ecLabel}</span>
                    ${cl.tel ? `<br><a href="https://wa.me/${cl.tel.replace(/\D/g,'')}">📱 ${cl.tel}</a>` : ''}
                </div>
            `);
        m.addTo(map);
        markers.push(m);
    });

    // Polígonos en modo comparativa
    if (seleccionados.length >= 2) {
        seleccionados.forEach(cobId => {
            const pts = clientes.filter(cl => cl.cobrador_id===cobId).map(cl=>[cl.lat,cl.lng]);
            if (pts.length < 2) return;
            const color = colorPorCobrador[cobId] || '#64748b';
            if (pts.length === 2) {
                polygons.push(L.polyline(pts,{color,weight:2,opacity:.6}).addTo(map));
            } else {
                polygons.push(L.polygon(convexHull(pts),{
                    color, weight:2, opacity:.85,
                    fillColor:color, fillOpacity:.13,
                }).addTo(map));
            }
        });
        document.getElementById('info-cruces').style.display = 'block';
    } else {
        document.getElementById('info-cruces').style.display = 'none';
    }

    // Leyenda
    const vis = seleccionados.length ? seleccionados : Object.keys(colorPorCobrador).map(Number);
    document.getElementById('leyenda').innerHTML = vis.map(id => {
        const color  = colorPorCobrador[id] || '#64748b';
        const nombre = clientes.find(d => d.cobrador_id==id)?.cobrador || 'Sin cobrador';
        return `<span style="display:flex;align-items:center;gap:5px">
            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};border:1.5px solid rgba(255,255,255,.3)"></span>
            ${nombre}
        </span>`;
    }).join('');

    if (markers.length) map.fitBounds(L.featureGroup(markers).getBounds().pad(0.1));
}

function toggleCobrador(id, chip) {
    const idx = seleccionados.indexOf(id);
    if (idx !== -1) {
        seleccionados.splice(idx, 1);
        chip.style.borderColor = 'rgba(255,255,255,.15)';
        chip.style.background  = 'transparent';
        chip.style.color       = '#cbd5e1';
    } else {
        if (seleccionados.length >= 4) {
            const av = document.getElementById('aviso-max');
            av.style.display = 'block';
            setTimeout(() => av.style.display='none', 3000);
            return;
        }
        seleccionados.push(id);
        const color = colorPorCobrador[id] || '#64748b';
        chip.style.borderColor = color;
        chip.style.background  = color + '22';
        chip.style.color       = '#fff';
    }
    document.getElementById('contador-sel').textContent = seleccionados.length + ' de 4';
    renderizar();
}

function seleccionarTodos() {
    seleccionados = [];
    document.querySelectorAll('.cobrador-chip').forEach(c => {
        c.style.borderColor='rgba(255,255,255,.15)';
        c.style.background='transparent';
        c.style.color='#cbd5e1';
    });
    document.getElementById('contador-sel').textContent = '0 de 4';
    renderizar();
}

function limpiarSeleccion() { seleccionarTodos(); }

renderizar();
</script>
</body>
</html>
