<?php

namespace App\Enums;

enum AlertType: string
{
    // What the device felt, in the owner's words. Vibration and Movement are what firmware sent
    // before it told pushes, lifts and touches apart; they stay so older alerts still read.
    case Touch = 'touch';
    case Push = 'push';
    case Lift = 'lift';
    case Tilt = 'tilt';
    case Vibration = 'vibration';
    case Movement = 'movement';
    case GeofenceExit = 'geofence_exit';
    case PowerCut = 'power_cut';
    case LowBattery = 'low_battery';

    public function label(): string
    {
        return match ($this) {
            self::Touch => 'Touched / bumped',
            self::Push => 'Pushed',
            self::Lift => 'Lifted',
            self::Tilt => 'Tilted',
            self::Vibration => 'Knock or vibration',
            self::Movement => 'Unauthorized movement',
            self::GeofenceExit => 'Moved from parking spot',
            self::PowerCut => 'Battery disconnected',
            self::LowBattery => 'Low battery',
        };
    }
}
