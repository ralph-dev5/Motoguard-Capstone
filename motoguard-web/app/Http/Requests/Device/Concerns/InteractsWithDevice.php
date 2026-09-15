<?php

namespace App\Http\Requests\Device\Concerns;

use App\Models\Device;
use App\Support\GeoPoint;

trait InteractsWithDevice
{
    public function device(): Device
    {
        $device = $this->user('sanctum');

        if (! $device instanceof Device) {
            abort(403, 'A device token is required.');
        }

        return $device;
    }

    public function point(): ?GeoPoint
    {
        return $this->filled(['lat', 'lng'])
            ? new GeoPoint($this->float('lat'), $this->float('lng'))
            : null;
    }
}
