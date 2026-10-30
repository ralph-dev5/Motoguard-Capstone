<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Support\DevicePin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects this browser to a unit by its ID and PIN - the only way into the dashboard.
 *
 * Behind the scenes each unit still has one hidden owner record, so every page keeps working
 * per device. The first connect creates that record and the device (which also lets the board
 * collect its token); later connects, from any phone, open the same device.
 */
class ConnectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // Forgive how an ID gets typed on a phone: lower case, stray spaces, a missing dash.
        $serial = strtoupper(preg_replace('/\s+/', '', (string) $request->input('serial')));
        if (preg_match('/^MG[0-9A-F]{6}$/', $serial)) {
            $serial = 'MG-'.substr($serial, 2);
        }
        $request->merge(['serial' => $serial, 'pin' => preg_replace('/\D/', '', (string) $request->input('pin'))]);

        $request->validate(
            ['serial' => ['required', 'regex:'.Device::SERIAL_PATTERN], 'pin' => ['required', 'digits:6']],
            ['serial.regex' => __('A device ID looks like MG-04A784: MG, a dash, then six letters or digits (0-9, A-F).')],
        );

        // A PIN has a million values; a few tries per unit per hour makes guessing hopeless.
        $key = 'connect:'.$serial;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['pin' => __('Too many wrong PINs for this device. Try again in :minutes minutes.', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ])]);
        }

        if (! DevicePin::matches($serial, $request->input('pin'))) {
            RateLimiter::hit($key, 3600);

            throw ValidationException::withMessages(['pin' => __('The Device ID or PIN is wrong.')]);
        }

        RateLimiter::clear($key);

        $device = Device::query()->where('serial', $serial)->first();
        $owner = $device?->user ?? User::create([
            'name' => $serial,
            'email' => Str::lower($serial).'@device.motoguard',
            'password' => Str::password(40),   // never used: there is no password sign-in
        ]);
        $owner->email_verified_at ??= now();
        $owner->save();

        if ($device === null) {
            $owner->devices()->create(['serial' => $serial, 'name' => $serial]);
        } elseif ($device->user_id === null) {
            $device->user()->associate($owner)->save();
        }

        Auth::login($owner, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
