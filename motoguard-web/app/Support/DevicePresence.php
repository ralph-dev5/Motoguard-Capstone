<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Liveness for the on/off badge, resolved entirely from the cache.
 *
 * The 30 s heartbeat carries telemetry and writes to Postgres, which costs ~2 s per request
 * against a database in another region — far too slow to answer "is it still powered?" every
 * second. Devices therefore send a separate ping that touches nothing but the local cache, so
 * presence resolves in milliseconds and an unplugged board shows as off within a few seconds.
 */
final class DevicePresence
{
    /**
     * How long without a ping before the device is called gone. Configurable because the safe
     * value depends entirely on how fast the web server answers: see config/presence.php.
     */
    public static function offlineAfterSeconds(): int
    {
        return max(2, (int) config('presence.offline_after_seconds', 6));
    }

    public static function pingIntervalSeconds(): int
    {
        return max(1, (int) config('presence.ping_interval_seconds', 1));
    }

    /**
     * How long a ping is remembered. Far beyond the offline window on purpose: the stored
     * timestamp is also what tells the UI that this device pings at all, and forgetting it too
     * early would quietly send the badge back to the much slower heartbeat threshold.
     */
    private const RETENTION_MINUTES = 10;

    /**
     * Resolved tokens are cached so the ping route never queries personal_access_tokens. The
     * tradeoff is that a revoked token keeps working until this expires, so it stays short.
     */
    private const TOKEN_CACHE_SECONDS = 60;

    /**
     * Records a ping. Returns true only on the absent → present edge, so callers can broadcast
     * a reconnect without paying for an event on every healthy ping.
     */
    public static function touch(int $deviceId): bool
    {
        $wasOnline = self::isOnline($deviceId);

        Cache::put(self::key($deviceId), now()->getTimestamp(), now()->addMinutes(self::RETENTION_MINUTES));

        return ! $wasOnline;
    }

    public static function lastPingAt(int $deviceId): ?int
    {
        $timestamp = Cache::get(self::key($deviceId));

        return is_int($timestamp) ? $timestamp : null;
    }

    public static function isOnline(int $deviceId): bool
    {
        $timestamp = self::lastPingAt($deviceId);

        return $timestamp !== null && (now()->getTimestamp() - $timestamp) < self::offlineAfterSeconds();
    }

    /**
     * Maps a bearer token to its device and owner without hitting the database on every ping.
     *
     * The owner id is cached alongside the device id so the ping can be broadcast to the right
     * private channels without loading the model, which would reintroduce the query this whole
     * route exists to avoid.
     *
     * Misses are cached as false as well as hits, so a wrong token cannot be used to force a
     * database lookup once per request.
     *
     * @return array{device: int, user: int}|null
     */
    public static function resolveDevice(?string $bearer): ?array
    {
        if ($bearer === null || ! str_contains($bearer, '|')) {
            return null;
        }

        [, $secret] = explode('|', $bearer, 2);
        $hash = hash('sha256', $secret);

        $cached = Cache::get(self::tokenKey($hash));

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $resolved = self::lookUpDevice($hash);

        Cache::put(self::tokenKey($hash), $resolved ?? false, now()->addSeconds(self::TOKEN_CACHE_SECONDS));

        return $resolved;
    }

    /**
     * @return array{device: int, user: int}|null
     */
    private static function lookUpDevice(string $hash): ?array
    {
        $token = PersonalAccessToken::where('token', $hash)->first();

        if ($token === null || $token->tokenable_type !== Device::class) {
            return null;
        }

        /** @var list<string> $abilities */
        $abilities = $token->abilities ?? [];

        if (! in_array('*', $abilities, true) && ! in_array(Device::TOKEN_ABILITY, $abilities, true)) {
            return null;
        }

        $device = Device::query()->whereKey($token->tokenable_id)->first(['id', 'user_id']);

        if ($device === null) {
            return null;
        }

        return ['device' => (int) $device->id, 'user' => (int) $device->user_id];
    }

    /**
     * Marks that the dashboard changed something the device only learns from its heartbeat reply
     * (arm/disarm). The ping answers "sync" while this is set, and the device heartbeats at once.
     */
    public static function requestSync(int $deviceId): void
    {
        Cache::put(self::syncKey($deviceId), true, now()->addMinutes(self::RETENTION_MINUTES));
    }

    public static function syncRequested(int $deviceId): bool
    {
        return Cache::has(self::syncKey($deviceId));
    }

    public static function clearSync(int $deviceId): void
    {
        Cache::forget(self::syncKey($deviceId));
    }

    private static function syncKey(int $deviceId): string
    {
        return "device-sync:{$deviceId}";
    }

    private static function key(int $deviceId): string
    {
        return "device-presence:{$deviceId}";
    }

    private static function tokenKey(string $hash): string
    {
        return "device-token:{$hash}";
    }
}
