<?php

namespace Database\Factories;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Device;
use App\Support\GeoPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'type' => AlertType::Movement,
            'location' => new GeoPoint(14.5995 + fake()->randomFloat(4, -0.01, 0.01), 120.9842 + fake()->randomFloat(4, -0.01, 0.01)),
            'payload' => ['accel_delta' => 2.4, 'tilt_deg' => 3.0],
            'sms_sent' => true,
        ];
    }
}
