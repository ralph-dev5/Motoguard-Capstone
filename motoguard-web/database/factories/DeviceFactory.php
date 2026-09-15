<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use App\Support\GeoPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Honda Click 125', 'Yamaha NMAX', 'Suzuki Raider 150', 'Kawasaki Barako']),
            'plate_number' => strtoupper(fake()->bothify('??? ####')),
            'serial' => 'MG-'.strtoupper(fake()->unique()->bothify('########')),
            'owner_phone' => fake()->numerify('+639#########'),
            'is_armed' => true,
            'status' => DeviceStatus::Offline,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['status' => DeviceStatus::Online, 'last_seen_at' => now()]);
    }

    public function withSafeZone(float $lat, float $lng, int $radiusMeters = 200): static
    {
        return $this->state(fn () => [
            'safe_zone_center' => new GeoPoint($lat, $lng),
            'safe_zone_radius_m' => $radiusMeters,
        ]);
    }
}
