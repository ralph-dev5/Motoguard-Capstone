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
            $request->validated('state'),
            $request->has('gps_chars') ? $request->integer('gps_chars') : null,
            $request->has('gps_satellites') ? $request->integer('gps_satellites') : null,
            $request->has('owner_nearby') ? $request->boolean('owner_nearby') : null,
        ));
    }
}
