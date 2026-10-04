<?php

namespace App\Support;

/**
 * What the GPS receiver is doing, as far as the dashboard can tell.
 *
 * A position on its own cannot answer "is the module working?". A receiver that is not wired and
 * one that is sitting indoors both report no location, and on the bench that ambiguity is the
 * expensive one: it sends you looking for a wiring fault when the only problem is a roof. The
 * firmware therefore reports how many characters it has read off the serial line and how many
 * satellites it can hear, which separates the two.
 */
enum GpsStatus: string
{
    /** Firmware has never reported GPS health, so nothing can be said about it. */
    case Unknown = 'unknown';

    /** Serial line is silent: not wired, wrong pins, or wrong baud. */
    case NoData = 'no_data';

    /** Sentences are arriving but the receiver can hear no satellites at all. */
    case Searching = 'searching';

    /** Hearing satellites, still short of the four a position needs. */
    case Acquiring = 'acquiring';

    /** Reporting a current position. */
    case Fix = 'fix';

    /** Satellites needed before the receiver can solve for a position. */
    public const SATELLITES_FOR_FIX = 4;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'GPS unknown',
            self::NoData => 'GPS no data',
            self::Searching => 'GPS searching',
            self::Acquiring => 'GPS acquiring',
            self::Fix => 'GPS locked',
        };
    }

    /** Follows the colour vocabulary of DeviceStatus::color() so badges stay consistent. */
    public function color(): string
    {
        return match ($this) {
            self::Fix => 'green',
            self::Acquiring, self::Searching => 'amber',
            self::NoData => 'red',
            self::Unknown => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Fix => 'map-pin',
            self::Acquiring, self::Searching => 'signal',
            self::NoData => 'exclamation-triangle',
            self::Unknown => 'question-mark-circle',
        };
    }

    /**
     * What the owner should do about it. Empty when nothing is wrong.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Fix => '',
            self::Acquiring => 'Hearing satellites. Needs '.self::SATELLITES_FOR_FIX.' in view for a position.',
            self::Searching => 'Receiver is running but hears no satellites at all. Needs open sky — or the antenna is not working.',
            self::NoData => 'Nothing arriving on the serial line. Check GPS TX to GPIO26, RX to GPIO27, 9600 baud.',
            self::Unknown => 'This device has not reported GPS health yet.',
        };
    }

    /**
     * Wiring is only proven good once characters have actually arrived; satellites and a fix are
     * sky problems beyond that point.
     */
    public function wiringConfirmed(): bool
    {
        return $this === self::Searching || $this === self::Acquiring || $this === self::Fix;
    }
}
