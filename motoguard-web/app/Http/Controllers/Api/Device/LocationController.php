<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreLocationsRequest;
use App\Services\DeviceTelemetry;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function __invoke(StoreLocationsRequest $request, DeviceTelemetry $telemetry): JsonResponse
    {
        $locations = $request->validated('locations');

        $telemetry->storeLocations($request->device(), $locations);

        return response()->json(['stored' => count($locations)], 201);
    }
}
