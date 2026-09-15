<?php

namespace App\Services;

use App\Models\Device;
use App\Support\GeoPoint;
use Illuminate\Support\Facades\DB;

class GeofenceService
{
    public function isOutside(Device $device, GeoPoint $point): bool
    {
        $center = $device->safe_zone_center;
        $radius = $device->safe_zone_radius_m;

        if ($center === null || $radius === null) {
            return false;
        }

        return ! DB::scalar(
            'select ST_DWithin(cast(? as geography), cast(? as geography), cast(? as double precision))',
            [$point->toEwkt(), $center->toEwkt(), $radius],
        );
    }

    public function distanceMeters(GeoPoint $from, GeoPoint $to): float
    {
        return (float) DB::scalar(
            'select ST_Distance(cast(? as geography), cast(? as geography))',
            [$from->toEwkt(), $to->toEwkt()],
        );
    }
}
