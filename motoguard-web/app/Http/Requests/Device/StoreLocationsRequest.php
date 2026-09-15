<?php

namespace App\Http\Requests\Device;

use App\Http\Requests\Device\Concerns\InteractsWithDevice;
use Illuminate\Foundation\Http\FormRequest;

class StoreLocationsRequest extends FormRequest
{
    use InteractsWithDevice;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'locations' => ['required', 'array', 'min:1', 'max:50'],
            'locations.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.speed_kmh' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'locations.*.heading' => ['nullable', 'numeric', 'between:0,360'],
            'locations.*.satellites' => ['nullable', 'integer', 'min:0', 'max:99'],
            'locations.*.recorded_at' => ['nullable', 'date'],
        ];
    }
}
