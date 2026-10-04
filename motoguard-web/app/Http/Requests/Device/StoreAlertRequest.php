<?php

namespace App\Http\Requests\Device;

use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Http\Requests\Device\Concerns\InteractsWithDevice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRequest extends FormRequest
{
    use InteractsWithDevice;

    /**
     * @return array<string, array<int, string|ValidationRule|object>>
     */
    public function rules(): array
    {
        return [
            // Geofence exits are detected by the server, never reported by the device.
            'type' => ['required', Rule::enum(AlertType::class)->except([AlertType::GeofenceExit])],
            'level' => ['nullable', Rule::enum(AlertLevel::class)],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'sms_sent' => ['required', 'boolean'],
            'payload' => ['nullable', 'array', 'max:20'],
            // An alert recorded while the device had no connection arrives late, with the time it
            // really happened: the GPS clock, or how long ago when the GPS had no time.
            'recorded_offline' => ['nullable', 'boolean'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now +5 minutes'],
            'age_s' => ['nullable', 'integer', 'min:0', 'max:2592000'],
        ];
    }
}
