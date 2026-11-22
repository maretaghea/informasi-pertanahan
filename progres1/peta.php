<?php
/**
 * SIP KLETERAN - Peta Informasi Pertanahan
 * Jalankan melalui XAMPP: http://localhost/nama-folder/peta_kleteran.php
 *
 * PENTING: nama layer di bawah harus sama dengan kolom "Name" pada
 * GeoServer > Data > Layers (bukan hanya judul/Title layer).
 */
$geoserverWms = 'http://localhost:8080/geoserver/PROGRES1/wms';
$geoserverWfs = 'http://localhost:8080/geoserver/PROGRES1/ows';
$layerPL  = 'PROGRES1:pl kleteran';
$layerZNT = 'PROGRES1:znt kleteran';

/*
 * Proxy GetFeatureInfo melalui PHP agar permintaan atribut tidak terhalang
 * CORS browser. Tile WMS tetap ditampilkan langsung dari GeoServer.
 */
if (isset($_GET['proxy']) && $_GET['proxy'] === 'featureinfo') {
    $params = $_GET;
    unset($params['proxy']);
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $url = $geoserverWms . '?' . $query;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            http_response_code($httpCode >= 400 ? $httpCode : 502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Permintaan GetFeatureInfo ke GeoServer gagal.', 'detail' => $curlError ?: ('HTTP ' . $httpCode)]);
            exit;
        }
    } else {
        $context = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'PHP tidak dapat menghubungi GeoServer. Periksa GeoServer dan ekstensi cURL PHP.']);
            exit;
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo $body;
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistem Informasi Pertanahan | Desa Kleteran</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
<style>
:root{--green-dark:#214d38;--green:#347653;--green-pale:#edf3ee;--gold:#d6a33a;--ink:#29332d;--muted:#718078;--line:#dfe6e0;--paper:#fff;--bg:#f1f4f1}
*{box-sizing:border-box}html,body{height:100%;margin:0}body{font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);background:var(--bg);display:flex;flex-direction:column;overflow:hidden}
header{height:68px;flex:0 0 68px;background:var(--green-dark);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 22px;border-bottom:3px solid var(--gold)}
.brand{display:flex;align-items:center;gap:12px}.brand-mark{width:36px;height:36px;border:1px solid rgba(255,255,255,.45);border-radius:7px;display:grid;place-items:center;font-size:19px}.brand h1{font-size:17px;letter-spacing:.2px;margin:0 0 3px;font-weight:650}.brand p{font-size:11px;margin:0;color:#dce8df}.head-note{font-size:11px;color:#e1ebe4;text-align:right;line-height:1.5}
.app{display:grid;grid-template-columns:265px minmax(300px,1fr) 285px;gap:0;min-height:0;flex:1}.side{background:var(--paper);overflow-y:auto;padding:15px 14px;border-right:1px solid var(--line)}.side.right{border-right:0;border-left:1px solid var(--line)}
.section{margin-bottom:17px}.section-title{font-size:11px;font-weight:700;letter-spacing:.7px;color:#64746a;text-transform:uppercase;margin:0 0 10px;display:flex;align-items:center;justify-content:space-between}.section-title:after{content:"";height:1px;background:var(--line);flex:1;margin-left:9px}
.field-label{font-size:11px;color:#5f6e64;display:block;margin:0 0 5px}.input,.select{width:100%;height:34px;border:1px solid #d5ded7;border-radius:5px;padding:0 9px;font:12px "Segoe UI",Arial;background:#fff;color:var(--ink);outline:none}.input:focus,.select:focus{border-color:var(--green)}.field-row{display:flex;gap:6px}.field-row .input{min-width:0;flex:1}
.btn{border:1px solid #cbd8ce;background:#fff;color:var(--green-dark);border-radius:5px;padding:7px 10px;font-size:11px;cursor:pointer;font-weight:600}.btn:hover{background:var(--green-pale)}.btn.primary{background:var(--green);border-color:var(--green);color:#fff}.btn.primary:hover{background:var(--green-dark)}.btn.block{width:100%;margin-top:7px}.btn-row{display:flex;gap:6px}.btn-row .btn{flex:1}.help{font-size:10px;color:var(--muted);line-height:1.5;margin:6px 0 0}.file-input{font-size:11px;width:100%;color:#68766d}.file-box{border:1px dashed #cbd7ce;border-radius:6px;padding:10px;background:#fafcf9}
#map-wrap{position:relative;min-width:0;min-height:0;background:#e8ece8}#map{width:100%;height:100%;background:#e8ece8}.map-status{position:absolute;z-index:500;left:12px;top:12px;background:rgba(255,255,255,.95);border:1px solid #dce4dd;border-radius:5px;padding:7px 10px;font-size:11px;box-shadow:0 1px 4px #00000012;max-width:calc(100% - 24px)}.status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#c49a37;margin-right:6px}.status-dot.ok{background:#31945a}.status-dot.error{background:#c84b43}
.layer-line{display:flex;align-items:center;gap:8px;margin:8px 0;font-size:12px}.layer-line input{accent-color:var(--green)}.layer-line span{flex:1}.tag{font-size:9px;color:#748178;background:#f0f3f0;padding:3px 5px;border-radius:3px}.range-line{display:flex;align-items:center;gap:8px;font-size:11px;color:#59685e}.range-line input{flex:1;accent-color:var(--green)}.range-value{width:30px;text-align:right;font-variant-numeric:tabular-nums}
.basemap-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}.base-choice{border:1px solid var(--line);border-radius:5px;padding:8px 6px;text-align:center;font-size:11px;cursor:pointer;background:#fff;color:#526057}.base-choice.active{border-color:var(--green);background:var(--green-pale);color:var(--green-dark);font-weight:700}
.legend-key{display:flex;align-items:center;gap:8px;font-size:11px;margin:7px 0;color:#56645b}.swatch{width:23px;height:13px;border:1px solid #777;background:#a6b5a9;border-radius:2px}.swatch.znt{background:#e5c77d}.mini-note{background:#f7f9f7;border-left:3px solid var(--gold);padding:8px;font-size:10px;color:#69766d;line-height:1.5;margin-top:8px}
.calc-result{background:#f3f7f3;border:1px solid #dce8de;border-radius:5px;padding:10px;margin-top:9px}.calc-result small{display:block;color:#6a796e;font-size:10px}.calc-result strong{display:block;color:var(--green-dark);font-size:18px;margin-top:3px;font-weight:700}.calc-result .formula{font-size:10px;color:#77847b;margin-top:4px}
.info-box{font-size:11px;line-height:1.55;color:#5e6c62;overflow-wrap:anywhere}.info-box table{width:100%;border-collapse:collapse}.info-box td{padding:5px 3px;border-bottom:1px solid #edf0ed;vertical-align:top}.info-box td:first-child{color:#718077;width:42%}.map-footer{height:25px;flex:0 0 25px;background:#fff;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 12px;font-size:10px;color:#78847c}
.coords{background:rgba(255,255,255,.94);padding:4px 7px;border-radius:4px;font:10px Consolas,monospace;box-shadow:0 1px 4px #0002}.leaflet-control-layers{font-size:11px}.leaflet-popup-content{margin:10px 12px}.popup-title{font-weight:700;color:var(--green-dark);border-bottom:2px solid var(--gold);padding-bottom:5px;margin-bottom:5px;font-size:12px}
@media(max-width:1000px){.app{grid-template-columns:235px minmax(250px,1fr)}.side.right{grid-column:1/-1;border-left:0;border-top:1px solid var(--line);max-height:230px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.side.right .section{margin-bottom:5px}}
@media(max-width:650px){body{overflow:auto}header{height:auto;min-height:64px;padding:10px 13px}.head-note{display:none}.app{display:flex;flex-direction:column;overflow:visible}.side{border-right:0;border-bottom:1px solid var(--line);overflow:visible}.side.left{display:grid;grid-template-columns:1fr 1fr;gap:12px}.side.left .section{margin-bottom:5px}#map-wrap{height:55vh;min-height:360px;order:2}.side.right{order:3;display:grid;grid-template-columns:1fr 1fr;max-height:none}.map-footer{font-size:9px}}
</style>
</head>
<body>
<header>
  <div class="brand"><div class="brand-mark">⌖</div><div><h1>Sistem Informasi Pertanahan</h1><p>Desa Kleteran · Kecamatan Grabag · Kabupaten Magelang</p></div></div>
  <div class="head-note">PETA TEMATIK PERTANAHAN<br>Penggunaan Lahan & Zona Nilai Tanah</div>
</header>
<div class="app">
  <aside class="side left">
    <section class="section">
      <h2 class="section-title">Pencarian bidang</h2>
      <label class="field-label" for="searchText">Kata kunci / nama pemilik / ID bidang</label>
      <div class="field-row"><input class="input" id="searchText" placeholder="Masukkan kata kunci..."><button class="btn primary" id="searchBtn">Cari</button></div>
      <p class="help">Pencarian atribut menggunakan data WFS GeoServer. Pastikan layanan WFS aktif.</p>
      <div id="searchResults" class="info-box" style="margin-top:8px"></div>
    </section>
    <section class="section">
      <h2 class="section-title">Impor data spasial</h2>
      <div class="file-box"><input class="file-input" type="file" id="spatialFile" accept=".geojson,.json,.kml,.zip"><p class="help">Format: GeoJSON, JSON, KML, dan ZIP Shapefile. Untuk ZIP, sertakan .shp, .shx, .dbf, dan .prj. Data impor hanya ditampilkan sementara di browser.</p></div>
      <button class="btn block" id="clearImport">Hapus data impor</button>
    </section>
    <section class="section">
      <h2 class="section-title">Digitasi & pengukuran</h2>
      <div class="btn-row"><button class="btn" id="drawPolygon">Gambar bidang</button><button class="btn" id="drawLine">Gambar garis</button></div>
      <div class="btn-row" style="margin-top:6px"><button class="btn" id="drawPoint">Tambah titik</button><button class="btn" id="deleteDraw">Hapus gambar</button></div>
      <div class="mini-note">Gunakan alat gambar pada peta untuk membuat objek. Luas bidang dan panjang garis ditampilkan setelah gambar selesai.</div>
      <div id="measureInfo" class="info-box" style="margin-top:7px">Belum ada objek hasil digitasi.</div>
    </section>
  </aside>

  <main id="map-wrap">
    <div id="map"></div>
    <div class="map-status"><span class="status-dot" id="statusDot"></span><span id="statusText">Menghubungkan ke GeoServer...</span></div>
  </main>

  <aside class="side right">
    <section class="section">
      <h2 class="section-title">Tampilan peta</h2>
      <div class="basemap-grid">
        <button class="base-choice active" data-base="osm">Jalan</button><button class="base-choice" data-base="topo">Topografi</button>
        <button class="base-choice" data-base="satellite">Satelit</button><button class="base-choice" data-base="light">Terang</button>
      </div>
    </section>
    <section class="section">
      <h2 class="section-title">Pengaturan layer</h2>
      <label class="layer-line"><input type="checkbox" id="showPL" checked><span>Penggunaan Lahan</span><i class="tag">PL</i></label>
      <label class="layer-line"><input type="checkbox" id="showZNT"><span>Zona Nilai Tanah</span><i class="tag">ZNT</i></label>
      <label class="field-label" for="opacityPL">Transparansi penggunaan lahan</label><div class="range-line"><input type="range" id="opacityPL" min="0" max="1" step="0.05" value="0.85"><span class="range-value" id="opacityPLVal">85%</span></div>
      <label class="field-label" for="opacityZNT" style="margin-top:9px">Transparansi zona nilai tanah</label><div class="range-line"><input type="range" id="opacityZNT" min="0" max="1" step="0.05" value="0.65"><span class="range-value" id="opacityZNTVal">65%</span></div>
      <button class="btn block" id="zoomAll">Tampilkan seluruh bidang</button>
    </section>
    <section class="section">
      <h2 class="section-title">Legenda</h2>
      <div class="legend-key"><span class="swatch"></span> Penggunaan lahan</div><div class="legend-key"><span class="swatch znt"></span> Zona nilai tanah</div>
      <p class="help">Simbol dan warna mengikuti style yang diatur pada GeoServer.</p>
    </section>
    <section class="section">
      <h2 class="section-title">Estimasi nilai tanah</h2>
      <label class="field-label" for="areaInput">Luas bidang (m²)</label><input class="input" type="number" min="0" id="areaInput" placeholder="Contoh: 250">
      <label class="field-label" for="priceInput" style="margin-top:8px">Harga tanah per m² (Rp)</label><input class="input" type="number" min="0" id="priceInput" placeholder="Masukkan harga ZNT">
      <div class="calc-result"><small>Perkiraan nilai tanah</small><strong id="totalValue">Rp 0</strong><div class="formula">Luas bidang × harga tanah per m²</div></div>
      <p class="help">Masukkan luas dan harga dari atribut ZNT untuk memperoleh estimasi. Hasil bukan penilaian resmi.</p>
    </section>
    <section class="section">
      <h2 class="section-title">Informasi objek</h2><div id="featureInfo" class="info-box">Klik salah satu bidang pada peta untuk melihat atribut yang tersedia.</div>
    </section>
  </aside>
</div>
<div class="map-footer"><span>Sumber: GeoServer · Data tematik Desa Kleteran</span><span id="coordinateText">Koordinat: -</span></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="https://unpkg.com/@tmcw/togeojson@5.8.1/dist/togeojson.umd.js"></script>\n<script src="https://unpkg.com/shpjs@latest/dist/shp.js"></script>
<script>
const WMS_URL = <?php echo json_encode($geoserverWms); ?>;
const WFS_URL = <?php echo json_encode($geoserverWfs); ?>;
const LAYER_PL = <?php echo json_encode($layerPL); ?>;
const LAYER_ZNT = <?php echo json_encode($layerZNT); ?>;
// Batas geografis diambil dari Lat/Lon Bounding Box layer ZNT pada GeoServer.
const DATA_BOUNDS = L.latLngBounds(
  [-7.382311349854716, 110.32830087640826],
  [-7.3621106558689755, 110.3444938321317]
);
const map = L.map('map',{zoomControl:true}).fitBounds(DATA_BOUNDS, {padding:[35,35]});
const baseMaps={
 osm:L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}),
 topo:L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',{maxZoom:17,attribution:'&copy; OpenTopoMap'}),
 satellite:L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:'Tiles &copy; Esri'}),
 light:L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',{subdomains:'abcd',maxZoom:20,attribution:'&copy; OpenStreetMap &copy; CARTO'})
};
baseMaps.osm.addTo(map);
const plLayer=L.tileLayer.wms(WMS_URL,{layers:LAYER_PL,format:'image/png',transparent:true,version:'1.1.1',opacity:.85});
const zntLayer=L.tileLayer.wms(WMS_URL,{layers:LAYER_ZNT,format:'image/png',transparent:true,version:'1.1.1',opacity:.65});
plLayer.addTo(map);
const drawnItems=new L.FeatureGroup().addTo(map);
const importedItems=L.geoJSON(null,{style:{color:'#b46a26',weight:2,fillColor:'#e6b45b',fillOpacity:.22},pointToLayer:(f,ll)=>L.circleMarker(ll,{radius:6,color:'#9a5b22',fillColor:'#e6b45b',fillOpacity:.9})}).addTo(map);
L.control.scale({metric:true,imperial:false,position:'bottomleft'}).addTo(map);
const drawControl=new L.Control.Draw({draw:{polygon:{allowIntersection:false,showArea:true},polyline:true,rectangle:true,circle:false,circlemarker:false,marker:true},edit:{featureGroup:drawnItems}});
map.addControl(drawControl);

function setStatus(message,type='loading'){document.getElementById('statusText').textContent=message;document.getElementById('statusDot').className='status-dot '+(type==='ok'?'ok':type==='error'?'error':'');}
function esc(v){return String(v??'-').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function setLayerVisibility(layer,visible){if(visible){if(!map.hasLayer(layer))layer.addTo(map);}else if(map.hasLayer(layer))map.removeLayer(layer);}
document.getElementById('showPL').addEventListener('change',e=>setLayerVisibility(plLayer,e.target.checked));
document.getElementById('showZNT').addEventListener('change',e=>setLayerVisibility(zntLayer,e.target.checked));
[['opacityPL',plLayer,'opacityPLVal'],['opacityZNT',zntLayer,'opacityZNTVal']].forEach(([id,layer,label])=>document.getElementById(id).addEventListener('input',e=>{layer.setOpacity(+e.target.value);document.getElementById(label).textContent=Math.round(+e.target.value*100)+'%';}));
document.querySelectorAll('.base-choice').forEach(btn=>btn.addEventListener('click',()=>{Object.values(baseMaps).forEach(l=>map.removeLayer(l));baseMaps[btn.dataset.base].addTo(map);document.querySelectorAll('.base-choice').forEach(b=>b.classList.toggle('active',b===btn));}));
document.getElementById('zoomAll').addEventListener('click',()=>{
  const overlays=[];
  if(drawnItems.getLayers().length) overlays.push(drawnItems);
  if(importedItems.getLayers().length) overlays.push(importedItems);
  if(overlays.length){
    const group=L.featureGroup(overlays);
    if(group.getBounds().isValid()) map.fitBounds(group.getBounds(),{padding:[25,25],maxZoom:18});
    else map.fitBounds(DATA_BOUNDS,{padding:[35,35]});
  } else {
    map.fitBounds(DATA_BOUNDS,{padding:[35,35]});
  }
});

// Status berdasarkan respons tile WMS, bukan fetch GetCapabilities (yang dapat terhalang CORS).
let loadedWmsTiles = 0;
function attachWmsStatus(layer, label) {
  layer.on('loading', () => setStatus('Memuat layer ' + label + ' dari GeoServer...', 'loading'));
  layer.on('tileload', () => {
    loadedWmsTiles++;
    setStatus('Layer ' + label + ' berhasil ditampilkan.', 'ok');
  });
  layer.on('tileerror', (event) => {
    console.error('WMS tile error (' + label + '):', event);
    setStatus('Gagal memuat ' + label + '. Periksa permintaan WMS pada Network browser.', 'error');
  });
}
attachWmsStatus(plLayer, 'Penggunaan Lahan');
attachWmsStatus(zntLayer, 'Zona Nilai Tanah');
map.fitBounds(DATA_BOUNDS,{padding:[35,35]});

// Impor GeoJSON/JSON/KML/ZIP Shapefile untuk tampilan sementara di browser.
document.getElementById('spatialFile').addEventListener('change', async e => {
  const file = e.target.files[0];
  if (!file) return;

  try {
    const filename = file.name.toLowerCase();
    let geojson;

    if (filename.endsWith('.zip')) {
      if (typeof shp !== 'function') {
        throw new Error('Pustaka pembaca ZIP Shapefile belum termuat. Periksa koneksi internet atau CDN shpjs.');
      }

      // ZIP Shapefile harus berisi pasangan .shp, .shx, .dbf dan sebaiknya .prj.
      const result = await shp(await file.arrayBuffer());

      // shpjs dapat mengembalikan satu FeatureCollection atau beberapa layer.
      if (result && result.type === 'FeatureCollection') {
        geojson = result;
      } else if (Array.isArray(result)) {
        geojson = {
          type: 'FeatureCollection',
          features: result.flatMap(item => item && item.features ? item.features : [])
        };
      } else if (result && typeof result === 'object') {
        const collections = Object.values(result).filter(item => item && item.type === 'FeatureCollection');
        geojson = {
          type: 'FeatureCollection',
          features: collections.flatMap(item => item.features || [])
        };
      }
    } else {
      const raw = await file.text();
      if (filename.endsWith('.kml')) {
        const xml = new DOMParser().parseFromString(raw, 'text/xml');
        if (xml.querySelector('parsererror')) throw new Error('Struktur KML tidak valid.');
        geojson = toGeoJSON.kml(xml);
      } else {
        geojson = JSON.parse(raw);
      }
    }

    if (!geojson || !Array.isArray(geojson.features)) {
      throw new Error('Tidak ditemukan fitur spasial. Periksa isi file dan struktur ZIP.');
    }

    importedItems.clearLayers();
    importedItems.addData(geojson);

    if (importedItems.getLayers().length) {
      const bounds = importedItems.getBounds();
      if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20] });
      setStatus('Impor berhasil: ' + importedItems.getLayers().length + ' fitur ditampilkan sementara.', 'ok');
    } else {
      throw new Error('File terbaca, tetapi tidak memiliki fitur untuk ditampilkan.');
    }
  } catch (err) {
    console.error('Impor data spasial gagal:', err);
    setStatus('Impor data gagal. Periksa format file.', 'error');
    alert('Data tidak dapat diimpor. ' + err.message +
      '\\n\\nUntuk ZIP Shapefile, pastikan file .shp, .shx, .dbf, dan .prj berada langsung di dalam ZIP.');
  }
});
document.getElementById('clearImport').addEventListener('click', () => {
  importedItems.clearLayers();
  document.getElementById('spatialFile').value = '';
  setStatus('Data impor telah dihapus.', 'ok');
});

// Digitasi dan pengukuran.
function startDraw(type){let tool;if(type==='polygon')tool=new L.Draw.Polygon(map,drawControl.options.draw.polygon);if(type==='polyline')tool=new L.Draw.Polyline(map,drawControl.options.draw.polyline);if(type==='marker')tool=new L.Draw.Marker(map,drawControl.options.draw.marker);tool.enable();}
document.getElementById('drawPolygon').onclick=()=>startDraw('polygon');document.getElementById('drawLine').onclick=()=>startDraw('polyline');document.getElementById('drawPoint').onclick=()=>startDraw('marker');
document.getElementById('deleteDraw').onclick=()=>{drawnItems.clearLayers();document.getElementById('measureInfo').textContent='Objek digitasi telah dihapus.';};
map.on(L.Draw.Event.CREATED,e=>{drawnItems.addLayer(e.layer);let result='Objek berhasil dibuat.';if(e.layer instanceof L.Polygon){const area=L.GeometryUtil.geodesicArea(e.layer.getLatLngs()[0]);result='Luas bidang: '+(area>=10000?(area/10000).toFixed(4)+' ha':area.toFixed(2)+' m²');}else if(e.layer instanceof L.Polyline){const latlngs=e.layer.getLatLngs();let length=0;for(let i=1;i<latlngs.length;i++)length+=latlngs[i-1].distanceTo(latlngs[i]);result='Panjang garis: '+(length>=1000?(length/1000).toFixed(3)+' km':length.toFixed(2)+' m');}document.getElementById('measureInfo').textContent=result;});

// Kalkulator nilai tanah.
function calculate(){const area=Number(document.getElementById('areaInput').value)||0;const price=Number(document.getElementById('priceInput').value)||0;document.getElementById('totalValue').textContent=new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(area*price);}
document.getElementById('areaInput').addEventListener('input',calculate);document.getElementById('priceInput').addEventListener('input',calculate);

// GetFeatureInfo: menampilkan atribut objek pada lokasi klik.
function getInfoUrl(latlng,layer){
  const size=map.getSize();
  const bounds=map.getBounds();
  const point=map.latLngToContainerPoint(latlng).round();

  // Leaflet memakai EPSG:3857 untuk peta web. BBOX GetFeatureInfo
  // harus memakai CRS yang sama dengan koordinat piksel peta.
  const nw=map.options.crs.project(bounds.getNorthWest());
  const se=map.options.crs.project(bounds.getSouthEast());
  const bbox=[nw.x,se.y,se.x,nw.y].join(',');

  const params=new URLSearchParams({
    proxy:'featureinfo',
    service:'WMS',
    version:'1.1.1',
    request:'GetFeatureInfo',
    layers:layer,
    query_layers:layer,
    styles:'',
    srs:'EPSG:3857',
    bbox:bbox,
    width:size.x,
    height:size.y,
    x:point.x,
    y:point.y,
    info_format:'application/json',
    feature_count:5
  });
  return window.location.pathname+'?'+params.toString();
}

map.on('click',async e=>{
  const active=[];
  if(document.getElementById('showPL').checked)active.push(['Penggunaan Lahan',LAYER_PL]);
  if(document.getElementById('showZNT').checked)active.push(['Zona Nilai Tanah',LAYER_ZNT]);
  if(!active.length)return;

  const box=document.getElementById('featureInfo');
  box.textContent='Mengambil informasi objek...';
  let html='';
  let errors=[];

  for(const [label,layer] of active){
    try{
      const response=await fetch(getInfoUrl(e.latlng,layer));
      const raw=await response.text();
      if(!response.ok)throw new Error('HTTP '+response.status+' - '+raw.slice(0,250));
      let data;
      try{data=JSON.parse(raw);}catch(parseError){throw new Error('Respons GeoServer bukan JSON: '+raw.slice(0,250));}

      if(data.features&&data.features.length){
        data.features.forEach((feature,index)=>{
          const props=feature.properties||{};
          html+='<div class="popup-title">'+esc(label)+(data.features.length>1?' ('+(index+1)+')':'')+'</div><table>'+
            Object.entries(props).filter(([key])=>key.toLowerCase()!=='bbox')
              .map(([key,value])=>'<tr><td>'+esc(key.replace(/_/g,' '))+'</td><td>'+esc(value===null||value===''?'-':value)+'</td></tr>').join('')+
            '</table>';
        });
      }
    }catch(error){
      console.error('GetFeatureInfo '+label+':',error);
      errors.push(label+': '+error.message);
    }
  }

  if(html){
    box.innerHTML=html;
  }else if(errors.length){
    box.textContent='Atribut gagal diambil. '+errors.join(' | ');
  }else{
    box.textContent='Tidak ada objek pada titik yang diklik. Coba klik tepat di bagian dalam bidang dan pastikan layer terkait aktif.';
  }
});

// Pencarian atribut via WFS. Pencarian dilakukan pada fitur yang dikembalikan GeoServer.
async function searchLayer(layer,query,label){const url=WFS_URL+'?'+new URLSearchParams({service:'WFS',version:'1.1.0',request:'GetFeature',typeName:layer,outputFormat:'application/json',srsName:'EPSG:4326',maxFeatures:'1000'});const response=await fetch(url);if(!response.ok)throw new Error('WFS HTTP '+response.status);const data=await response.json();return (data.features||[]).filter(f=>Object.values(f.properties||{}).some(v=>String(v??'').toLowerCase().includes(query))).map(f=>({feature:f,label}));}
document.getElementById('searchBtn').addEventListener('click',async()=>{const q=document.getElementById('searchText').value.trim().toLowerCase();const out=document.getElementById('searchResults');if(!q){out.textContent='Masukkan kata kunci terlebih dahulu.';return;}out.textContent='Sedang mencari...';try{const jobs=[];if(document.getElementById('showPL').checked)jobs.push(searchLayer(LAYER_PL,q,'Penggunaan Lahan'));if(document.getElementById('showZNT').checked)jobs.push(searchLayer(LAYER_ZNT,q,'Zona Nilai Tanah'));const results=(await Promise.all(jobs)).flat().slice(0,30);if(!results.length){out.textContent='Data tidak ditemukan. Periksa ejaan atau aktifkan layer yang ingin dicari.';return;}out.innerHTML=results.map((r,i)=>{const props=r.feature.properties||{};const title=Object.entries(props).find(([k,v])=>v!==null&&String(v).trim()!=='')?.[1]||('Hasil '+(i+1));return '<div style="padding:7px 0;border-bottom:1px solid #edf0ed"><strong>'+esc(title)+'</strong><br><span style="color:#7b887f;font-size:10px">'+esc(r.label)+'</span><br><button class="btn" style="margin-top:4px" data-result="'+i+'">Lihat pada peta</button></div>';}).join('');out.querySelectorAll('[data-result]').forEach(btn=>btn.addEventListener('click',()=>{const f=results[Number(btn.dataset.result)].feature;if(f.geometry){const layer=L.geoJSON(f);map.fitBounds(layer.getBounds(),{maxZoom:18});document.getElementById('featureInfo').innerHTML='<div class="popup-title">'+esc(results[Number(btn.dataset.result)].label)+'</div><table>'+Object.entries(f.properties||{}).map(([k,v])=>'<tr><td>'+esc(k)+'</td><td>'+esc(v)+'</td></tr>').join('')+'</table>';}}));}catch(err){console.error(err);out.textContent='Pencarian gagal. Pastikan WFS aktif dan GeoServer dapat diakses dari browser.';}});
document.getElementById('searchText').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('searchBtn').click();});
map.on('mousemove',e=>{document.getElementById('coordinateText').textContent='Koordinat: '+e.latlng.lat.toFixed(6)+', '+e.latlng.lng.toFixed(6);});
window.addEventListener('resize',()=>map.invalidateSize());setTimeout(()=>map.invalidateSize(),250);
</script>
</body>
</html>
