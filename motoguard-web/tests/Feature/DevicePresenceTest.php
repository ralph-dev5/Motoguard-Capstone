<?php

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Support\DevicePresence;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('a ping marks the device present and is judged on the short window', function () {
    $device = Device::factory()->create(['last_seen_at' => now()->subHour()]);

    $this->postJson(route('api.device.ping'), [], [
        'Authorization' => 'Bearer '.$device->issueToken(),
    ])->assertOk()->assertJson(['ok' => true]);

    [$signal, $threshold] = $device->presenceSignal();

    expect($threshold)->toBe(DevicePresence::offlineAfterSeconds())
        ->and($signal)->not->toBeNull()
        ->and($device->isRecentlySeen())->toBeTrue();
});

test('a device that stops pinging reads offline within the ping window', function () {
    $device = Device::factory()->create(['last_seen_at' => now()]);

    DevicePresence::touch($device->id);
    expect($device->isRecentlySeen())->toBeTrue();

    // Past three missed pings. last_seen_at is still fresh, so this proves the ping window wins
    // over the much wider heartbeat window once a device is known to ping.
    $this->travel(DevicePresence::offlineAfterSeconds() + 1)->seconds();

    expect($device->isRecentlySeen())->toBeFalse();
});

test('a device that never pings still falls back to the heartbeat window', function () {
    $device = Device::factory()->create(['last_seen_at' => now()->subSeconds(10)]);

    [, $threshold] = $device->presenceSignal();

    // Ten seconds of silence would fail the ping window but is fine on a 30 s heartbeat.
    expect($threshold)->toBe(Device::OFFLINE_AFTER_SECONDS)
        ->and($device->isRecentlySeen())->toBeTrue();
});

test('the ping route rejects a token that is not a device token', function () {
    $this->postJson(route('api.device.ping'), [], [
        'Authorization' => 'Bearer 1|notarealsecretatall',
    ])->assertForbidden();
});

test('the ping route rejects a missing token', function () {
    $this->postJson(route('api.device.ping'))->assertForbidden();
});

test('only the first ping after silence reports a reconnect', function () {
    $device = Device::factory()->create();

    expect(DevicePresence::touch($device->id))->toBeTrue()
        ->and(DevicePresence::touch($device->id))->toBeFalse();
});

test('the badge shows a pinging device as on even when its heartbeat is stale', function () {
    // The exact shape of the real board: heartbeats every 30 s, pings every second.
    $device = Device::factory()->create([
        'status' => DeviceStatus::Online,
        'last_seen_at' => now()->subSeconds(25),
    ]);

    DevicePresence::touch($device->id);

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee('Device on');
});
