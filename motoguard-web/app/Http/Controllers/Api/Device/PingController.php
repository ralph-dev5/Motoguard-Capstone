<?php

namespace App\Http\Controllers\Api\Device;

use App\Events\DevicePinged;
use App\Events\DeviceStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\DevicePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        $this->recordNetwork($resolved['device'], $request->query('net'));

        // Every ping, not just the reconnect edge: an open page has no other way to hear that
        // the device is still alive, and would otherwise count past its window and show "off".
        // Reverb only, no database and no queue, so this stays cheap at one a second.
        DevicePinged::dispatch($resolved['device'], $resolved['user'], now()->getTimestamp());

        // sync: a dashboard change (arm/disarm) is waiting in the heartbeat reply, so fetch it now.
        return response()->json(['ok' => true, 'sync' => DevicePresence::syncRequested($resolved['device'])]);
    }

    /**
     * WiFi or the A7670G's 4G data, as the ping reports it (?net=wifi|cellular). The ping is the
     * request that gets through on a weak 4G link, so the dashboard learns of a switch from it
     * rather than waiting for a heartbeat. Written only when it changes, so pings stay cheap.
     */
    private function recordNetwork(int $deviceId, mixed $network): void
    {
        if (! in_array($network, ['wifi', 'cellular'], true)) {
            return;
        }

        $key = "device:{$deviceId}:network";
        if (Cache::get($key) === $network) {
            return;
        }

        $device = Device::find($deviceId);
        if ($device === null) {
            return;
        }

        if ($device->network !== $network) {
            $device->network = $network;
            if ($network === 'cellular') {
                $device->network_name = null;
            }
            $device->save();
            DeviceStatusChanged::dispatch($device);
        }

        Cache::put($key, $network, now()->addMinutes(10));
    }
}
