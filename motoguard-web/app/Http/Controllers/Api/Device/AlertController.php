<?php

namespace App\Http\Controllers\Api\Device;

use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreAlertRequest;
use App\Services\DeviceTelemetry;
use Illuminate\Http\JsonResponse;

class AlertController extends Controller
{
    public function __invoke(StoreAlertRequest $request, DeviceTelemetry $telemetry): JsonResponse
    {
        $alert = $telemetry->raiseAlert(
            $request->device(),
            $request->enum('type', AlertType::class),
            $request->point(),
            $request->boolean('sms_sent'),
            $request->validated('payload') ?? [],
            $request->enum('level', AlertLevel::class),
        );

        return response()->json(['id' => $alert->id], 201);
    }
}
