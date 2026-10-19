<?php

namespace App\Http\Controllers\Api\Device;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The board texted the owner for this alert after the alert was already reported, because the
 * first send failed and a retry got through. Marking it here also stops the server's backup text.
 */
class AlertSmsSentController extends Controller
{
    public function __invoke(Request $request, Alert $alert): JsonResponse
    {
        $device = $request->user('sanctum');

        // Another device's alert answers the same as a missing one.
        abort_unless($device instanceof Device && $alert->device_id === $device->id, 404);

        if (! $alert->sms_sent) {
            $alert->update(['sms_sent' => true]);
        }

        return response()->json(['sms_sent' => true]);
    }
}
