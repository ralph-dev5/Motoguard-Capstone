<?php

namespace App\Enums;

/**
 * Whether the motorcycle is really being guarded, combining the dashboard switch (is_armed)
 * with what the device last reported. The switch alone says "Armed" for up to a heartbeat
 * before the device has heard about it, while the owner's phone holds the alarm off, and while
 * the device is switched off - none of which is guarding.
 */
enum ArmState: string
{
    case Armed = 'armed';
    case Disarmed = 'disarmed';
    case Arming = 'arming';
    case Disarming = 'disarming';
    case OwnerNearby = 'owner_nearby';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Armed => 'Armed',
            self::Disarmed => 'Disarmed',
            self::Arming => 'Arming…',
            self::Disarming => 'Disarming…',
            self::OwnerNearby => 'Paused, owner nearby',
            self::Offline => 'Not guarding, device off',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Armed => 'green',
            self::Arming, self::Disarming => 'amber',
            self::OwnerNearby => 'blue',
            self::Disarmed, self::Offline => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Armed => 'lock-closed',
            self::Arming, self::Disarming => 'arrow-path',
            self::OwnerNearby => 'user',
            self::Disarmed => 'lock-open',
            self::Offline => 'power',
        };
    }

    public function isGuarding(): bool
    {
        return $this === self::Armed;
    }
}
