<?php

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

test('the owner can save a safe zone', function () {
    $device = Device::factory()->create();

    Livewire::actingAs($device->user)
        ->test('pages::devices.show', ['device' => $device])
        ->call('placeZone', 14.5995, 120.9842)
        ->set('zoneRadius', 300)
        ->call('saveZone')
        ->assertHasNoErrors();

    $device->refresh();

    expect($device->safe_zone_radius_m)->toBe(300)
        ->and($device->safe_zone_center->lat)->toEqualWithDelta(14.5995, 0.000001);
});
