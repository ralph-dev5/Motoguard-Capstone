<?php

namespace App\Enums;

/**
 * How threatening a motion episode was, as classified by the device from what its sensors saw
 * over the whole episode rather than from any single reading. Only a theft attempt is worth
 * waking the owner for; the lower levels exist so that a passer-by brushing the seat is still
 * on record without paging anyone.
 */
enum AlertLevel: string
{
    case Minor = 'minor';
    case Suspicious = 'suspicious';
    case TheftAttempt = 'theft_attempt';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::Suspicious => 'Suspicious',
            self::TheftAttempt => 'Theft attempt',
        };
    }

    /**
     * Flux badge color.
     */
    public function color(): string
    {
        return match ($this) {
            self::Minor => 'zinc',
            self::Suspicious => 'amber',
            self::TheftAttempt => 'red',
        };
    }

    public function notifiesOwner(): bool
    {
        return $this === self::TheftAttempt;
    }

    /**
     * Whether the device should show as "in alert". Minor episodes are filtered noise: they are
     * stored already acknowledged and never turn the dashboard red.
     */
    public function raisesAlarm(): bool
    {
        return $this !== self::Minor;
    }
}
