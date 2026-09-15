<?php

namespace App\Enums;

enum AlertType: string
{
    case Movement = 'movement';
    case Tilt = 'tilt';
    case GeofenceExit = 'geofence_exit';
    case PowerCut = 'power_cut';
    case LowBattery = 'low_battery';

    public function label(): string
    {
        return match ($this) {
            self::Movement => 'Unauthorized movement',
            self::Tilt => 'Tilt / tamper detected',
            self::GeofenceExit => 'Left safe zone',
            self::PowerCut => 'Battery disconnected',
            self::LowBattery => 'Low battery',
        };
    }
}
