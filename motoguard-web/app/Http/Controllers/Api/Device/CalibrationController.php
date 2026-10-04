<?php

namespace App\Http\Controllers\Api\Device;

use App\Events\DeviceStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreCalibrationRequest;
use Illuminate\Http\JsonResponse;

class CalibrationController extends Controller
{
    public function __invoke(StoreCalibrationRequest $request): JsonResponse
    {
        $device = $request->device();

        $device->update([
            'calibration' => collect($request->validated())
                ->map(fn ($value, $key) => $key === 'samples' ? (int) $value : round((float) $value, 3))
                ->all(),
            'calibrated_at' => now(),
        ]);

        // The device page is waiting on this; the status event makes it refresh straight away.
        DeviceStatusChanged::dispatch($device);

        return response()->json(['stored' => true], 201);
    }
}
