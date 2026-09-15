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
            'battery_voltage' => ['nullable', 'numeric', 'between:0,30'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }
}
