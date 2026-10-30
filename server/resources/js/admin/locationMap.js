import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const FALLBACK = { lat: -2.5, lng: 118, zoom: 5 };
const CIRCLE = { color: '#173d2b', weight: 2, fillColor: '#173d2b', fillOpacity: 0.14 };
const DOT = { radius: 7, color: '#173d2b', weight: 2, fillColor: '#d9f25f', fillOpacity: 1 };

let map = null;
let marker = null;
let circle = null;

function readPoint(formRoot) {
    const lat = Number(formRoot.querySelector('[name="latitude"]')?.value);
    const lng = Number(formRoot.querySelector('[name="longitude"]')?.value);
    const radius = Number(formRoot.querySelector('[name="radius_meters"]')?.value);
    return {
        lat: Number.isFinite(lat) && lat >= -90 && lat <= 90 ? lat : null,
        lng: Number.isFinite(lng) && lng >= -180 && lng <= 180 ? lng : null,
        radius: Number.isFinite(radius) && radius > 0 ? radius : null,
    };
}

function writePoint(formRoot, lat, lng) {
    const latEl = formRoot.querySelector('[name="latitude"]');
    const lngEl = formRoot.querySelector('[name="longitude"]');
    if (latEl) latEl.value = lat.toFixed(6);
    if (lngEl) lngEl.value = lng.toFixed(6);
    const radiusEl = formRoot.querySelector('[name="radius_meters"]');
    if (radiusEl && !radiusEl.value) radiusEl.value = '100';
}

function setStatus(formRoot, text = '', isError = false) {
    const el = formRoot.querySelector('[data-geo-status]');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('field-error', isError);
    el.hidden = !text;
}

function boundsAround(lat, lng, radius) {
    const latPad = radius / 111320;
    const lngPad = radius / (111320 * Math.max(Math.abs(Math.cos((lat * Math.PI) / 180)), 0.01));
    return L.latLngBounds([lat - latPad, lng - lngPad], [lat + latPad, lng + lngPad]);
}

function ensureView(lat, lng, zoom) {
    if (typeof map.getZoom() !== 'number') {
        map.setView([lat, lng], zoom);
    }
}

function draw(formRoot, { fit = false } = {}) {
    if (!map) return;
    const { lat, lng, radius } = readPoint(formRoot);
    if (lat == null || lng == null) {
        marker?.remove();
        circle?.remove();
        marker = null;
        circle = null;
        ensureView(FALLBACK.lat, FALLBACK.lng, FALLBACK.zoom);
        map.setView([FALLBACK.lat, FALLBACK.lng], FALLBACK.zoom);
        return;
    }

    ensureView(lat, lng, 16);

    if (!marker) {
        marker = L.circleMarker([lat, lng], DOT).addTo(map);
    } else {
        marker.setLatLng([lat, lng]);
    }

    if (radius) {
        if (!circle) {
            circle = L.circle([lat, lng], { ...CIRCLE, radius }).addTo(map);
        } else {
            circle.setLatLng([lat, lng]);
            circle.setRadius(radius);
        }
        if (fit) map.fitBounds(boundsAround(lat, lng, radius), { padding: [28, 28], maxZoom: 17 });
    } else {
        circle?.remove();
        circle = null;
        if (fit) map.setView([lat, lng], 16);
    }
}

function layoutMap(formRoot) {
    if (!map) return;
    map.invalidateSize();
    draw(formRoot, { fit: true });
}

export function destroyLocationMap() {
    if (!map) return;
    map.remove();
    map = null;
    marker = null;
    circle = null;
}

export function syncLocationMap(formRoot) {
    const wrap = formRoot.querySelector('[data-field="map"]');
    if (!wrap || wrap.hidden) {
        destroyLocationMap();
        return;
    }

    const mapEl = wrap.querySelector('[data-osm-map]');
    if (!mapEl) return;

    if (map && map.getContainer() !== mapEl) {
        destroyLocationMap();
    }

    if (mapEl.clientWidth < 8) {
        requestAnimationFrame(() => {
            if (mapEl.isConnected && mapEl.clientWidth >= 8) syncLocationMap(formRoot);
        });
        return;
    }

    if (!map) {
        map = L.map(mapEl, { scrollWheelZoom: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        }).addTo(map);
        map.setView([FALLBACK.lat, FALLBACK.lng], FALLBACK.zoom);
        map.on('click', (event) => {
            writePoint(formRoot, event.latlng.lat, event.latlng.lng);
            setStatus(formRoot);
            draw(formRoot, { fit: true });
        });
        layoutMap(formRoot);
        requestAnimationFrame(() => layoutMap(formRoot));
        return;
    }

    map.invalidateSize();
    draw(formRoot);
}

export function refreshLocationMap(formRoot, { fit = false } = {}) {
    if (!map) {
        syncLocationMap(formRoot);
        return;
    }
    draw(formRoot, { fit });
}

export function locateCurrentPoint(formRoot, button) {
    if (!navigator.geolocation) {
        setStatus(formRoot, 'Browser ini tidak mendukung lokasi GPS.', true);
        return;
    }

    const label = button.querySelector('span');
    const prev = label?.textContent ?? 'Gunakan lokasi saya';
    button.disabled = true;
    if (label) label.textContent = 'Mengambil lokasi...';
    setStatus(formRoot, 'Mengambil koordinat perangkat...');

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            button.disabled = false;
            if (label) label.textContent = prev;
            writePoint(formRoot, pos.coords.latitude, pos.coords.longitude);
            const acc = Math.round(pos.coords.accuracy || 0);
            setStatus(formRoot, acc > 0 ? `Titik diisi dari lokasi perangkat (akurasi sekitar ${acc} m).` : 'Titik diisi dari lokasi perangkat.');
            syncLocationMap(formRoot);
            draw(formRoot, { fit: true });
        },
        (err) => {
            button.disabled = false;
            if (label) label.textContent = prev;
            const msg = err.code === 1
                ? 'Izin lokasi ditolak di browser. Izinkan akses lokasi atau klik titik langsung di peta.'
                : err.code === 3
                    ? 'Waktu ambil lokasi habis. Coba lagi atau klik titik di peta.'
                    : 'Lokasi perangkat belum terbaca. Pastikan GPS aktif atau klik titik di peta.';
            setStatus(formRoot, msg, true);
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 },
    );
}
