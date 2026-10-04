<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on every presence ping so an open dashboard can reset its local clock.
 *
 * Without this the page would only learn of continued presence when Livewire next re-rendered,
 * so the badge counted past its window and flipped to "Device off" while the device was happily
 * reporting. Deliberately carries plain ids rather than a Device model: loading one would put
 * the per-second database query back into the path this whole mechanism exists to avoid.
 */
class DevicePinged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $deviceId,
        public int $userId,
        public int $at,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices.'.$this->deviceId),
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.pinged';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId, 'at' => $this->at];
    }
}
