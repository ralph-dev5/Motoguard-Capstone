<?php

namespace App\Support;

/**
 * The 6-digit PIN the owner's phone asks for when it pairs with a unit over Bluetooth.
 *
 * Pairing needs no app: the unit appears in the phone's Bluetooth settings as "MotoGuard MG-XXXXXX",
 * and once paired the phone reconnects whenever it is near, which tells the unit the owner is there.
 * The PIN keeps strangers from pairing. It is never stored: the firmware and the server both derive
 * it from the unit's ID with the shared enroll secret (HMAC-SHA256 of "pair:" plus the ID, first four
 * bytes as a number, last six digits), so the dashboard can show the same PIN the unit expects.
 */
class BluetoothPin
{
    public static function for(string $serial): ?string
    {
        $secret = (string) config('services.device.enroll_secret');

        if ($secret === '') {
            return null;
        }

        $mac = hash_hmac('sha256', 'pair:'.strtoupper($serial), $secret);

        return str_pad((string) (hexdec(substr($mac, 0, 8)) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
