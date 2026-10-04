<?php

use App\Models\Device;
use App\Support\GpsStatus;

test('a device whose firmware never reports gps health reads unknown', function () {
    $device = Device::factory()->create(['gps_chars' => null, 'last_seen_at' => now()]);

    expect($device->gpsStatus())->toBe(GpsStatus::Unknown);
});

test('no characters on the serial line points at wiring, not the sky', function () {
    $device = Device::factory()->create(['gps_chars' => 0, 'gps_satellites' => 0, 'last_seen_at' => now()]);

    expect($device->gpsStatus())->toBe(GpsStatus::NoData)
        ->and($device->gpsStatus()->wiringConfirmed())->toBeFalse()
        ->and($device->gpsStatus()->hint())->toContain('GPIO26');
});

test('characters arriving with no satellites means the receiver works but has no sky', function () {
    $device = Device::factory()->create(['gps_chars' => 5928, 'gps_satellites' => 0, 'last_seen_at' => now()]);

    // The distinction the whole feature exists for: wiring is proven, only the sky is missing.
    expect($device->gpsStatus())->toBe(GpsStatus::Searching)
        ->and($device->gpsStatus()->wiringConfirmed())->toBeTrue();
});

test('hearing satellites without a position reads as acquiring', function () {
    $device = Device::factory()->create(['gps_chars' => 9100, 'gps_satellites' => 3, 'last_seen_at' => now()]);

    expect($device->gpsStatus())->toBe(GpsStatus::Acquiring);
});

test('a position reported with the current heartbeat reads as locked', function () {
    $device = Device::factory()->create([
        'gps_chars' => 14000,
        'gps_satellites' => 7,
        'gps_fix_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect($device->gpsStatus())->toBe(GpsStatus::Fix);
});

test('a stale fix does not keep reading as locked', function () {
    // last_location is never cleared, so without gps_fix_at an hours-old position would show as a
    // live lock forever even with the device back indoors.
    $device = Device::factory()->create([
        'gps_chars' => 20000,
        'gps_satellites' => 0,
        'gps_fix_at' => now()->subHours(3),
        'last_seen_at' => now(),
    ]);

    expect($device->gpsStatus())->toBe(GpsStatus::Searching);
});

test('the heartbeat stores gps health reported by the device', function () {
    $device = Device::factory()->create();

    $this->postJson(route('api.device.heartbeat'), [
        'state' => 'armed',
        'gps_chars' => 5928,
        'gps_satellites' => 0,
    ], ['Authorization' => 'Bearer '.$device->issueToken()])->assertOk();

    $device->refresh();

    expect($device->gps_chars)->toBe(5928)
        ->and($device->gps_satellites)->toBe(0)
        ->and($device->gpsStatus())->toBe(GpsStatus::Searching);
});

test('the heartbeat rejects an impossible satellite count', function () {
    $device = Device::factory()->create();

    $this->postJson(route('api.device.heartbeat'), [
        'state' => 'armed',
        'gps_satellites' => 999,
    ], ['Authorization' => 'Bearer '.$device->issueToken()])->assertStatus(422);
});

test('the device page shows the gps indicator', function () {
    $device = Device::factory()->create(['gps_chars' => 5928, 'gps_satellites' => 0, 'last_seen_at' => now()]);

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee('GPS searching');
});
