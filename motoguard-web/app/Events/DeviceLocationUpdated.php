<?php

namespace App\Events;

use App\Models\Device;
use App\Models\LocationLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DeviceLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public Device $device, public LocationLog $log) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices.'.$this->device->id),
            new PrivateChannel('App.Models.User.'.$this->device->user_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'device' => $this->device->livePayload(),
            'point' => [
                'lat' => $this->log->location->lat,
                'lng' => $this->log->location->lng,
                'speed_kmh' => $this->log->speed_kmh,
                'recorded_at' => $this->log->recorded_at->toIso8601String(),
            ],
        ];
    }
}
