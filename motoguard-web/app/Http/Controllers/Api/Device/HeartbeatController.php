<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\HeartbeatRequest;
use App\Services\DeviceTelemetry;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request, DeviceTelemetry $telemetry): JsonResponse
    {
        return response()->json($telemetry->heartbeat(
            $request->device(),
            $request->filled('battery_voltage') ? $request->float('battery_voltage') : null,
            $request->point(),
        ));
    }
}
