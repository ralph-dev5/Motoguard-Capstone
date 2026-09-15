<?php

namespace App\Events;

use App\Models\Alert;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AlertTriggered implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public Alert $alert) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices.'.$this->alert->device_id),
            new PrivateChannel('App.Models.User.'.$this->alert->device->user_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['alert' => $this->alert->livePayload()];
    }
}
