<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DeviceStatus;
use App\Events\AlertTriggered;
use App\Events\DeviceLocationUpdated;
use App\Events\DeviceStatusChanged;
use App\Jobs\SendAlertSms;
use App\Models\Alert;
use App\Models\Device;
use App\Models\LocationLog;
use App\Support\GeoPoint;
use Illuminate\Support\Facades\Date;

class DeviceTelemetry
{
    public function __construct(private readonly GeofenceService $geofence) {}

    /**
     * @return array{armed: bool, safe_zone: array{lat: float, lng: float, radius_m: int}|null, server_time: string}
     */
    public function heartbeat(Device $device, ?float $batteryVoltage, ?GeoPoint $location): array
    {
        if ($batteryVoltage !== null) {
            $device->battery_voltage = $batteryVoltage;
        }

        $exited = $location !== null && $this->moveTo($device, $location);

        $this->markSeen($device);
        $device->save();

        DeviceStatusChanged::dispatch($device);

        if ($location !== null && $exited) {
            $this->raiseGeofenceAlert($device, $location);
        }

        return [
            'armed' => $device->is_armed,
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
     * @param  array<string, mixed>  $payload
     */
    public function raiseAlert(Device $device, AlertType $type, ?GeoPoint $location, bool $smsSent, array $payload = []): Alert
    {
        if ($location !== null) {
            $device->last_location = $location;
        }

        $alert = $device->alerts()->create([
            'type' => $type,
            'location' => $device->last_location,
            'payload' => $payload ?: null,
            'sms_sent' => $smsSent,
        ]);
        $alert->setRelation('device', $device);

        $device->status = DeviceStatus::Alert;
        $device->last_seen_at = now();
        $device->save();

        AlertTriggered::dispatch($alert);
        DeviceStatusChanged::dispatch($device);

        if (! $smsSent && $device->owner_phone) {
            SendAlertSms::dispatch($alert);
        }

        return $alert;
    }

    public function acknowledge(Device $device, ?Alert $alert = null): void
    {
        $device->alerts()
            ->when($alert, fn ($query) => $query->whereKey($alert?->id))
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
        $device->save();

        DeviceStatusChanged::dispatch($device);
    }

    /**
     * Updates the location and reports whether this move crossed out of the safe zone while armed.
     */
    private function moveTo(Device $device, GeoPoint $next): bool
    {
        $exited = $device->is_armed
            && $device->hasSafeZone()
            && ($device->last_location === null || ! $this->geofence->isOutside($device, $device->last_location))
            && $this->geofence->isOutside($device, $next);

        $device->last_location = $next;

        return $exited;
    }

    private function raiseGeofenceAlert(Device $device, GeoPoint $location): void
    {
        $center = $device->safe_zone_center;

        $this->raiseAlert($device, AlertType::GeofenceExit, $location, smsSent: false, payload: [
            'distance_m' => $center ? round($this->geofence->distanceMeters($center, $location)) : null,
            'radius_m' => $device->safe_zone_radius_m,
        ]);
    }

    private function markSeen(Device $device): void
    {
        $device->last_seen_at = now();

        if ($device->status === DeviceStatus::Offline) {
            $device->status = DeviceStatus::Online;
        }
    }
}
