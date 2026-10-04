<?php

namespace App\Http\Controllers\Api\Device;

use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreAlertRequest;
use App\Services\DeviceTelemetry;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

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
            $this->occurredAt($request),
        );

        return response()->json(['id' => $alert->id], 201);
    }

    /**
     * When a late alert really happened, or null for one reported as it happens.
     */
    private function occurredAt(StoreAlertRequest $request): ?CarbonInterface
    {
        if (! $request->boolean('recorded_offline')) {
            return null;
        }

        if ($request->filled('occurred_at')) {
            return Date::parse($request->validated('occurred_at'));
        }

        // No GPS clock and the device restarted since: the upload time is the best there is.
        return now()->subSeconds((int) $request->validated('age_s', 0));
    }
}
