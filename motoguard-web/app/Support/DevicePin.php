<?php

namespace App\Support;

/**
 * The 6-digit PIN that connects a browser to a MotoGuard+ unit.
 *
 * There are no accounts: whoever knows a unit's ID and PIN controls it, the way tuning software
 * talks to whatever ECU is plugged in. The PIN is not stored anywhere. Both the server and the
 * firmware derive it from the unit's ID with the shared enroll secret (HMAC-SHA256 of "pin:" plus
 * the ID, first four bytes as a number, last six digits), so the board can show it on its setup
 * page and nobody who only read the ID off the board can guess it.
 */
class DevicePin
{
    public static function for(string $serial): ?string
    {
        $secret = (string) config('services.device.enroll_secret');

        if ($secret === '') {
            return null;
        }

        $mac = hash_hmac('sha256', 'pin:'.strtoupper($serial), $secret);

        return str_pad((string) (hexdec(substr($mac, 0, 8)) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function matches(string $serial, string $pin): bool
    {
        $expected = self::for($serial);

        return $expected !== null && hash_equals($expected, $pin);
    }
}
