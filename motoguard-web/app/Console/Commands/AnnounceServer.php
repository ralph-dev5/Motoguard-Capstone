<?php

namespace App\Console\Commands;

use App\Support\ServerAddress;
use Illuminate\Console\Command;

/**
 * Tells MotoGuard+ devices on the local network where this server is, so the owner never types an
 * IP address and a device follows the PC when its address changes (a new WiFi, a phone hotspot).
 * Sends a tiny UDP broadcast every few seconds; the firmware listens for it on DISCOVERY_PORT.
 */
class AnnounceServer extends Command
{
    /** Must match DISCOVERY_PORT in the firmware's config.h. */
    public const DISCOVERY_PORT = 47100;

    protected $signature = 'motoguard:announce
        {--port=8000 : Port the web server listens on}
        {--interval=2 : Seconds between announcements}';

    protected $description = 'Broadcast this server\'s address on the local network so devices find it by themselves';

    public function handle(): int
    {
        if (! function_exists('socket_create')) {
            $this->error('The PHP sockets extension is off. Enable extension=sockets in php.ini.');

            return self::FAILURE;
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);

        $port = (int) $this->option('port');
        $interval = max(1, (int) $this->option('interval'));
        $message = json_encode(['motoguard' => 1, 'port' => $port]);

        $this->info("Announcing port {$port} on UDP ".self::DISCOVERY_PORT." every {$interval} s. Leave this running.");

        $lastIp = null;
        while (true) {
            // Re-read every round: the whole point is to keep working after the PC changes network.
            $ip = ServerAddress::lanIp();
            if ($ip !== $lastIp) {
                $this->line('Server address now '.($ip ?? 'unknown (no network?)').':'.$port);
                $lastIp = $ip;
            }

            // 255.255.255.255 only leaves through Windows' default interface; the subnet broadcast
            // reaches the network the PC is actually on when it has several adapters.
            foreach (array_unique(array_filter(['255.255.255.255', $ip ? self::subnetBroadcast($ip) : null])) as $target) {
                @socket_sendto($socket, $message, strlen($message), 0, $target, self::DISCOVERY_PORT);
            }

            sleep($interval);
        }
    }

    /**
     * Home routers and phone hotspots both hand out /24 networks.
     */
    private static function subnetBroadcast(string $ip): string
    {
        return preg_replace('/\.\d+$/', '.255', $ip);
    }
}
