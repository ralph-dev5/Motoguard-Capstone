// Tilted 3D view of one motorcycle: extruded buildings, the day's route, the safe zone, and a
// marker the camera follows as live positions arrive. MapLibre is loaded on first use so the
// dashboard pages, which never show this view, don't pay for its ~800 KB.

// Free vector tiles with a building-height layer ("building-3d"); no API key needed.
const STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
const DEFAULT_CENTER = [120.9842, 14.5995]; // [lng, lat]
const ROUTE_COLOR = '#2a78d6';

const STATUS_COLOR = { online: '#0ca30c', alert: '#d03b3b', offline: '#898781' };

// GeoJSON wants [lng, lat]; the rest of the app passes [lat, lng].
const toLngLat = ([lat, lng]) => [lng, lat];

function lineFeature(points) {
    return {
        type: 'Feature',
        geometry: { type: 'LineString', coordinates: points.map(toLngLat) },
        properties: {},
    };
}

// MapLibre has no circle-in-metres primitive, so the safe zone is drawn as a 64-sided polygon.
function circleFeature(zone) {
    const steps = 64;
    const earth = 6371008.8;
    const lat = (zone.lat * Math.PI) / 180;
    const lng = (zone.lng * Math.PI) / 180;
    const d = zone.radius_m / earth;
    const ring = [];

    for (let i = 0; i <= steps; i++) {
        const bearing = (i / steps) * 2 * Math.PI;
        const pLat = Math.asin(Math.sin(lat) * Math.cos(d) + Math.cos(lat) * Math.sin(d) * Math.cos(bearing));
        const pLng = lng + Math.atan2(
            Math.sin(bearing) * Math.sin(d) * Math.cos(lat),
            Math.cos(d) - Math.sin(lat) * Math.sin(pLat),
        );
        ring.push([(pLng * 180) / Math.PI, (pLat * 180) / Math.PI]);
    }

    return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [ring] }, properties: {} };
}

// Compass bearing from one [lat, lng] to the next. The GPS's own course is unreliable at walking
// speed, so the camera turns by the direction actually travelled between fixes.
function bearingBetween([lat1, lng1], [lat2, lng2]) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const y = Math.sin(toRad(lng2 - lng1)) * Math.cos(toRad(lat2));
    const x = Math.cos(toRad(lat1)) * Math.sin(toRad(lat2))
        - Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(toRad(lng2 - lng1));

    return ((Math.atan2(y, x) * 180) / Math.PI + 360) % 360;
}

function motoMap3d(config) {
    // MapLibre objects stay outside Alpine's reactive state; proxying them breaks the map.
    let map = null;
    let marker = null;
    let resizeObserver = null;
    let route = [...(config.route ?? [])];
    let destroyed = false;
    let lib = null;
    // Plain object so follow() can read it without going through Alpine's proxy.
    const followState = { on: true };
    const subscriptions = [];

    const markerElement = (status) => {
        const el = document.createElement('div');
        el.className = 'moto-3d-marker';
        el.style.setProperty('--moto-color', STATUS_COLOR[status] ?? STATUS_COLOR.offline);
        el.innerHTML = '<span class="moto-3d-pulse"></span><span class="moto-3d-dot"></span>';
        return el;
    };

    const setRoute = (points) => {
        route = [...points];
        map.getSource('route')?.setData(lineFeature(route));
    };

    const placeMarker = (lngLat, status) => {
        if (!marker) {
            marker = new lib.Marker({ element: markerElement(status) }).setLngLat(lngLat).addTo(map);
            return;
        }
        marker.setLngLat(lngLat);
        marker.getElement().style.setProperty('--moto-color', STATUS_COLOR[status] ?? STATUS_COLOR.offline);
    };

    const follow = (lngLat, bearing) => {
        if (!followState.on) {
            return;
        }
        map.easeTo({
            center: lngLat,
            bearing: bearing ?? map.getBearing(),
            zoom: Math.max(map.getZoom(), 16.5),
            pitch: 62,
            duration: 1500,
        });
    };

    const listen = (channel, event, callback) => {
        channel.listen(event, callback);
        subscriptions.push([channel, event, callback]);
    };

    return {
        following: true,
        loading: true,
        failed: false,

        async init() {
            try {
                lib = (await import('maplibre-gl')).default;
                await import('maplibre-gl/dist/maplibre-gl.css');
            } catch {
                this.failed = true;
                this.loading = false;
                return;
            }
            if (destroyed) {
                return;
            }

            const device = config.device;
            const start = device.location
                ? [device.location.lng, device.location.lat]
                : route.length
                    ? toLngLat(route[route.length - 1])
                    : DEFAULT_CENTER;

            map = new lib.Map({
                container: this.$refs.canvas,
                style: STYLE_URL,
                center: start,
                zoom: 16.5,
                pitch: 62,
                bearing: -20,
                maxPitch: 80,
                attributionControl: { compact: true },
            });
            map.addControl(new lib.NavigationControl({ visualizePitch: true }), 'top-right');

            // Dragging the map means the owner wants to look around; stop pulling the camera back.
            map.on('dragstart', () => {
                this.following = false;
                followState.on = false;
            });

            map.on('error', () => {
                // Tile hiccups are routine; only a style that never loads is worth reporting.
                if (!map.isStyleLoaded()) {
                    this.failed = true;
                }
            });

            map.on('load', () => {
                this.loading = false;

                if (config.zone) {
                    map.addSource('zone', { type: 'geojson', data: circleFeature(config.zone) });
                    map.addLayer({
                        id: 'zone-fill',
                        type: 'fill',
                        source: 'zone',
                        paint: { 'fill-color': '#52514e', 'fill-opacity': 0.08 },
                    });
                    map.addLayer({
                        id: 'zone-line',
                        type: 'line',
                        source: 'zone',
                        paint: { 'line-color': '#52514e', 'line-width': 2, 'line-dasharray': [3, 3] },
                    });
                }

                map.addSource('route', { type: 'geojson', data: lineFeature(route) });
                map.addLayer({
                    id: 'route-casing',
                    type: 'line',
                    source: 'route',
                    layout: { 'line-cap': 'round', 'line-join': 'round' },
                    paint: { 'line-color': '#ffffff', 'line-width': 9, 'line-opacity': 0.9 },
                });
                map.addLayer({
                    id: 'route',
                    type: 'line',
                    source: 'route',
                    layout: { 'line-cap': 'round', 'line-join': 'round' },
                    paint: { 'line-color': ROUTE_COLOR, 'line-width': 5 },
                });

                if (device.location || route.length) {
                    placeMarker(start, device.status);
                }
            });

            resizeObserver = new ResizeObserver(() => map?.resize());
            resizeObserver.observe(this.$refs.canvas);

            // A different day picked on the page: redraw that day's route and jump to its end.
            this.$wire.$watch('route', (points) => {
                setRoute(points);
                if (points.length) {
                    const last = toLngLat(points[points.length - 1]);
                    placeMarker(last, config.device.status);
                    follow(last);
                }
            });

            if (window.Echo) {
                const channel = window.Echo.private(`devices.${device.id}`);

                listen(channel, 'DeviceLocationUpdated', (event) => {
                    const point = [event.point.lat, event.point.lng];
                    const previous = route[route.length - 1];
                    const lngLat = toLngLat(point);

                    if (this.$wire.routeDate === config.today) {
                        setRoute([...route, point]);
                    }
                    placeMarker(lngLat, event.device.status);

                    // Only turn the camera for a real move; GPS jitter at a standstill would spin it.
                    const moved = previous && (Math.abs(previous[0] - point[0]) + Math.abs(previous[1] - point[1])) > 0.00005;
                    follow(lngLat, moved ? bearingBetween(previous, point) : undefined);
                });

                listen(channel, 'DeviceStatusChanged', (event) => {
                    marker?.getElement().style.setProperty('--moto-color', STATUS_COLOR[event.device.status] ?? STATUS_COLOR.offline);
                });
            }
        },

        recenter() {
            this.following = true;
            followState.on = true;
            const target = marker?.getLngLat() ?? (route.length ? toLngLat(route[route.length - 1]) : null);
            if (target) {
                map.easeTo({ center: target, zoom: 16.5, pitch: 62, duration: 1000 });
            }
        },

        destroy() {
            destroyed = true;
            resizeObserver?.disconnect();
            subscriptions.forEach(([channel, event, callback]) => channel.stopListening(event, callback));
            map?.remove();
            map = null;
            marker = null;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('motoMap3d', motoMap3d);
});
