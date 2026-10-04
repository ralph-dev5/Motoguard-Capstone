<?php

namespace App\Models;

use App\Casts\AsGeoPoint;
use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Support\GeoPoint;
use Carbon\CarbonImmutable;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $device_id
 * @property AlertType $type
 * @property AlertLevel|null $level
 * @property GeoPoint|null $location
 * @property array<string, mixed>|null $payload
 * @property bool $sms_sent
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Device $device
 */
#[Fillable(['type', 'level', 'location', 'payload', 'sms_sent', 'acknowledged_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'level' => AlertLevel::class,
            'location' => AsGeoPoint::class,
            'payload' => 'array',
            'sms_sent' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unacknowledged(Builder $query): void
    {
        $query->whereNull('acknowledged_at');
    }

    public function smsMessage(): string
    {
        $where = $this->location?->mapsUrl() ?? 'GPS location not available yet.';

        $what = $this->level ? "{$this->level->label()} - {$this->type->label()}" : $this->type->label();

        return "MotoGuard+ ALERT: {$what} on {$this->device->label()}. {$where}";
    }

    /**
     * @return array<string, mixed>
     */
    public function livePayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->level ? "{$this->level->label()}: {$this->type->label()}" : $this->type->label(),
            'level' => $this->level?->value,
            'device_id' => $this->device_id,
            'device_name' => $this->device->label(),
            'location' => $this->location?->toArray(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
