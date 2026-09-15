<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Alert = 'alert';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Alert => 'Alert',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Online => 'green',
            self::Offline => 'zinc',
            self::Alert => 'red',
        };
    }
}
