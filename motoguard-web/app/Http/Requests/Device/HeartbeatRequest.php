<?php

namespace App\Http\Requests\Device;

use App\Http\Requests\Device\Concerns\InteractsWithDevice;
use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    use InteractsWithDevice;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'in:armed,disarmed,alert'],
            'battery_voltage' => ['nullable', 'numeric', 'between:0,30'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            // Characters read off the GPS serial line since boot. Zero is meaningful, not missing:
            // it is what says "nothing is arriving", so this must not be coerced away.
            'gps_chars' => ['nullable', 'integer', 'min:0'],
            'gps_satellites' => ['nullable', 'integer', 'between:0,64'],
            // The owner's phone beacon is in Bluetooth range; motion alarms are held off meanwhile.
            'owner_nearby' => ['nullable', 'boolean'],
        ];
    }
}
