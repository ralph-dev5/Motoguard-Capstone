<?php

use App\Enums\AlertType;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

test('the dashboard lists the owners motorcycles', function () {
    $device = Device::factory()->create(['name' => 'Honda Click 125']);

    $this->actingAs($device->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Honda Click 125');
});

test('owners can open their device page', function () {
    $device = Device::factory()->create();

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee($device->serial);
});

test('the dashboard shows the battery charge as a percentage', function () {
    $device = Device::factory()->online()->create(['battery_voltage' => 3.92]);

    $this->actingAs($device->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('70%');
});

test('a device that has never reported a battery reading shows a dash', function () {
    $device = Device::factory()->online()->create(['battery_voltage' => null]);

    $this->actingAs($device->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('%');
});

test('the dashboard counts devices that need charging', function () {
    $user = User::factory()->create();
    Device::factory()->online()->for($user)->create(['battery_voltage' => 3.72]);  // 20%
    Device::factory()->online()->for($user)->create(['battery_voltage' => 4.10]);  // 88%

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Needs charging');

    expect($user->devices->filter(fn (Device $device) => $device->battery()?->needsCharge())->count())->toBe(1);
});

test('the device page shows the battery charge', function () {
    $device = Device::factory()->online()->create(['battery_voltage' => 3.74]);

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee('25%');
});

test('other users cannot open someone elses device', function () {
    $device = Device::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('devices.show', $device))
        ->assertForbidden();
});

test('registering a device issues a firmware token', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::devices.index')
        ->set('name', 'Yamaha NMAX')
        ->set('serial', 'MG-0001')
        ->set('owner_phone', '+639171234567')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSet('newToken', fn (?string $token) => $token !== null && str_contains($token, '|'));

    $device = $user->devices()->sole();

    expect($device->serial)->toBe('MG-0001')
        ->and($device->tokens()->count())->toBe(1);
});

test('the owner can set the parking zone radius', function () {
    $device = Device::factory()->create();

    Livewire::actingAs($device->user)
        ->test('pages::devices.show', ['device' => $device])
        ->set('zoneRadius', 300)
        ->call('saveZoneRadius')
        ->assertHasNoErrors();

    expect($device->refresh()->safe_zone_radius_m)->toBe(300);
});

test('a device whose heartbeats stopped reads offline even while the status column still says online', function () {
    // Reproduces the state the app sits in whenever devices:mark-offline is not scheduled:
    // the column is stale, last_seen_at is the honest signal.
    $device = Device::factory()->create([
        'status' => DeviceStatus::Online,
        'last_seen_at' => now()->subSeconds(Device::OFFLINE_AFTER_SECONDS + 60),
    ]);

    expect($device->liveStatus())->toBe(DeviceStatus::Offline);

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee('Device off');
});

test('a device heartbeating right now reads online', function () {
    $device = Device::factory()->online()->create();

    expect($device->liveStatus())->toBe(DeviceStatus::Online);

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee('Device on');
});

test('a device that has never reported reads offline', function () {
    $device = Device::factory()->create(['last_seen_at' => null]);

    expect($device->liveStatus())->toBe(DeviceStatus::Offline);
});

test('an alerting device keeps its alert badge after it goes silent', function () {
    // Alert outranks presence: the owner still has to see that it happened.
    $device = Device::factory()->create([
        'status' => DeviceStatus::Alert,
        'last_seen_at' => now()->subHour(),
    ]);

    expect($device->liveStatus())->toBe(DeviceStatus::Alert);
});

test('the live payload the map reads carries the derived status', function () {
    $device = Device::factory()->create([
        'status' => DeviceStatus::Online,
        'last_seen_at' => now()->subSeconds(Device::OFFLINE_AFTER_SECONDS + 60),
    ]);

    expect($device->livePayload()['status'])->toBe('offline');
});

test('a heartbeating device still reads on even while it has unacknowledged alerts', function () {
    // The state the real board was in: reporting fine, but 35 open alerts from days earlier.
    // Presence must stay readable instead of being masked by the alert badge.
    $device = Device::factory()->create([
        'status' => DeviceStatus::Alert,
        'last_seen_at' => now(),
    ]);
    $device->alerts()->create([
        'type' => AlertType::Movement,
        'location' => null,
        'sms_sent' => false,
    ]);

    $this->actingAs($device->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertSee('Device on')
        ->assertSee('Alert');
});

test('the owner can ask the device to calibrate', function () {
    $device = Device::factory()->create();

    Livewire::actingAs($device->user)
        ->test('pages::devices.show', ['device' => $device])
        ->call('requestCalibration')
        ->assertSee('Waiting for device');

    expect($device->refresh()->calibrationPending())->toBeTrue();
});

test('the device page shows the calibrated thresholds', function () {
    $device = Device::factory()->create([
        'calibrated_at' => now(),
        'calibration' => ['samples' => 300, 'noise_jerk' => 0.5, 'noise_shove' => 0.1, 'noise_rumble' => 0.1, 'jolt_threshold' => 1.5, 'push_accel' => 0.3, 'push_rumble' => 0.25],
    ]);

    Livewire::actingAs($device->user)
        ->test('pages::devices.show', ['device' => $device])
        ->assertSee('jolt > 1.50');
});

test('the owner can save a Bluetooth beacon ID', function () {
    $device = Device::factory()->create();

    Livewire::actingAs($device->user)
        ->test('pages::devices.show', ['device' => $device])
        ->set('owner_beacon', '5F3C9A2E-1111-4222-8333-944455556666')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($device->refresh()->owner_beacon)->toBe('5f3c9a2e-1111-4222-8333-944455556666');
});

test('a malformed Bluetooth beacon ID is rejected', function () {
    $device = Device::factory()->create();

    Livewire::actingAs($device->user)
        ->test('pages::devices.show', ['device' => $device])
        ->set('owner_beacon', 'my phone')
        ->call('saveDetails')
        ->assertHasErrors(['owner_beacon' => 'regex']);
});
