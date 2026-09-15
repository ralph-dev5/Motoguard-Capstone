import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import Chart from 'chart.js/auto';

const DEFAULT_CENTER = [14.5995, 120.9842];

const STATUS = {
    online: { color: '#0ca30c', label: 'Online' },
    alert: { color: '#d03b3b', label: 'Alert' },
    offline: { color: '#898781', label: 'Offline' },
};

function deviceLabel(device) {
    const status = STATUS[device.status] ?? STATUS.offline;
    const root = document.createElement('div');
    const name = document.createElement('strong');
    const meta = document.createElement('div');

    name.textContent = device.name;
    meta.textContent = [device.plate_number, status.label, device.is_armed ? 'Armed' : 'Disarmed']
        .filter(Boolean)
        .join(' · ');

    root.append(name, meta);
    return root;
}

function motoMap(config) {
    // Leaflet objects live outside Alpine's reactive state; proxying them breaks Leaflet.
    let map = null;
    let routeLine = null;
    let zoneCircle = null;
    const markers = new Map();
    const subscriptions = [];

    const upsertMarker = (device) => {
        if (!device.location) {
            return;
        }

        const latLng = [device.location.lat, device.location.lng];
        const fillColor = (STATUS[device.status] ?? STATUS.offline).color;
        const existing = markers.get(device.id);

        if (existing) {
            existing.setLatLng(latLng).setStyle({ fillColor }).setTooltipContent(deviceLabel(device));
            return;
        }

        const marker = L.circleMarker(latLng, { radius: 9, weight: 3, color: '#ffffff', fillColor, fillOpacity: 1 })
            .bindTooltip(deviceLabel(device))
            .addTo(map);

        if (config.deviceUrl) {
            marker.on('click', () => window.Livewire.navigate(config.deviceUrl.replace('__ID__', device.id)));
        }

        markers.set(device.id, marker);
    };

    const drawRoute = (points) => {
        routeLine?.remove();
        routeLine = points.length
            ? L.polyline(points, { color: '#2a78d6', weight: 4, opacity: 0.85 }).addTo(map)
            : null;
    };

    const appendRoute = (latLng) => {
        if (routeLine) {
            routeLine.addLatLng(latLng);
        } else {
            drawRoute([latLng]);
        }
    };

    const drawZone = (zone) => {
        zoneCircle?.remove();
        zoneCircle = L.circle([zone.lat, zone.lng], {
            radius: zone.radius_m,
            color: '#52514e',
            weight: 2,
            dashArray: '6 6',
            fillOpacity: 0.06,
        }).addTo(map);
    };

    const removeZone = () => {
        zoneCircle?.remove();
        zoneCircle = null;
    };

    const fitToContent = () => {
        const layers = [...markers.values(), routeLine, zoneCircle].filter(Boolean);
        if (!layers.length) {
            return;
        }

        const bounds = L.featureGroup(layers).getBounds();
        if (bounds.isValid()) {
            map.fitBounds(bounds.pad(0.2), { maxZoom: 16 });
        }
    };

    const listen = (channel, event, callback) => {
        channel.listen(event, callback);
        subscriptions.push([channel, event, callback]);
    };

    return {
        init() {
            map = L.map(this.$el).setView(DEFAULT_CENTER, 13);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);

            config.devices.forEach(upsertMarker);
            if (config.route?.length) {
                drawRoute(config.route);
            }
            if (config.zone) {
                drawZone(config.zone);
            }
            fitToContent();
            requestAnimationFrame(() => map.invalidateSize());

            if (config.editableZone) {
                map.on('click', (event) => {
                    const { lat, lng } = event.latlng;
                    drawZone({ lat, lng, radius_m: Number(this.$wire.zoneRadius) || 200 });
                    this.$wire.placeZone(lat, lng);
                });

                this.$wire.$watch('zoneRadius', (radius) => zoneCircle?.setRadius(Number(radius)));
                this.$wire.$watch('zoneLat', (lat) => {
                    if (lat === null) {
                        removeZone();
                    }
                });
            }

            if (config.deviceId) {
                this.$wire.$watch('route', (points) => {
                    drawRoute(points);
                    fitToContent();
                });
            }

            if (window.Echo) {
                const channel = config.deviceId
                    ? window.Echo.private(`devices.${config.deviceId}`)
                    : window.Echo.private(`App.Models.User.${config.userId}`);

                listen(channel, 'DeviceLocationUpdated', (event) => {
                    upsertMarker(event.device);

                    if (config.deviceId && this.$wire.routeDate === config.today) {
                        appendRoute([event.point.lat, event.point.lng]);
                    }
                });

                listen(channel, 'DeviceStatusChanged', (event) => upsertMarker(event.device));
            }
        },

        destroy() {
            subscriptions.forEach(([channel, event, callback]) => channel.stopListening(event, callback));
            map?.remove();
        },
    };
}

function alertsChart(data) {
    let chart = null;

    return {
        init() {
            const dark = document.documentElement.classList.contains('dark');
            const ink = dark
                ? { series: '#3987e5', surface: '#1a1a19', primary: '#ffffff', secondary: '#c3c2b7', muted: '#898781', grid: '#2c2c2a', baseline: '#383835', border: 'rgba(255,255,255,0.10)' }
                : { series: '#2a78d6', surface: '#fcfcfb', primary: '#0b0b0b', secondary: '#52514e', muted: '#898781', grid: '#e1e0d9', baseline: '#c3c2b7', border: 'rgba(11,11,11,0.10)' };

            chart = new Chart(this.$el, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Alerts',
                        data: data.counts,
                        backgroundColor: ink.series,
                        hoverBackgroundColor: ink.series,
                        borderRadius: { topLeft: 4, topRight: 4 },
                        borderSkipped: 'start',
                        maxBarThickness: 28,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: ink.surface,
                            titleColor: ink.primary,
                            bodyColor: ink.secondary,
                            borderColor: ink.border,
                            borderWidth: 1,
                            displayColors: false,
                            padding: 10,
                            callbacks: {
                                title: (items) => data.dates[items[0].dataIndex],
                                label: (item) => `${item.parsed.y} ${item.parsed.y === 1 ? 'alert' : 'alerts'}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { color: ink.baseline },
                            ticks: { color: ink.muted },
                        },
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: ink.grid },
                            ticks: { color: ink.muted, precision: 0, maxTicksLimit: 5 },
                        },
                    },
                },
            });
        },

        destroy() {
            chart?.destroy();
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('motoMap', motoMap);
    window.Alpine.data('alertsChart', alertsChart);
});
