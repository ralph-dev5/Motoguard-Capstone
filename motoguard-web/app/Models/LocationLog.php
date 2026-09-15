<?php

namespace App\Models;

use App\Casts\AsGeoPoint;
use App\Support\GeoPoint;
use Carbon\CarbonImmutable;
use Database\Factories\LocationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $device_id
 * @property GeoPoint $location
 * @property float|null $speed_kmh
 * @property float|null $heading
 * @property int|null $satellites
 * @property CarbonImmutable $recorded_at
 * @property-read Device $device
 */
#[Fillable(['location', 'speed_kmh', 'heading', 'satellites', 'recorded_at'])]
class LocationLog extends Model
{
    /** @use HasFactory<LocationLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => AsGeoPoint::class,
            'speed_kmh' => 'float',
            'heading' => 'float',
            'satellites' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
