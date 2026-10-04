<?php

use App\Support\BatteryLevel;

test('it maps the curve anchor points exactly', function (float $volts, int $percent) {
    expect(BatteryLevel::fromVolts($volts)->percent)->toBe($percent);
})->with([
    [4.20, 100],
    [4.06, 85],
    [3.92, 70],
    [3.84, 55],
    [3.79, 40],
    [3.74, 25],
    [3.70, 15],
    [3.60, 5],
    [3.30, 0],
]);

test('it interpolates between anchor points', function () {
    // Halfway between 4.06 V (85%) and 4.20 V (100%).
    expect(BatteryLevel::fromVolts(4.13)->percent)->toBe(93);
});

test('it clamps readings outside the curve', function () {
    expect(BatteryLevel::fromVolts(4.50)->percent)->toBe(100)
        ->and(BatteryLevel::fromVolts(3.00)->percent)->toBe(0);
});

test('it reports the flat part of the curve without stalling', function () {
    // The danger with a linear map: most usable charge sits between 3.9 V and 3.7 V, so
    // these must be clearly different readings rather than all landing on one number.
    $percents = array_map(
        fn (float $volts) => BatteryLevel::fromVolts($volts)->percent,
        [3.90, 3.85, 3.80, 3.75],
    );

    expect($percents)->toBe([66, 57, 43, 28]);
});

test('it asks for a charge at or below 20 percent', function () {
    // 3.72 V is the 20% point, which is what the firmware's LOW_BATTERY_VOLTS is set to.
    expect(BatteryLevel::fromVolts(3.72)->needsCharge())->toBeTrue()
        ->and(BatteryLevel::fromVolts(3.74)->needsCharge())->toBeFalse();
});

test('it flags a critical cell', function () {
    expect(BatteryLevel::fromVolts(3.60)->isCritical())->toBeTrue()
        ->and(BatteryLevel::fromVolts(3.70)->isCritical())->toBeFalse();
});

test('it colours the badge by charge state', function (float $volts, string $color) {
    expect(BatteryLevel::fromVolts($volts)->color())->toBe($color);
})->with([
    [4.20, 'green'],
    [3.84, 'green'],
    [3.79, 'amber'],
    [3.74, 'amber'],
    [3.72, 'red'],
    [3.30, 'red'],
]);

test('it labels the percentage', function () {
    expect(BatteryLevel::fromVolts(3.92)->label())->toBe('70%');
});
