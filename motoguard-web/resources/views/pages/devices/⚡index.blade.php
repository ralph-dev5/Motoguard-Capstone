<?php

use App\Models\Device;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Devices')] class extends Component {
    public string $serial = '';

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
     * Adds a device by the ID built into the board. That one value is all the owner types: the
     * board proves who it is and collects its own token (see EnrollController), and the
     * motorcycle's plate and SMS number are filled in later on the device page.
     */
    public function addDevice(): void
    {
        // Forgive how an ID gets typed on a phone: lower case, stray spaces, a missing dash.
        $serial = strtoupper(preg_replace('/\s+/', '', $this->serial));
        if (preg_match('/^MG[0-9A-F]{6}$/', $serial)) {
            $serial = 'MG-'.substr($serial, 2);
        }
        $this->serial = $serial;

        $this->validate(
            ['serial' => ['required', 'string', 'regex:'.Device::SERIAL_PATTERN, 'unique:devices,serial']],
            [
                'serial.regex' => __('A device ID looks like MG-04A784: MG, a dash, then six letters or digits (0-9, A-F).'),
                'serial.unique' => __('This device is already registered.'),
            ],
        );

        $device = Auth::user()->devices()->create([
            'serial' => $serial,
            // A device has no separate name: it is known by its ID everywhere.
            'name' => $serial,
        ]);

        $this->redirectRoute('devices.show', $device, navigate: true);
    }

    public function deleteDevice(int $deviceId): void
    {
        $device = Auth::user()->devices()->findOrFail($deviceId);
        $device->tokens()->delete();
        $device->delete();

        Flux::toast(text: __('Device removed.'), variant: 'success');
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Devices') }}</flux:heading>
            <flux:text>{{ __('Add each MotoGuard+ unit by the ID shown on its setup page. A device belongs to one account.') }}</flux:text>
        </div>
        <flux:modal.trigger name="register-device">
            <flux:button variant="primary" icon="plus">{{ __('Add device') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="relative overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
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
                            <a href="{{ route('devices.show', $device) }}" wire:navigate class="font-mono font-medium hover:underline">{{ $device->serial }}</a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $device->plate_number }}</div>
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
                                <flux:button size="sm" :href="route('devices.show', $device)" wire:navigate>{{ __('Open') }}</flux:button>
                                {{-- Rare and destructive actions live behind a menu so they are never one stray click away. --}}
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More actions')" />
                                    <flux:menu>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteDevice({{ $device->id }})" wire:confirm="{{ __('Remove this device with all its alerts and GPS history?') }}">
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

    <flux:modal name="register-device" class="md:w-96">
        <form wire:submit="addDevice" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add device') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Type the ID of your MotoGuard+ unit. It is shown on the device’s setup page (WiFi “MotoGuard-Setup”).') }}</flux:text>
            </div>

            <flux:input wire:model="serial" :label="__('Device ID')" placeholder="MG-04A784" autocomplete="off" autocapitalize="characters" required />

            <flux:text class="text-sm">{{ __('You can add the plate number and a phone number for SMS afterwards.') }}</flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Add device') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
