<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DeviceStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public Device $device) {}

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
        return ['device' => $this->device->livePayload()];
    }
}
