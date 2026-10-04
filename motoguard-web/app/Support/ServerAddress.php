<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address a device on the same network should use to reach this server, shown on the setup
 * guide so the owner never has to look up the PC's IP. "localhost" in the browser is useless to
 * the ESP32, so in that case the PC's own LAN address is worked out instead.
 */
final class ServerAddress
{
    /**
     * @return array{host: string, port: int}
     */
    public static function forDevices(Request $request): array
    {
        $host = $request->getHost();

        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $host = self::lanIp() ?? $host;
        }

        return ['host' => $host, 'port' => (int) $request->getPort()];
    }

    /**
     * The IP of the network interface that routes outward. Connecting a UDP socket sends nothing;
     * it only asks the OS which local address it would use.
     */
    public static function lanIp(): ?string
    {
        $socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $error, 1);
        if ($socket === false) {
            return null;
        }

        $local = stream_socket_get_name($socket, false);
        fclose($socket);

        $ip = $local ? substr($local, 0, strrpos($local, ':')) : null;

        return $ip && filter_var($ip, FILTER_VALIDATE_IP) && ! str_starts_with($ip, '127.') ? $ip : null;
    }
}
