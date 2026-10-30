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
}

function draw(formRoot, { fit = false } = {}) {
    if (!map) return;
    const { lat, lng, radius } = readPoint(formRoot);
    if (lat == null || lng == null) {
        marker?.remove();
        circle?.remove();
        marker = null;
        circle = null;
        map.setView([FALLBACK.lat, FALLBACK.lng], FALLBACK.zoom);
        return;
    }

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
        if (fit) map.fitBounds(circle.getBounds(), { padding: [28, 28], maxZoom: 17 });
    } else {
        circle?.remove();
        circle = null;
        if (fit) map.setView([lat, lng], 16);
    }
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

    if (!map) {
        map = L.map(mapEl, { scrollWheelZoom: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        }).addTo(map);
        map.on('click', (event) => {
            writePoint(formRoot, event.latlng.lat, event.latlng.lng);
            const radiusEl = formRoot.querySelector('[name="radius_meters"]');
            if (radiusEl && !radiusEl.value) radiusEl.value = '100';
            draw(formRoot, { fit: true });
        });
        draw(formRoot, { fit: true });
        requestAnimationFrame(() => map?.invalidateSize());
        return;
    }

    draw(formRoot);
    map.invalidateSize();
}

export function refreshLocationMap(formRoot, { fit = false } = {}) {
    if (!map) {
        syncLocationMap(formRoot);
        return;
    }
    draw(formRoot, { fit });
}
