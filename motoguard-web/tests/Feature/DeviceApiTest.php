<?php

use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Enums\DeviceStatus;
use App\Events\AlertTriggered;
use App\Jobs\SendAlertSms;
use App\Models\Device;
use App\Models\User;
use App\Services\DeviceTelemetry;
use App\Support\GeoPoint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

test('device endpoints require a token', function () {
    $this->postJson(route('api.device.heartbeat'))->assertUnauthorized();
});

test('user tokens cannot use device endpoints', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->postJson(route('api.device.heartbeat'))->assertForbidden();
});

test('device tokens without the report ability are rejected', function () {
    Sanctum::actingAs(Device::factory()->create(), []);

    $this->postJson(route('api.device.heartbeat'))->assertForbidden();
});

test('an issued firmware token can report', function () {
    $device = Device::factory()->create();

    $this->withToken($device->issueToken())
        ->postJson(route('api.device.heartbeat'))
        ->assertOk();
});

test('heartbeat marks the device online and returns the arm state and parking zone', function () {
    $device = Device::factory()->withSafeZone(14.5995, 120.9842, 300)->create(['is_armed' => false]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.heartbeat'), ['battery_voltage' => 12.4, 'lat' => 14.5995, 'lng' => 120.9842])
        ->assertOk()
        ->assertJsonPath('armed', false)
        ->assertJsonPath('safe_zone', null);

    $device->refresh();

    expect($device->status)->toBe(DeviceStatus::Online)
        ->and($device->battery_voltage)->toBe(12.4)
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->last_location->lat)->toEqualWithDelta(14.5995, 0.000001)
        ->and($device->last_location->lng)->toEqualWithDelta(120.9842, 0.000001);
});

test('device alerts are stored and broadcast', function () {
    Event::fake([AlertTriggered::class]);
    Bus::fake();

    $device = Device::factory()->online()->create();
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), [
        'type' => 'movement',
        'lat' => 14.6,
        'lng' => 121.0,
        'sms_sent' => true,
        'payload' => ['accel_delta' => 3.1],
    ])->assertCreated();

    $alert = $device->alerts()->sole();

    expect($device->refresh()->status)->toBe(DeviceStatus::Alert)
        ->and($alert->type)->toBe(AlertType::Movement)
        ->and($alert->location->lat)->toEqualWithDelta(14.6, 0.000001)
        ->and($alert->payload)->toBe(['accel_delta' => 3.1]);

    Event::assertDispatched(AlertTriggered::class);
    Bus::assertNotDispatched(SendAlertSms::class);
});

test('the server sends a backup SMS when the device could not', function () {
    Bus::fake();

    $device = Device::factory()->create();
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'tilt', 'sms_sent' => false])->assertCreated();

    Bus::assertDispatched(SendAlertSms::class);
});

test('a minor episode is recorded without alarming anyone', function () {
    Event::fake([AlertTriggered::class]);
    Bus::fake();

    $device = Device::factory()->online()->create();
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'vibration', 'level' => 'minor', 'sms_sent' => false])
        ->assertCreated();

    $alert = $device->alerts()->sole();

    expect($alert->level)->toBe(AlertLevel::Minor)
        ->and($alert->acknowledged_at)->not->toBeNull()
        ->and($device->refresh()->status)->toBe(DeviceStatus::Online);

    Event::assertNotDispatched(AlertTriggered::class);
    Bus::assertNotDispatched(SendAlertSms::class);
});

test('a suspicious episode alerts the dashboard but does not text the owner', function () {
    Event::fake([AlertTriggered::class]);
    Bus::fake();

    $device = Device::factory()->online()->create();
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'tilt', 'level' => 'suspicious', 'sms_sent' => false])
        ->assertCreated();

    expect($device->alerts()->sole()->acknowledged_at)->toBeNull()
        ->and($device->refresh()->status)->toBe(DeviceStatus::Alert);

    Event::assertDispatched(AlertTriggered::class);
    Bus::assertNotDispatched(SendAlertSms::class);
});

test('a theft attempt texts the owner when the device could not', function () {
    Bus::fake();

    $device = Device::factory()->create();
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'movement', 'level' => 'theft_attempt', 'sms_sent' => false])
        ->assertCreated();

    expect($device->alerts()->sole()->level)->toBe(AlertLevel::TheftAttempt);

    Bus::assertDispatched(SendAlertSms::class);
});

test('an unknown alert level is rejected', function () {
    Sanctum::actingAs(Device::factory()->create(), [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'movement', 'level' => 'catastrophic', 'sms_sent' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('level');
});

test('devices cannot report geofence exits themselves', function () {
    Sanctum::actingAs(Device::factory()->create(), [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'geofence_exit', 'sms_sent' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('leaving the parking zone while armed raises exactly one geofence alert', function () {
    Bus::fake();

    $device = Device::factory()->online()->withSafeZone(14.5995, 120.9842, 200)->create([
        'last_location' => new GeoPoint(14.5995, 120.9842),
    ]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.locations'), ['locations' => [['lat' => 14.6100, 'lng' => 120.9842]]])->assertCreated();
    $this->postJson(route('api.device.locations'), ['locations' => [['lat' => 14.6110, 'lng' => 120.9842]]])->assertCreated();

    $alerts = $device->alerts()->where('type', AlertType::GeofenceExit)->get();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->payload['distance_m'])->toBeGreaterThan(1000)
        ->and($device->locationLogs()->count())->toBe(2);

    Bus::assertDispatched(SendAlertSms::class);
});

test('moving while disarmed does not raise a geofence alert', function () {
    $device = Device::factory()->online()->withSafeZone(14.5995, 120.9842, 200)->create([
        'is_armed' => false,
        'last_location' => new GeoPoint(14.5995, 120.9842),
    ]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.locations'), ['locations' => [['lat' => 14.6100, 'lng' => 120.9842]]])->assertCreated();

    expect($device->alerts()->count())->toBe(0);
});

test('the first GPS fix after arming marks the parking spot without alerting', function () {
    $device = Device::factory()->online()->create(['safe_zone_radius_m' => 150]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.heartbeat'), ['lat' => 14.5995, 'lng' => 120.9842])
        ->assertOk()
        ->assertJsonPath('safe_zone.radius_m', 150);

    $device->refresh();

    expect($device->safe_zone_center->lat)->toEqualWithDelta(14.5995, 0.000001)
        ->and($device->parked_at)->not->toBeNull()
        ->and($device->alerts()->count())->toBe(0);
});

test('arming from the dashboard parks the zone at a fresh GPS fix', function () {
    $device = Device::factory()->online()->create([
        'is_armed' => false,
        'last_location' => new GeoPoint(14.5995, 120.9842),
        'gps_fix_at' => now(),
    ]);

    app(DeviceTelemetry::class)->setArmed($device, true);

    expect($device->refresh()->safe_zone_center->lat)->toEqualWithDelta(14.5995, 0.000001);
});

test('disarming lifts the parking zone', function () {
    $device = Device::factory()->online()->withSafeZone(14.5995, 120.9842)->create();

    app(DeviceTelemetry::class)->setArmed($device, false);

    expect($device->refresh()->hasSafeZone())->toBeFalse()
        ->and($device->parked_at)->toBeNull();
});

test('the owner riding away with their phone does not count as leaving the parking zone', function () {
    $device = Device::factory()->online()->withSafeZone(14.5995, 120.9842, 200)->create([
        'last_location' => new GeoPoint(14.5995, 120.9842),
    ]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.heartbeat'), ['owner_nearby' => true, 'lat' => 14.6100, 'lng' => 120.9842])->assertOk();

    expect($device->alerts()->count())->toBe(0)
        ->and($device->refresh()->hasSafeZone())->toBeFalse();
});

test('devices report what was done to the motorcycle', function (string $type, AlertType $expected) {
    Bus::fake();
    Sanctum::actingAs($device = Device::factory()->online()->create(), [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => $type, 'level' => 'suspicious', 'sms_sent' => false])
        ->assertCreated();

    expect($device->alerts()->first()->type)->toBe($expected);
})->with([
    'lifted' => ['lift', AlertType::Lift],
    'pushed' => ['push', AlertType::Push],
    'touched' => ['touch', AlertType::Touch],
]);

test('acknowledging every alert clears the alert status', function () {
    $device = Device::factory()->online()->create(['status' => DeviceStatus::Alert]);
    $device->alerts()->create(['type' => AlertType::Movement, 'sms_sent' => true]);

    app(DeviceTelemetry::class)->acknowledge($device);

    expect($device->refresh()->status)->toBe(DeviceStatus::Online)
        ->and($device->alerts()->unacknowledged()->count())->toBe(0);
});

test('heartbeat asks the device to calibrate only while a request is unanswered', function () {
    $device = Device::factory()->create(['calibration_requested_at' => now()->subMinute()]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.heartbeat'))->assertOk()->assertJsonPath('calibrate', true);

    $device->update(['calibrated_at' => now()]);

    $this->postJson(route('api.device.heartbeat'))->assertOk()->assertJsonPath('calibrate', false);
});

test('the device reports its calibration', function () {
    $device = Device::factory()->create(['calibration_requested_at' => now()->subMinute()]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.calibration'), [
        'samples' => 300,
        'noise_jerk' => 0.42,
        'noise_shove' => 0.03,
        'noise_rumble' => 0.07,
        'jolt_threshold' => 1.5,
        'push_accel' => 0.3,
        'push_rumble' => 0.25,
    ])->assertCreated();

    $device->refresh();

    expect($device->calibrationPending())->toBeFalse()
        ->and($device->calibration['noise_jerk'])->toBe(0.42)
        ->and($device->motionThresholds())->toBe(['jolt' => 1.5, 'push_accel' => 0.3, 'push_rumble' => 0.25]);
});

test('a calibration report must carry every measurement', function () {
    $device = Device::factory()->create();
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.calibration'), ['samples' => 300])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['noise_jerk', 'jolt_threshold']);
});

test('heartbeat records the owner nearby and hands the device its beacon', function () {
    $device = Device::factory()->create(['owner_beacon' => '5f3c9a2e-1111-4222-8333-944455556666']);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.heartbeat'), ['state' => 'disarmed', 'owner_nearby' => true])
        ->assertOk()
        ->assertJsonPath('owner_beacon', '5f3c9a2e-1111-4222-8333-944455556666');

    $device->refresh();

    expect($device->owner_nearby)->toBeTrue()
        ->and($device->owner_seen_at)->not->toBeNull()
        ->and($device->is_armed)->toBeTrue();
});
