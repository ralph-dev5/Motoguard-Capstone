<?php

namespace App\Models;

use App\Casts\AsGeoPoint;
use App\Enums\DeviceStatus;
use App\Support\GeoPoint;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property bool $is_armed
 * @property DeviceStatus $status
 * @property float|null $battery_voltage
 * @property CarbonImmutable|null $last_seen_at
 * @property GeoPoint|null $last_location
 * @property GeoPoint|null $safe_zone_center
 * @property int|null $safe_zone_radius_m
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'plate_number', 'serial', 'owner_phone', 'is_armed', 'status', 'battery_voltage', 'last_seen_at', 'last_location', 'safe_zone_center', 'safe_zone_radius_m'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasApiTokens, HasFactory;

    public const TOKEN_ABILITY = 'device:report';

    public const OFFLINE_AFTER_SECONDS = 120;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_armed' => 'boolean',
            'status' => DeviceStatus::class,
            'battery_voltage' => 'float',
            'last_seen_at' => 'datetime',
            'last_location' => AsGeoPoint::class,
            'safe_zone_center' => AsGeoPoint::class,
            'safe_zone_radius_m' => 'integer',
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

    public function hasSafeZone(): bool
    {
        return $this->safe_zone_center !== null && $this->safe_zone_radius_m !== null;
    }

    /**
     * @return array{lat: float, lng: float, radius_m: int}|null
     */
    public function safeZonePayload(): ?array
    {
        if ($this->safe_zone_center === null || $this->safe_zone_radius_m === null) {
            return null;
        }

        return [...$this->safe_zone_center->toArray(), 'radius_m' => $this->safe_zone_radius_m];
    }

    public function isRecentlySeen(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }

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
            'status' => $this->status->value,
            'is_armed' => $this->is_armed,
            'battery_voltage' => $this->battery_voltage,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'location' => $this->last_location?->toArray(),
        ];
    }
}
