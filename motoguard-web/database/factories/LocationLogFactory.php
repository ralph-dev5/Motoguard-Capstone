<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\LocationLog;
use App\Support\GeoPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationLog>
 */
class LocationLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'location' => new GeoPoint(14.5995 + fake()->randomFloat(4, -0.01, 0.01), 120.9842 + fake()->randomFloat(4, -0.01, 0.01)),
            'speed_kmh' => fake()->randomFloat(1, 0, 60),
            'heading' => fake()->randomFloat(1, 0, 359),
            'satellites' => fake()->numberBetween(4, 12),
            'recorded_at' => now(),
        ];
    }
}
