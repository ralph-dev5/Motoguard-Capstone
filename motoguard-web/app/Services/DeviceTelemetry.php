<?php

namespace App\Services;

use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Enums\DeviceStatus;
use App\Events\AlertTriggered;
use App\Events\DeviceLocationUpdated;
use App\Events\DeviceStatusChanged;
use App\Jobs\SendAlertSms;
use App\Models\Alert;
use App\Models\Device;
use App\Models\LocationLog;
use App\Support\DevicePresence;
use App\Support\GeoPoint;
use Illuminate\Support\Facades\Date;

class DeviceTelemetry
{
    public function __construct(private readonly GeofenceService $geofence) {}

    /**
     * @return array{armed: bool, safe_zone: array{lat: float, lng: float, radius_m: int}|null, server_time: string}
     */
    public function heartbeat(Device $device, ?float $batteryVoltage, ?GeoPoint $location, ?string $reportedState = null, ?int $gpsChars = null, ?int $gpsSatellites = null, ?bool $ownerNearby = null): array
    {
        if ($ownerNearby !== null) {
            $device->owner_nearby = $ownerNearby;
            if ($ownerNearby) {
                $device->owner_seen_at = now();
            }
        }

        if ($batteryVoltage !== null) {
            $device->battery_voltage = $batteryVoltage;
        }

        $this->recordGps($device, $gpsChars, $gpsSatellites, $location);
        $this->syncParkingZone($device);

        $exited = $location !== null && $this->moveTo($device, $location);

        $this->markSeen($device);
        $this->applyReportedState($device, $reportedState);
        $device->save();

        // This heartbeat's reply carries every pending change, so the ping can stop asking for one.
        DevicePresence::clearSync($device->id);

        DeviceStatusChanged::dispatch($device);

        if ($location !== null && $exited) {
            $this->raiseGeofenceAlert($device, $location);
        }

        return [
            'armed' => $device->is_armed,
            'calibrate' => $device->calibrationPending(),
            'owner_beacon' => $device->owner_beacon ?? '',
            // The owner's phone hotspot, which the device falls back to away from its usual WiFi.
            'backup_wifi' => $device->hotspot_ssid ? ['ssid' => $device->hotspot_ssid, 'password' => (string) $device->hotspot_password] : null,
            'safe_zone' => $device->safeZonePayload(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{lat: float|string, lng: float|string, speed_kmh?: float|null, heading?: float|null, satellites?: int|null, recorded_at?: string|null}>  $locations
     */
    public function storeLocations(Device $device, array $locations): void
    {
        /** @var LocationLog $latest */
        $latest = collect($locations)
            ->map(fn (array $location) => $device->locationLogs()->create([
                'location' => new GeoPoint((float) $location['lat'], (float) $location['lng']),
                'speed_kmh' => $location['speed_kmh'] ?? null,
                'heading' => $location['heading'] ?? null,
                'satellites' => $location['satellites'] ?? null,
                'recorded_at' => isset($location['recorded_at']) ? Date::parse($location['recorded_at']) : now(),
            ]))
            ->sortBy(fn (LocationLog $log) => $log->recorded_at->getTimestamp())
            ->last();

        $exited = $this->moveTo($device, $latest->location);

        $this->markSeen($device);
        $device->save();

        DeviceLocationUpdated::dispatch($device, $latest);

        if ($exited) {
            $this->raiseGeofenceAlert($device, $latest->location);
        }
    }

    /**
     * A null level is an alert that is not a motion episode (low battery) or one from firmware
     * that predates classification; both keep the old behaviour of always alarming.
     *
     * @param  array<string, mixed>  $payload
     */
    public function raiseAlert(Device $device, AlertType $type, ?GeoPoint $location, bool $smsSent, array $payload = [], ?AlertLevel $level = null): Alert
    {
        if ($location !== null) {
            $device->last_location = $location;
        }

        $alarming = $level?->raisesAlarm() ?? true;

        $alert = $device->alerts()->create([
            'type' => $type,
            'level' => $level,
            'location' => $device->last_location,
            'payload' => $payload ?: null,
            'sms_sent' => $smsSent,
            // Minor episodes are kept for the record only: already seen, so they never keep the
            // badge red or sit in the "open alerts" count waiting for the owner.
            'acknowledged_at' => $alarming ? null : now(),
        ]);
        $alert->setRelation('device', $device);

        if ($alarming) {
            $device->status = DeviceStatus::Alert;
        }
        $device->last_seen_at = now();
        $device->save();

        if (! $alarming) {
            return $alert;
        }

        AlertTriggered::dispatch($alert);
        DeviceStatusChanged::dispatch($device);

        if (! $smsSent && $device->owner_phone && ($level?->notifiesOwner() ?? true)) {
            SendAlertSms::dispatch($alert);
        }

        return $alert;
    }

    public function acknowledge(Device $device, ?Alert $alert = null): void
    {
        $this->acknowledgeMany($device, $alert ? [$alert->id] : null);
    }

    /**
     * Acknowledges the given alerts of one device in a single update, or all of them when $alertIds
     * is null, then clears the device's alert state once nothing is left open.
     *
     * @param  list<int>|null  $alertIds
     */
    public function acknowledgeMany(Device $device, ?array $alertIds = null): void
    {
        $device->alerts()
            ->when($alertIds !== null, fn ($query) => $query->whereKey($alertIds))
            ->unacknowledged()
            ->update(['acknowledged_at' => now()]);

        if ($device->status === DeviceStatus::Alert && ! $device->alerts()->unacknowledged()->exists()) {
            $device->status = $device->isRecentlySeen() ? DeviceStatus::Online : DeviceStatus::Offline;
            $device->save();

            DeviceStatusChanged::dispatch($device);
        }
    }

    public function setArmed(Device $device, bool $armed): void
    {
        $device->is_armed = $armed;
        $this->syncParkingZone($device);

        // Have the device fetch this on its next 1 s ping instead of waiting up to 30 s.
        DevicePresence::requestSync($device->id);

        // Armed with a live GPS fix: the spot it is standing on is the parking spot. Without one,
        // the first fix that arrives while it is guarded sets it instead (see moveTo).
        if ($device->isGuarding() && ! $device->hasSafeZone() && $device->last_location !== null && $device->gps_fix_at?->gt(now()->subMinutes(2))) {
            $this->parkAt($device, $device->last_location);
        }

        $device->save();

        DeviceStatusChanged::dispatch($device);
    }

    /**
     * Updates the location and reports whether this move crossed out of the safe zone while armed.
     */
    private function moveTo(Device $device, GeoPoint $next): bool
    {
        if ($device->isGuarding() && ! $device->hasSafeZone()) {
            // First fix since it was armed: this is where it is parked.
            $this->parkAt($device, $next);
        }

        $exited = $device->isGuarding()
            && $device->hasSafeZone()
            && ($device->last_location === null || ! $this->geofence->isOutside($device, $device->last_location))
            && $this->geofence->isOutside($device, $next);

        $device->last_location = $next;

        return $exited;
    }

    /**
     * The parking zone only exists while the motorcycle is guarded. Disarming it, or the owner's
     * phone coming near, lifts the zone so the next parking spot can be anchored afresh.
     */
    private function syncParkingZone(Device $device): void
    {
        if (! $device->isGuarding() && $device->hasSafeZone()) {
            $device->safe_zone_center = null;
            $device->parked_at = null;
        }
    }

    private function parkAt(Device $device, GeoPoint $point): void
    {
        $device->safe_zone_center = $point;
        $device->parked_at = now();
    }

    private function raiseGeofenceAlert(Device $device, GeoPoint $location): void
    {
        $center = $device->safe_zone_center;

        $this->raiseAlert($device, AlertType::GeofenceExit, $location, smsSent: false, payload: [
            'distance_m' => $center ? round($this->geofence->distanceMeters($center, $location)) : null,
            'radius_m' => $device->parkingRadius(),
        ], level: AlertLevel::TheftAttempt);
    }

    /**
     * The firmware reports its own state on every heartbeat. Without it the dashboard would
     * sit on "alert" forever, because the device has no other way to say it has calmed down.
     * An unacknowledged alert still keeps the badge red: the owner has to see it happened.
     */
    private function applyReportedState(Device $device, ?string $reportedState): void
    {
        if ($reportedState === null) {
            return;
        }

        // An alarm only sounds while armed, so "alert" is armed too.
        $device->reported_armed = $reportedState !== 'disarmed';

        if ($reportedState === 'alert') {
            $device->status = DeviceStatus::Alert;

            return;
        }

        if ($device->status === DeviceStatus::Alert && ! $device->alerts()->unacknowledged()->exists()) {
            $device->status = DeviceStatus::Online;
        }
    }

    /**
     * Stores what the receiver reported about itself. gps_fix_at is stamped only when this
     * heartbeat carried a position, which is what lets the dashboard tell a live lock from the
     * stale coordinates left behind in last_location, which is never cleared.
     */
    private function recordGps(Device $device, ?int $chars, ?int $satellites, ?GeoPoint $location): void
    {
        if ($chars !== null) {
            $device->gps_chars = $chars;
        }

        if ($satellites !== null) {
            $device->gps_satellites = $satellites;
        }

        if ($location !== null) {
            $device->gps_fix_at = now();
        }
    }

    private function markSeen(Device $device): void
    {
        $device->last_seen_at = now();

        // Any request from the device proves it is powered, not just the 1 s ping. The pings tend
        // to drop out for a few seconds around each heartbeat, so counting the heartbeat (and alerts
        // and locations) as a ping keeps the "Device on" badge from flickering in exactly that gap.
        DevicePresence::touch($device->id);

        if ($device->status === DeviceStatus::Offline) {
            $device->status = DeviceStatus::Online;
        }
    }
}
