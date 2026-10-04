<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Live updates are a convenience on top of the database, which is the record: pages also poll, and
 * an alert is already saved before it is broadcast. So a Reverb server that is down must not fail
 * the request that triggered the broadcast (an owner acknowledging alerts, or worse, the ESP32
 * reporting a theft). Failures are logged and broadcasting pauses briefly, because each attempt
 * against a dead server costs a ~2 s connect timeout.
 */
class ResilientPusherBroadcaster extends PusherBroadcaster
{
    private const PAUSE_KEY = 'broadcasting:paused';

    private const PAUSE_SECONDS = 30;

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if (Cache::get(self::PAUSE_KEY)) {
            return;
        }

        try {
            parent::broadcast($channels, $event, $payload);
        } catch (BroadcastException $exception) {
            Cache::put(self::PAUSE_KEY, true, self::PAUSE_SECONDS);

            Log::warning('Broadcasting paused for '.self::PAUSE_SECONDS.' s: is Reverb running? (php artisan reverb:start)', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
