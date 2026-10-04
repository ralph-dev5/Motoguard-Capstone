<?php

namespace App\Http\Controllers\Api\Device;

use App\Events\DevicePinged;
use App\Http\Controllers\Controller;
use App\Support\DevicePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Still here" from a device, once a second.
 *
 * Deliberately does not use auth:sanctum: that resolves the token straight out of Postgres on
 * every request, which is the cost this route exists to avoid. Token resolution is cached
 * instead, so the handler writes one cache key, broadcasts, and returns.
 */
class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $resolved = DevicePresence::resolveDevice($request->bearerToken());

        if ($resolved === null) {
            abort(403, 'A device token is required.');
        }

        DevicePresence::touch($resolved['device']);

        // Every ping, not just the reconnect edge: an open page has no other way to hear that
        // the device is still alive, and would otherwise count past its window and show "off".
        // Reverb only, no database and no queue, so this stays cheap at one a second.
        DevicePinged::dispatch($resolved['device'], $resolved['user'], now()->getTimestamp());

        // sync: a dashboard change (arm/disarm) is waiting in the heartbeat reply, so fetch it now.
        return response()->json(['ok' => true, 'sync' => DevicePresence::syncRequested($resolved['device'])]);
    }
}
