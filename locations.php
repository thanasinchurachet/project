<?php
require_once __DIR__.'/api_client.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ค้นหาศูนย์จัดการขยะ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="css/share.css" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', sans-serif; background-color: #f8f9fa; }
        /* Map UI removed from this page */
        #map { display:none; height:0; width:0; }
        .card { border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transition: transform 0.2s ease-in-out; }
        .card:hover { transform: translateY(-5px); }
        .filter-chip { padding: 0.5rem 1rem; border-radius: 9999px; background-color: #ffffff; color: #374151; transition: all 0.3s; cursor: pointer; border: 1px solid #e5e7eb; font-weight: 500; }
        .filter-chip:hover, .filter-chip.active { background-color: #10b981; color: white; border-color: #10b981; }
        .pagination-controls button:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>

    <div class="container" style="padding-top: 50px; padding-bottom: 60px;">


        <div class="card p-3 p-md-4 mb-4">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-lg-7">
                    <label class="form-label fw-semibold">ประเภทขยะ:</label>
                    <div id="filter-container" class="d-flex flex-wrap gap-2">
                        <button class="filter-chip active" data-keyword="all">ทั้งหมด</button>
                        <button class="filter-chip" data-keyword="ขยะรีไซเคิล">รีไซเคิล</button>
                        <button class="filter-chip" data-keyword="ขยะอันตราย">อันตราย</button>
                        <button class="filter-chip" data-keyword="ขยะทั่วไป">ทั่วไป</button>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
    <label for="radiusInput" class="form-label fw-semibold">ค้นหาในรัศมี (กม.):</label>
    <div class="input-group">
        <input type="number" id="radiusInput" class="form-control" value="30" min="1" max="120">
        <button id="searchBtn" class="btn btn-success"><i class="fas fa-search me-1"></i> ค้นหา</button>
    </div>
    <div id="radiusHelp" class="form-text">
        <i class="fas fa-info-circle"></i> รัศมีการค้นหาสูงสุดคือ 120 กิโลเมตร
    </div>
</div>
            </div>
        </div>

        <div id="loading" class="text-center py-5 d-none">
            <div class="spinner-border text-success" role="status" style="width: 3rem; height: 3rem;"></div>
            <p class="mt-3">กำลังค้นหาสถานที่ใกล้เคียง...</p>
        </div>

        <div id="result" class="row g-4"></div>

        <div id="pagination-container" class="pagination-controls d-flex justify-content-center align-items-center gap-3 py-5 d-none">
            <button class="btn btn-success" id="prevPage"><i class="fas fa-chevron-left"></i> ก่อนหน้า</button>
            <span class="fw-semibold" id="currentPage"></span>
            <button class="btn btn-success" id="nextPage">ถัดไป <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>

    <?php include 'chat-button.php'; ?>
    
  
<!-- Leaflet (OpenStreetMap) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map, marker, infoWindow, userLat, userLng, placeAutocomplete;
        let allPlaces = [], currentPage = 1, placeMarkers = [];
        // geo accuracy helpers
        let accuracyCircle = null, bestAccuracy = Infinity, lastFixTime = 0, firstFixDone = false;
        let lastLat = null, lastLng = null;
        const itemsPerPage = 9;

        function clearPlaceMarkers() {
            try { placeMarkers.forEach(m => m && map && map.removeLayer ? map.removeLayer(m) : null); } catch(_){}
            placeMarkers = [];
        }

        function displayMarkersOnMap(places) {
            // No map markers to display (map hidden)
        }

        function haversineMeters(lat1,lng1,lat2,lng2){
            const R=6371000;
            const toRad = (d)=>d*Math.PI/180;
            const dLat=toRad(lat2-lat1), dLng=toRad(lng2-lng1);
            const a=Math.sin(dLat/2)**2+Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLng/2)**2;
            return 2*R*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
        }

        function initMap() {
            const defaultPosition = { lat: 19.9087, lng: 99.8325 }; 

            // Map hidden; no map/marker init
            map = null; marker = null; infoWindow = { setContent: ()=>{}, open: ()=>{} };

            // helper: apply user location consistently
            const applyUserLocation = (lat, lng, acc = Infinity, recenter = true) => {
                const now = Date.now();
                const moved = (lastLat!==null)? haversineMeters(lastLat,lastLng,lat,lng) : Infinity;
                const improved = acc < bestAccuracy - 20; // at least 20m better
                const stale = (now - lastFixTime) > 3000;  // 3s throttle
                if (!improved && moved < 30 && !stale && firstFixDone) return; // ignore tiny changes

                bestAccuracy = Math.min(bestAccuracy, acc || Infinity);
                lastFixTime = now;
                lastLat = lat; lastLng = lng;

                // Update only state (no map visuals)
                firstFixDone = true;

                updateLocation(lat, lng);
            };

            // No drag/click handlers (no map)

            // Places Autocomplete on search input
            // Removed Google Places Autocomplete; using backend text geocode handlers below

            if (navigator.geolocation) {
                const GEO_OPTS = { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 };
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        applyUserLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy, true);
                        // watch for refined fixes
                        try {
                            geoWatchId = navigator.geolocation.watchPosition(
                                (p) => applyUserLocation(p.coords.latitude, p.coords.longitude, p.coords.accuracy, false),
                                () => {},
                                GEO_OPTS
                            );
                            setTimeout(()=>{ if(geoWatchId){ try{ navigator.geolocation.clearWatch(geoWatchId);}catch{} geoWatchId=null; } }, 20000);
                        } catch {}
                    },
                    () => updateLocation(defaultPosition.lat, defaultPosition.lng),
                    GEO_OPTS
                );
            } else {
                updateLocation(defaultPosition.lat, defaultPosition.lng);
            }

            // No map controls
        }
        
        function updateLocation(lat, lng) {
            userLat = lat;
            userLng = lng;

            fetch(`http://localhost:8000/reverse_geocode?lat=${lat}&lng=${lng}`)
                .then(res => res.json())
                .then(data => {
                    const address = data.address || `พิกัด: ${lat.toFixed(4)}, ${lng.toFixed(4)}`;

                    // แสดงผลที่การ์ดเท่านั้น ไม่เปิดแผนที่
                });
            
            fetchData();
        }

        const loadingDiv = document.getElementById('loading');
        const resultDiv = document.getElementById('result');
        const paginationContainer = document.getElementById('pagination-container');

        const generateStars = (rating) => { const r = parseFloat(rating) || 0; let h = ''; for (let i = 1; i <= 5; i++) { if (i <= r) h += '<i class="fas fa-star"></i>'; else if (i - 0.5 <= r) h += '<i class="fas fa-star-half-alt"></i>'; else h += '<i class="far fa-star"></i>'; } return h; };
        
        const displayPage = (page) => {
            resultDiv.innerHTML = "";
            const items = allPlaces.slice((page - 1) * itemsPerPage, page * itemsPerPage);
            items.forEach(p => {
                const card = document.createElement("div");
                card.className = "col-md-6 col-lg-4 d-flex";
                const base = (String(p.type||'').toLowerCase()==='recycle') ? 'จุดรีไซเคิล' : 'จุดทิ้งขยะ';
                const addr = (p.address||'').trim();
                const locality = addr ? addr.split(',')[0].trim() : '';
                const hasRealName = p.center_name && !/^ศูนย์จัดการ(ขยะ)?$/.test(String(p.center_name).trim());
                const displayName = hasRealName ? p.center_name : (locality ? `${base} – ${locality}` : base);
                card.innerHTML = `<div class=\"card p-3 bg-white w-100\">\n                  <h5 class=\"fw-bold mb-1\">${displayName}</h5>\n                  <p class=\"text-muted small mb-2\">${addr}</p>\n                  <div class=\"d-flex justify-content-end mb-2\">\n                    <span class=\"badge bg-success rounded-pill\">${p.distance || '-'} กม.</span>\n                  </div>\n                  <a href=\"${p.maps_link}\" target=\"_blank\" class=\"btn btn-sm btn-outline-primary mt-auto\"><i class=\"fas fa-map-marker-alt me-1\"></i> ดูบนแผนที่</a>\n                </div>`;
                resultDiv.appendChild(card);
            });
        };
        
        const setupPagination = () => {
            const totalPages = Math.ceil(allPlaces.length / itemsPerPage);
            paginationContainer.classList.toggle('d-none', totalPages <= 0);
            if (totalPages > 0) {
                document.getElementById('currentPage').textContent = `หน้า ${currentPage} จาก ${totalPages}`;
                document.getElementById('prevPage').disabled = currentPage === 1;
                document.getElementById('nextPage').disabled = currentPage >= totalPages;
                displayPage(currentPage);
            }
        };

        async function fetchOverpassCenters(lat, lng, radiusKm, kwLabel) {
            const radiusM = Math.max(100, Math.round(radiusKm * 1000));
            const endpoints = [
                'https://overpass-api.de/api/interpreter',
                'https://overpass.kumi.systems/api/interpreter',
                'https://overpass.openstreetmap.ru/api/interpreter'
            ];
            const q = `
                [out:json][timeout:25];
                (
                  node["amenity"="recycling"](around:${radiusM},${lat},${lng});
                  way["amenity"="recycling"](around:${radiusM},${lat},${lng});
                  relation["amenity"="recycling"](around:${radiusM},${lat},${lng});
                  node["amenity"="waste_disposal"](around:${radiusM},${lat},${lng});
                  way["amenity"="waste_disposal"](around:${radiusM},${lat},${lng});
                  relation["amenity"="waste_disposal"](around:${radiusM},${lat},${lng});
                );
                out center tags;`;
            let data = null;
            for (const ep of endpoints) {
                try {
                    const res = await fetch(ep, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: 'data=' + encodeURIComponent(q)
                    });
                    data = await res.json();
                    if (data && data.elements) break;
                } catch(_) {}
            }
            if (!data) return [];
            const elements = Array.isArray(data.elements) ? data.elements : [];

            const isHazardous = (tags) => {
                if (!tags) return false;
                const keys = [
                  'recycling:hazardous_waste','recycling:batteries','recycling:electronics',
                  'recycling:oil','recycling:paint','recycling:chemicals','recycling:toxic'
                ];
                return keys.some(k => String(tags[k]||'').toLowerCase() === 'yes');
            };

            const normalize = (el) => {
                const tags = el.tags || {};
                const la = (typeof el.lat === 'number') ? el.lat : (el.center ? el.center.lat : null);
                const ln = (typeof el.lon === 'number') ? el.lon : (el.center ? el.center.lon : null);
                const amenity = (tags.amenity || '').toLowerCase();
                let type = (amenity === 'recycling') ? 'recycle' : 'general';
                if (amenity === 'recycling' && isHazardous(tags)) type = 'hazardous';
                // Prefer real names; if missing, synthesize from type + locality (ถนน/ย่าน)
                let displayName = tags['name:th'] || tags['name:en'] || tags['int_name'] ||
                                  tags['operator:th'] || tags['operator:en'] || tags['operator'] ||
                                  tags['brand:th'] || tags['brand:en'] || tags['brand'] ||
                                  tags.name || '';
                // Build address from OSM addr:* tags
                const addrParts = [];
                const addIf = (v) => { if (v) addrParts.push(v); };
                addIf(tags['addr:housename']);
                addIf(tags['addr:housenumber']);
                addIf(tags['addr:street']);
                addIf(tags['addr:subdistrict'] || tags['addr:suburb']);
                addIf(tags['addr:district'] || tags['addr:county']);
                addIf(tags['addr:city'] || tags['addr:town'] || tags['addr:village']);
                addIf(tags['addr:province'] || tags['addr:state']);
                addIf(tags['addr:postcode']);
                let address = tags['addr:full'] || addrParts.join(', ');
                const locKeys = [
                  'addr:street','addr:neighbourhood','addr:subdistrict','addr:suburb','addr:quarter',
                  'addr:hamlet','addr:village','addr:town','addr:city','addr:district','addr:province','addr:place'
                ];
                let locality = '';
                for (const k of locKeys) { const v = tags[k]; if (v) { locality = v; break; } }
                if (!locality && tags['addr:full']) {
                    try { locality = String(tags['addr:full']).split(',')[0].trim(); } catch {}
                }
                if (!displayName || !displayName.trim()) {
                    const base = (amenity === 'recycling') ? 'จุดรีไซเคิล' : 'จุดทิ้งขยะ';
                    displayName = locality ? `${base} – ${locality}` : base;
                }
                if (!address && locality) { address = locality; }
                const acc = {
                    plastic: String(tags['recycling:plastic']||'').toLowerCase()==='yes',
                    glass: String(tags['recycling:glass']||'').toLowerCase()==='yes',
                    paper: String(tags['recycling:paper']||'').toLowerCase()==='yes',
                    metal: String(tags['recycling:metal']||'').toLowerCase()==='yes',
                    batteries: String(tags['recycling:batteries']||'').toLowerCase()==='yes',
                    electronics: String(tags['recycling:electronics']||'').toLowerCase()==='yes',
                    clothes: String(tags['recycling:clothes']||'').toLowerCase()==='yes',
                    oil: (String(tags['recycling:cooking_oil']||'').toLowerCase()==='yes' || String(tags['recycling:oil']||'').toLowerCase()==='yes')
                };
                const acceptedList = Object.entries(acc).filter(([,v])=>v).map(([k])=>({plastic:'พลาสติก',glass:'แก้ว',paper:'กระดาษ',metal:'โลหะ',batteries:'แบตเตอรี่',electronics:'อิเล็กทรอนิกส์',clothes:'เสื้อผ้า',oil:'น้ำมัน'})[k]);
                const distKm = (Number.isFinite(la) && Number.isFinite(ln)) ? (haversineMeters(lat,lng,la,ln)/1000) : null;
                return {
                    center_name: displayName,
                    lat: la,
                    lng: ln,
                    address: address || '',
                    rating: null,
                    distance: distKm !== null ? distKm.toFixed(1) : null,
                    maps_link: (Number.isFinite(la) && Number.isFinite(ln)) ? `https://www.openstreetmap.org/?mlat=${la}&mlon=${ln}#map=16/${la}/${ln}` : '#',
                    type,
                    opening_hours: tags['opening_hours'] || null,
                    phone: tags['contact:phone'] || tags['phone'] || null,
                    website: tags['contact:website'] || tags['website'] || null,
                    accepted: acc,
                    accepted_list: acceptedList
                };
            };

            let places = elements.map(normalize).filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lng));

            // Filter by selected chip
            const kw = (kwLabel||'').trim();
            if (kw === 'ขยะรีไซเคิล') {
                places = places.filter(p => p.type === 'recycle' || p.type === 'hazardous');
            } else if (kw === 'ขยะอันตราย') {
                places = places.filter(p => p.type === 'hazardous');
            } else if (kw === 'ขยะทั่วไป') {
                places = places.filter(p => p.type === 'general');
            }

            places.sort((a,b) => (parseFloat(a.distance||'999') - parseFloat(b.distance||'999')));
            return places;
        }

        const fetchData = async () => {
            if (!userLat || !userLng) return;
            loadingDiv.classList.remove('d-none');
            resultDiv.innerHTML = "";
            paginationContainer.classList.add('d-none');
            clearPlaceMarkers();

            const rawRadius = parseFloat(document.getElementById('radiusInput').value);
            const radius = Math.max(0.5, Math.min(120, isNaN(rawRadius) ? 30 : rawRadius));
            const kw = (document.querySelector('.filter-chip.active')?.dataset?.keyword || 'all').trim();
            try {
                allPlaces = await fetchOverpassCenters(userLat, userLng, radius, kw);
                loadingDiv.classList.add('d-none');
                displayMarkersOnMap(allPlaces);
                if (allPlaces.length === 0) {
                    resultDiv.innerHTML = `<p class='text-center col-12 text-muted'>ไม่พบศูนย์จัดการขยะที่ตรงตามเงื่อนไข</p>`;
                } else {
                    currentPage = 1;
                    setupPagination();
                }
            } catch (error) {
                console.error('Overpass error:', error);
                loadingDiv.classList.add('d-none');
                resultDiv.innerHTML = '<p class="text-danger col-12 text-center">เกิดข้อผิดพลาดในการดึงข้อมูล</p>';
            }
        };
        
        document.getElementById('searchBtn').addEventListener('click', fetchData);
        document.querySelectorAll('.filter-chip').forEach(chip => {
            chip.addEventListener('click', function() {
                document.querySelector('.filter-chip.active').classList.remove('active');
                this.classList.add('active');
                fetchData();
            });
        });
        document.getElementById('prevPage').addEventListener('click', () => { if (currentPage > 1) { currentPage--; setupPagination(); } });
        document.getElementById('nextPage').addEventListener('click', () => { if (currentPage < Math.ceil(allPlaces.length / itemsPerPage)) { currentPage++; setupPagination(); } });
        const radiusInput = document.getElementById('radiusInput');
radiusInput.addEventListener('input', () => {
    if (parseInt(radiusInput.value, 10) > 120) {
        radiusInput.value = 120;
    }
});

        // Manual search fallback via backend geocode (Enter or button)
        const placeSearchInput = document.getElementById('placeSearchInput');
        const placeSearchBtn = document.getElementById('placeSearchBtn');
        const triggerTextGeocode = () => {
            const q = (placeSearchInput?.value || '').trim();
            if (!q) return;
            fetch(`http://localhost:8000/geocode?address=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) return;
                    const lat = parseFloat(data.lat);
                    const lng = parseFloat(data.lng);
                    if (isFinite(lat) && isFinite(lng)) {
                        map.setView([lat, lng], 15);
                        marker.setLatLng([lat, lng]);
                        updateLocation(lat, lng);
                    }
                })
                .catch(() => {});
        };
        placeSearchBtn?.addEventListener('click', (e) => { e.preventDefault(); triggerTextGeocode(); });
        placeSearchInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); triggerTextGeocode(); } });
        // Initialize map when DOM is ready
        document.addEventListener('DOMContentLoaded', initMap);
    </script>
</body>
</html>
