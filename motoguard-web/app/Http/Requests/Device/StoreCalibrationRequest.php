<?php

namespace App\Http\Requests\Device;

use App\Http\Requests\Device\Concerns\InteractsWithDevice;
use Illuminate\Foundation\Http\FormRequest;

class StoreCalibrationRequest extends FormRequest
{
    use InteractsWithDevice;

    /**
     * All in m/s^2. The noise_* values are the largest readings seen while the motorcycle stood
     * still; the other three are the level-3 thresholds the firmware set from them.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'samples' => ['required', 'integer', 'min:1'],
            'noise_jerk' => ['required', 'numeric', 'between:0,100'],
            'noise_shove' => ['required', 'numeric', 'between:0,100'],
            'noise_rumble' => ['required', 'numeric', 'between:0,100'],
            'jolt_threshold' => ['required', 'numeric', 'between:0,100'],
            'push_accel' => ['required', 'numeric', 'between:0,100'],
            'push_rumble' => ['required', 'numeric', 'between:0,100'],
        ];
    }
}
