<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use Illuminate\Console\Command;

class MarkOfflineDevices extends Command
{
    protected $signature = 'devices:mark-offline';

    protected $description = 'Mark online devices as offline when their heartbeats stop';

    public function handle(): int
    {
        // Devices in "alert" keep that status until the owner acknowledges it, even if they go silent.
        $devices = Device::query()
            ->where('status', DeviceStatus::Online)
            ->where(fn ($query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subSeconds(Device::OFFLINE_AFTER_SECONDS)))
            ->get();

        foreach ($devices as $device) {
            $device->update(['status' => DeviceStatus::Offline]);
            DeviceStatusChanged::dispatch($device);
        }

        $this->info("Marked {$devices->count()} device(s) offline.");

        return self::SUCCESS;
    }
}
