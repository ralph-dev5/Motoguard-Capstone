<?php

use App\Livewire\Actions\Logout;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Devices')] class extends Component {
    /**
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        return Auth::user()->devices()
            ->withCount(['alerts as open_alerts_count' => fn ($query) => $query->whereNull('acknowledged_at')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Removes the unit with its history and disconnects this browser. The hidden owner record goes
     * too, so connecting again with the ID and PIN starts from a clean device.
     */
    public function deleteDevice(int $deviceId, Logout $logout): void
    {
        $owner = Auth::user();
        $device = $owner->devices()->findOrFail($deviceId);
        $device->tokens()->delete();
        $device->delete();

        $logout();
        $owner->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="w-full space-y-6">
    <x-page-header :title="__('Devices')" :description="__('The MotoGuard+ unit this phone is connected to.')" icon="map-pin" />

    {{-- Phones get one card per device; the table pushed status and actions off-screen. --}}
    <ul class="space-y-3 md:hidden">
        @forelse ($this->devices as $device)
            <li wire:key="device-card-{{ $device->id }}" class="moto-card space-y-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('devices.show', $device) }}" wire:navigate class="font-mono font-semibold text-zinc-900 hover:underline dark:text-white">{{ $device->serial }}</a>
                        <flux:text class="text-xs">{{ $device->plate_number ?? __('No plate number yet') }}</flux:text>
                    </div>
                    @if ($device->open_alerts_count > 0)
                        <a href="{{ route('alerts.index', ['device' => $device->id, 'state' => 'open']) }}" wire:navigate class="shrink-0">
                            <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ number_format($device->open_alerts_count) }}</flux:badge>
                        </a>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <x-device-status :device="$device" size="sm" wire:key="presence-card-{{ $device->id }}" />
                    <flux:badge size="sm" :color="$device->armState()->color()" :icon="$device->armState()->icon()">{{ __($device->armState()->label()) }}</flux:badge>
                </div>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="primary" icon:trailing="arrow-right" :href="route('devices.show', $device)" wire:navigate class="flex-1">{{ __('Open') }}</flux:button>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" icon="ellipsis-horizontal" :aria-label="__('More actions')" />
                        <flux:menu>
                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteDevice({{ $device->id }})" wire:confirm="{{ __('Remove this device with all its alerts and GPS history? This phone will be disconnected.') }}">
                                {{ __('Remove device') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </li>
        @empty
            <li class="moto-card px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No devices yet.') }}</li>
        @endforelse
    </ul>

    <div class="moto-card relative hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ __('Device ID') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Owner phone') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Open alerts') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 text-zinc-900 dark:divide-zinc-700 dark:text-zinc-100">
                @forelse ($this->devices as $device)
                    <tr wire:key="device-{{ $device->id }}">
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('devices.show', $device) }}" wire:navigate class="font-mono font-medium hover:underline">{{ $device->serial }}</a>
                                @if ($device->plate_number)
                                    <flux:badge size="sm" color="zinc">{{ $device->plate_number }}</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $device->owner_phone }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-device-status :device="$device" size="sm" wire:key="presence-{{ $device->id }}" />
                                <flux:badge size="sm" :color="$device->armState()->color()" :icon="$device->armState()->icon()">{{ __($device->armState()->label()) }}</flux:badge>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($device->open_alerts_count > 0)
                                <a href="{{ route('alerts.index', ['device' => $device->id, 'state' => 'open']) }}" wire:navigate>
                                    <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ number_format($device->open_alerts_count) }}</flux:badge>
                                </a>
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('None') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="primary" icon:trailing="arrow-right" :href="route('devices.show', $device)" wire:navigate>{{ __('Open') }}</flux:button>
                                {{-- Rare and destructive actions live behind a menu so they are never one stray click away. --}}
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" icon="ellipsis-horizontal" :aria-label="__('More actions')" />
                                    <flux:menu>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteDevice({{ $device->id }})" wire:confirm="{{ __('Remove this device with all its alerts and GPS history? This phone will be disconnected.') }}">
                                            {{ __('Remove device') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">{{ __('No devices yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</section>
