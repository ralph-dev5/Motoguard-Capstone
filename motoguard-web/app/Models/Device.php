<?php

namespace App\Models;

use App\Casts\AsGeoPoint;
use App\Enums\ArmState;
use App\Enums\DeviceStatus;
use App\Support\BatteryLevel;
use App\Support\DevicePresence;
use App\Support\GeoPoint;
use App\Support\GpsStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $plate_number
 * @property string $serial
 * @property string|null $owner_phone
 * @property string|null $owner_beacon
 * @property string|null $hotspot_ssid
 * @property string|null $hotspot_password
 * @property bool $owner_nearby
 * @property CarbonImmutable|null $owner_seen_at
 * @property bool $is_armed
 * @property bool|null $reported_armed
 * @property CarbonImmutable|null $calibration_requested_at
 * @property CarbonImmutable|null $calibrated_at
 * @property array{samples: int, noise_jerk: float, noise_shove: float, noise_rumble: float, jolt_threshold: float, push_accel: float, push_rumble: float}|null $calibration
 * @property DeviceStatus $status
 * @property float|null $battery_voltage
 * @property int|null $gps_chars
 * @property int|null $gps_satellites
 * @property CarbonImmutable|null $gps_fix_at
 * @property CarbonImmutable|null $last_seen_at
 * @property GeoPoint|null $last_location
 * @property GeoPoint|null $safe_zone_center
 * @property int|null $safe_zone_radius_m
 * @property CarbonImmutable|null $parked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['hotspot_password'])]
#[Fillable(['name', 'plate_number', 'serial', 'owner_phone', 'owner_beacon', 'hotspot_ssid', 'hotspot_password', 'owner_nearby', 'owner_seen_at', 'is_armed', 'reported_armed', 'calibration_requested_at', 'calibrated_at', 'calibration', 'status', 'battery_voltage', 'gps_chars', 'gps_satellites', 'gps_fix_at', 'last_seen_at', 'last_location', 'safe_zone_center', 'safe_zone_radius_m', 'parked_at'])]
class Device extends Model implements AuthenticatableContract
{
    /** @use HasFactory<DeviceFactory> */
    use Authenticatable, HasApiTokens, HasFactory;

    public const TOKEN_ABILITY = 'device:report';

    /** Radius of the automatic parking zone when the owner has not picked one, in metres. */
    public const PARKING_RADIUS_DEFAULT = 100;

    /**
     * Two and a half missed heartbeats at the firmware's 30 s cadence (HEARTBEAT_INTERVAL_MS).
     *
     * One dropped request leaves a 60 s gap, which stays inside this window, so flaky WiFi does
     * not flap the badge; two consecutive misses is a real problem and should show. Detection
     * cannot be faster than the heartbeat interval itself, so shortening this further means
     * lowering HEARTBEAT_INTERVAL_MS in the firmware rather than tightening the window here.
     */
    public const OFFLINE_AFTER_SECONDS = 75;

    /**
     * The owner's phone was heard over Bluetooth by a device that is still reporting in. A device
     * that went silent keeps its last flag in the column, so presence gates it.
     */
    public function ownerIsNearby(): bool
    {
        return $this->owner_nearby && $this->isRecentlySeen();
    }

    /**
     * Asked for on the dashboard and not yet answered by the device.
     */
    public function calibrationPending(): bool
    {
        return $this->calibration_requested_at !== null
            && ($this->calibrated_at === null || $this->calibrated_at->lt($this->calibration_requested_at));
    }

    /**
     * The thresholds the device set from its last calibration.
     *
     * @return array{jolt: float, push_accel: float, push_rumble: float}|null
     */
    public function motionThresholds(): ?array
    {
        if ($this->calibration === null) {
            return null;
        }

        return [
            'jolt' => (float) $this->calibration['jolt_threshold'],
            'push_accel' => (float) $this->calibration['push_accel'],
            'push_rumble' => (float) $this->calibration['push_rumble'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_armed' => 'boolean',
            'reported_armed' => 'boolean',
            'hotspot_password' => 'encrypted',
            'owner_nearby' => 'boolean',
            'owner_seen_at' => 'datetime',
            'calibration_requested_at' => 'datetime',
            'calibrated_at' => 'datetime',
            'calibration' => 'array',
            'status' => DeviceStatus::class,
            'battery_voltage' => 'float',
            'gps_chars' => 'integer',
            'gps_satellites' => 'integer',
            'gps_fix_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_location' => AsGeoPoint::class,
            'safe_zone_center' => AsGeoPoint::class,
            'safe_zone_radius_m' => 'integer',
            'parked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * @return HasMany<LocationLog, $this>
     */
    public function locationLogs(): HasMany
    {
        return $this->hasMany(LocationLog::class);
    }

    /**
     * Whether the motorcycle is being guarded right now: armed, and the owner's phone is not
     * nearby. Only then is it "parked"; the owner riding it away must never look like a theft.
     */
    public function isGuarding(): bool
    {
        return $this->is_armed && ! $this->owner_nearby;
    }

    public function parkingRadius(): int
    {
        return $this->safe_zone_radius_m ?? self::PARKING_RADIUS_DEFAULT;
    }

    /**
     * The parking zone exists once a guarded motorcycle has had a GPS fix to anchor it to.
     */
    public function hasSafeZone(): bool
    {
        return $this->safe_zone_center !== null;
    }

    /**
     * @return array{lat: float, lng: float, radius_m: int}|null
     */
    public function safeZonePayload(): ?array
    {
        if ($this->safe_zone_center === null) {
            return null;
        }

        return [...$this->safe_zone_center->toArray(), 'radius_m' => $this->parkingRadius()];
    }

    /**
     * Charge level of the onboard cell, or null when the device has never reported a voltage
     * (battery monitoring is compiled out of the firmware until the divider is wired).
     */
    public function battery(): ?BatteryLevel
    {
        return $this->battery_voltage === null
            ? null
            : BatteryLevel::fromVolts($this->battery_voltage);
    }

    /**
     * State of the GPS receiver for the dashboard badge.
     *
     * A fix counts as current only if it arrived with a recent heartbeat: gps_fix_at is kept
     * separate from last_location precisely because the location is never cleared, so an old
     * position would otherwise read as a live lock forever.
     */
    public function gpsStatus(): GpsStatus
    {
        if ($this->gps_chars === null) {
            return GpsStatus::Unknown;
        }

        if ($this->gps_chars === 0) {
            return GpsStatus::NoData;
        }

        if ($this->gps_fix_at !== null && $this->last_seen_at !== null
            && $this->gps_fix_at->gte($this->last_seen_at->subSeconds(self::OFFLINE_AFTER_SECONDS))) {
            return GpsStatus::Fix;
        }

        return ($this->gps_satellites ?? 0) > 0 ? GpsStatus::Acquiring : GpsStatus::Searching;
    }

    public function isRecentlySeen(): bool
    {
        [$signal, $threshold] = $this->presenceSignal();

        return $signal !== null && (now()->getTimestamp() - $signal) < $threshold;
    }

    /**
     * The timestamp presence is judged from, and the window allowed around it.
     *
     * A device running firmware that sends the once-a-second ping is judged on those, so pulling
     * its power shows within seconds. One that only heartbeats falls back to last_seen_at and the
     * much wider heartbeat window, because at 30 s between signals anything tighter would flap.
     *
     * @return array{0: int|null, 1: int}
     */
    public function presenceSignal(): array
    {
        $ping = DevicePresence::lastPingAt($this->id);

        if ($ping !== null) {
            return [$ping, DevicePresence::offlineAfterSeconds()];
        }

        return [$this->last_seen_at?->getTimestamp(), self::OFFLINE_AFTER_SECONDS];
    }

    /**
     * What to show the owner right now.
     *
     * The stored status column only becomes Offline when devices:mark-offline runs, so on any
     * machine without a scheduler a device that lost power still reads "Online" forever. Presence
     * is derived from last_seen_at instead, which needs no background job to stay honest.
     *
     * Alert is kept as-is: it is a state the firmware reported, not something a clock can infer,
     * and it stays until the owner acknowledges it even after the device goes silent.
     */
    public function liveStatus(): DeviceStatus
    {
        if ($this->status === DeviceStatus::Alert) {
            return DeviceStatus::Alert;
        }

        return $this->isRecentlySeen() ? DeviceStatus::Online : DeviceStatus::Offline;
    }

    /**
     * Whether it is really being guarded right now, not just what the dashboard switch says.
     * A device that has never reported its state (older firmware) is trusted to follow the switch.
     */
    public function armState(): ArmState
    {
        if (! $this->isRecentlySeen()) {
            return $this->is_armed ? ArmState::Offline : ArmState::Disarmed;
        }

        $reported = $this->reported_armed ?? $this->is_armed;

        if (! $this->is_armed) {
            return $reported ? ArmState::Disarming : ArmState::Disarmed;
        }

        if ($this->owner_nearby) {
            return ArmState::OwnerNearby;
        }

        return $reported ? ArmState::Armed : ArmState::Arming;
    }

    /**
     * How the device is called wherever it is listed: its ID, with the plate beside it once the
     * owner has added one. The ID alone says nothing to someone with more than one motorcycle.
     */
    public function label(): string
    {
        return $this->plate_number ? $this->serial.' · '.$this->plate_number : $this->serial;
    }

    /** What a board's built-in ID looks like: MG- and the last six hex digits of its chip address. */
    public const SERIAL_PATTERN = '/^MG-[0-9A-F]{6}$/';

    /**
     * Replaces the firmware token; the old one stops working immediately.
     */
    public function issueToken(): string
    {
        $this->tokens()->where('name', 'firmware')->delete();

        return $this->createToken('firmware', [self::TOKEN_ABILITY])->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function livePayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plate_number' => $this->plate_number,
            'status' => $this->liveStatus()->value,
            'is_armed' => $this->is_armed,
            'arm_state' => $this->armState()->value,
            'arm_label' => $this->armState()->label(),
            'battery_voltage' => $this->battery_voltage,
            'battery_percent' => $this->battery()?->percent,
            'gps_status' => $this->gpsStatus()->value,
            'gps_satellites' => $this->gps_satellites,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'location' => $this->last_location?->toArray(),
            // last_location is never cleared, so on its own the map would keep drawing a position
            // from days ago as though the receiver were reporting it now. These two say how old
            // the pin is and whether the receiver currently stands behind it.
            'location_at' => $this->gps_fix_at?->toIso8601String(),
            'location_is_live' => $this->gpsStatus() === GpsStatus::Fix,
        ];
    }
}
