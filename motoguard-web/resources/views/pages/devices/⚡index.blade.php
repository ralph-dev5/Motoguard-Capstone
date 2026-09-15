<?php

use App\Models\Device;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Devices')] class extends Component {
    public string $name = '';

    public string $plate_number = '';

    public string $serial = '';

    public string $owner_phone = '';

    #[Locked]
    public ?string $newToken = null;

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

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'plate_number' => ['nullable', 'string', 'max:20'],
            'serial' => ['required', 'string', 'max:50', 'unique:devices,serial'],
            'owner_phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        $device = Auth::user()->devices()->create([
            ...$validated,
            'plate_number' => $validated['plate_number'] ?: null,
        ]);

        $this->reset('name', 'plate_number', 'serial', 'owner_phone');
        Flux::modal('register-device')->close();

        $this->showToken($device);
    }

    public function regenerateToken(int $deviceId): void
    {
        $this->showToken(Auth::user()->devices()->findOrFail($deviceId));
    }

    public function deleteDevice(int $deviceId): void
    {
        $device = Auth::user()->devices()->findOrFail($deviceId);
        $device->tokens()->delete();
        $device->delete();

        Flux::toast(text: __('Device removed.'), variant: 'success');
    }

    public function clearToken(): void
    {
        $this->newToken = null;
        Flux::modal('device-token')->close();
    }

    private function showToken(Device $device): void
    {
        $this->newToken = $device->issueToken();
        Flux::modal('device-token')->show();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Devices') }}</flux:heading>
            <flux:text>{{ __('Register each MotoGuard+ unit, then copy its token into the firmware config.') }}</flux:text>
        </div>
        <flux:modal.trigger name="register-device">
            <flux:button variant="primary" icon="plus">{{ __('Register device') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ __('Motorcycle') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Serial') }}</th>
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
                            <a href="{{ route('devices.show', $device) }}" wire:navigate class="font-medium hover:underline">{{ $device->name }}</a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $device->plate_number }}</div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $device->serial }}</td>
                        <td class="px-4 py-3">{{ $device->owner_phone }}</td>
                        <td class="px-4 py-3"><flux:badge size="sm" :color="$device->status->color()">{{ $device->status->label() }}</flux:badge></td>
                        <td class="px-4 py-3 tabular-nums">{{ $device->open_alerts_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="regenerateToken({{ $device->id }})" wire:confirm="{{ __('The ESP32 will stop reporting until you flash the new token. Continue?') }}">
                                    {{ __('New token') }}
                                </flux:button>
                                <flux:button size="sm" variant="danger" wire:click="deleteDevice({{ $device->id }})" wire:confirm="{{ __('Remove this device with all its alerts and GPS history?') }}">
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">{{ __('No devices yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="register-device" class="md:w-96">
        <form wire:submit="register" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Register device') }}</flux:heading>
                <flux:text class="mt-2">{{ __('You will get a token to paste into the ESP32 firmware.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Motorcycle name')" placeholder="Honda Click 125" required />
            <flux:input wire:model="plate_number" :label="__('Plate number')" placeholder="ABC 1234" />
            <flux:input wire:model="serial" :label="__('Device serial')" placeholder="MG-0001" required />
            <flux:input wire:model="owner_phone" :label="__('Owner phone for SMS')" placeholder="+639171234567" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Register') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="device-token" class="md:w-[32rem]" wire:close="clearToken">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Device token') }}</flux:heading>
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Copy this now. It will not be shown again.')" />

            @if ($newToken)
                <pre class="select-all whitespace-pre-wrap break-all rounded-lg bg-zinc-100 p-3 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">{{ $newToken }}</pre>
            @endif

            <flux:text>{!! __('Paste it into :file as :constant.', ['file' => '<code>motoguard-firmware/include/config.h</code>', 'constant' => '<code>DEVICE_TOKEN</code>']) !!}</flux:text>

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="clearToken">{{ __('Done') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
