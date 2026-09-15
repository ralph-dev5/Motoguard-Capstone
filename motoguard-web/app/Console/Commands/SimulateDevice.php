<?php

namespace App\Console\Commands;

use App\Enums\AlertType;
use App\Models\Device;
use App\Support\GeoPoint;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SimulateDevice extends Command
{
    protected $signature = 'device:simulate
        {device : ID of the device to impersonate}
        {--steps=30 : Number of location updates to send}
        {--interval=3 : Seconds between updates}
        {--url= : Base URL of the running app (defaults to APP_URL)}';

    protected $description = 'Act like the ESP32: send heartbeats, a movement alert and a moving route to the device API';

    public function handle(): int
    {
        $device = Device::findOrFail($this->argument('device'));
        $steps = max(1, (int) $this->option('steps'));
        $interval = max(0, (int) $this->option('interval'));
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/api/v1/device';

        // A separate token, so the real firmware token keeps working.
        $token = $device->createToken('simulator', [Device::TOKEN_ABILITY]);
        $http = fn (): PendingRequest => Http::withToken($token->plainTextToken)->acceptJson()->timeout(15);

        $start = $device->safe_zone_center ?? $device->last_location ?? new GeoPoint(14.5995, 120.9842);
        $lat = $start->lat;
        $lng = $start->lng;

        try {
            $this->info("Simulating {$device->name} against {$base}");

            $beat = $http()->post("{$base}/heartbeat", ['battery_voltage' => 12.6, 'lat' => $lat, 'lng' => $lng])->throw()->json();
            $this->line('Heartbeat OK. Armed: '.($beat['armed'] ? 'yes' : 'no'));

            sleep($interval);

            $http()->post("{$base}/alerts", [
                'type' => AlertType::Movement->value,
                'lat' => $lat,
                'lng' => $lng,
                'sms_sent' => false,
                'payload' => ['accel_delta' => 3.2, 'tilt_deg' => 4.1, 'source' => 'simulator'],
            ])->throw();
            $this->warn('Movement alert sent.');

            for ($step = 1; $step <= $steps; $step++) {
                sleep($interval);

                $lat += 0.0004;
                $lng += 0.0003;

                $http()->post("{$base}/locations", ['locations' => [[
                    'lat' => $lat,
                    'lng' => $lng,
                    'speed_kmh' => random_int(20, 45),
                    'heading' => 37,
                    'satellites' => 9,
                    'recorded_at' => now()->toIso8601String(),
                ]]])->throw();

                $this->line(sprintf('[%d/%d] %.6f, %.6f', $step, $steps, $lat, $lng));

                if ($step % 5 === 0) {
                    $http()->post("{$base}/heartbeat", ['battery_voltage' => 12.5, 'lat' => $lat, 'lng' => $lng])->throw();
                }
            }
        } finally {
            $token->accessToken->delete();
        }

        $this->info('Done. Stop sending heartbeats and the device goes offline after about 2 minutes.');

        return self::SUCCESS;
    }
}
