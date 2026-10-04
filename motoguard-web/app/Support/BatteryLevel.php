<?php

namespace App\Support;

use JsonSerializable;

/**
 * Charge level of the device's onboard single-cell Li-ion battery.
 *
 * The firmware reports volts; the percentage is worked out here so the curve can be
 * corrected without reflashing every device.
 */
final readonly class BatteryLevel implements JsonSerializable
{
    /** At or below this the owner is told to charge. Matches LOW_BATTERY_VOLTS in the firmware's config.h. */
    public const NEEDS_CHARGE_PERCENT = 20;

    public const CRITICAL_PERCENT = 5;

    /**
     * Discharge curve of a single Li-ion cell, highest first. A linear volts-to-percent map
     * would be badly wrong: the curve is almost flat between 3.9 V and 3.6 V, which is where
     * most of the usable charge sits, so the reading would stall and then collapse.
     *
     * @var list<array{0: float, 1: int}>
     */
    private const CURVE = [
        [4.20, 100],
        [4.06, 85],
        [3.92, 70],
        [3.84, 55],
        [3.79, 40],
        [3.74, 25],
        [3.70, 15],
        [3.60, 5],
        [3.30, 0],
    ];

    private function __construct(
        public float $volts,
        public int $percent,
    ) {}

    public static function fromVolts(float $volts): self
    {
        return new self($volts, self::percentFor($volts));
    }

    private static function percentFor(float $volts): int
    {
        [$highVolts, $highPercent] = self::CURVE[0];

        if ($volts >= $highVolts) {
            return $highPercent;
        }

        foreach (array_slice(self::CURVE, 1) as [$lowVolts, $lowPercent]) {
            if ($volts >= $lowVolts) {
                // Straight line between the two anchor points either side of this reading.
                $span = $highVolts - $lowVolts;
                $position = $span > 0 ? ($volts - $lowVolts) / $span : 0;

                return (int) round($lowPercent + $position * ($highPercent - $lowPercent));
            }

            [$highVolts, $highPercent] = [$lowVolts, $lowPercent];
        }

        return 0;
    }

    public function label(): string
    {
        return $this->percent.'%';
    }

    /**
     * Follows the colour vocabulary of DeviceStatus::color() so badges stay consistent.
     */
    public function color(): string
    {
        return match (true) {
            $this->percent <= self::NEEDS_CHARGE_PERCENT => 'red',
            $this->percent <= 50 => 'amber',
            default => 'green',
        };
    }

    /**
     * The icon fills up as the cell does, so the indicator reads at a glance.
     */
    public function icon(): string
    {
        return match (true) {
            $this->percent <= self::NEEDS_CHARGE_PERCENT => 'battery-0',
            $this->percent <= 50 => 'battery-50',
            default => 'battery-100',
        };
    }

    public function needsCharge(): bool
    {
        return $this->percent <= self::NEEDS_CHARGE_PERCENT;
    }

    public function isCritical(): bool
    {
        return $this->percent <= self::CRITICAL_PERCENT;
    }

    /**
     * @return array{volts: float, percent: int}
     */
    public function toArray(): array
    {
        return ['volts' => $this->volts, 'percent' => $this->percent];
    }

    /**
     * @return array{volts: float, percent: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
