<?php

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

test('heartbeat marks the device online and returns the arm state and safe zone', function () {
    $device = Device::factory()->withSafeZone(14.5995, 120.9842, 300)->create(['is_armed' => false]);
    Sanctum::actingAs($device, [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.heartbeat'), ['battery_voltage' => 12.4, 'lat' => 14.5995, 'lng' => 120.9842])
        ->assertOk()
        ->assertJsonPath('armed', false)
        ->assertJsonPath('safe_zone.radius_m', 300);

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

test('devices cannot report geofence exits themselves', function () {
    Sanctum::actingAs(Device::factory()->create(), [Device::TOKEN_ABILITY]);

    $this->postJson(route('api.device.alerts'), ['type' => 'geofence_exit', 'sms_sent' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('leaving the safe zone while armed raises exactly one geofence alert', function () {
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

test('acknowledging every alert clears the alert status', function () {
    $device = Device::factory()->online()->create(['status' => DeviceStatus::Alert]);
    $device->alerts()->create(['type' => AlertType::Movement, 'sms_sent' => true]);

    app(DeviceTelemetry::class)->acknowledge($device);

    expect($device->refresh()->status)->toBe(DeviceStatus::Online)
        ->and($device->alerts()->unacknowledged()->count())->toBe(0);
});
