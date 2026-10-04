<?php

namespace App\Http\Controllers\Api\Device;

use App\Events\DeviceStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gives a board its firmware token once its owner has added the board's ID on the dashboard.
 *
 * Every board derives its own ID from its chip (MG- plus six hex digits), so there is nothing to
 * type into the firmware and no pairing code. The route is unauthenticated by design - the board
 * has no token yet - so the board proves it runs genuine firmware by sending an HMAC of its ID
 * made with a secret the firmware and the server share. Without that proof anyone who read an ID
 * off a device could request its token and take over its reports.
 */
class EnrollController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serial' => ['required', 'string', 'max:50'],
            'proof' => ['required', 'string', 'size:64'],
        ]);

        $serial = strtoupper(trim($validated['serial']));
        $secret = (string) config('services.device.enroll_secret');

        // No secret configured means nobody can be trusted, rather than everybody.
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $serial, $secret), strtolower($validated['proof']))) {
            return response()->json(['message' => 'This device could not be verified.'], 403);
        }

        $device = Device::query()->where('serial', $serial)->first();

        if ($device === null) {
            // Not an error for the board: it keeps asking until its owner adds the ID.
            return response()->json(['message' => 'This device has not been added to an account yet.'], 404);
        }

        $token = $device->issueToken();

        // The device page is waiting for the board to connect; this refreshes it straight away.
        DeviceStatusChanged::dispatch($device);

        return response()->json(['token' => $token, 'device' => ['id' => $device->id, 'name' => $device->name]], 201);
    }
}
