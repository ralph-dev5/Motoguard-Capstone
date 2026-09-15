<?php

namespace App\Casts;

use App\Support\GeoPoint;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<GeoPoint|null, GeoPoint|null>
 */
class AsGeoPoint implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?GeoPoint
    {
        return is_string($value) && $value !== '' ? GeoPoint::fromDatabase($value) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value?->toEwkt();
    }
}
