<?php

namespace App\Http\Controllers\Api\Device;

use App\Events\DeviceStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trades a pairing code shown on the dashboard for the device's firmware token, so the owner never
 * has to type the token itself. Unauthenticated by design (the device has no token yet), so the
 * code is single use, expires, and the route is tightly throttled.
 */
class PairController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        // Forgive the ways a code gets typed on a phone: lower case, spaces, a dash in the middle.
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->string('code')));

        $device = Device::query()->where('pairing_code', $code)->first();

        if (! $device?->hasValidPairingCode()) {
            return response()->json(['message' => 'That pairing code is wrong or has expired. Get a new one from the dashboard.'], 422);
        }

        $token = $device->issueToken();
        $device->update(['pairing_code' => null, 'pairing_code_expires_at' => null]);

        // The device page is showing the code and waiting; this refreshes it straight away.
        DeviceStatusChanged::dispatch($device);

        return response()->json(['token' => $token, 'device' => ['id' => $device->id, 'name' => $device->name]], 201);
    }
}
