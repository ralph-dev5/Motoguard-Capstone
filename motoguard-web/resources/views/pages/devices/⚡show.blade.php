<?php

use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\Device;
use App\Models\LocationLog;
use App\Services\DeviceTelemetry;
use App\Support\GeoPoint;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Motorcycle')] class extends Component {
    public Device $device;

    #[Url(as: 'date')]
    public string $routeDate = '';

    /** @var list<array{0: float, 1: float}> */
    public array $route = [];

    public ?float $zoneLat = null;

    public ?float $zoneLng = null;

    public int $zoneRadius = 200;

    public string $name = '';

    public string $plate_number = '';

    public string $owner_phone = '';

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
        $this->routeDate = $this->routeDate ?: now()->toDateString();
        $this->zoneLat = $device->safe_zone_center?->lat;
        $this->zoneLng = $device->safe_zone_center?->lng;
        $this->zoneRadius = $device->safe_zone_radius_m ?? 200;
        $this->name = $device->name;
        $this->plate_number = (string) $device->plate_number;
        $this->owner_phone = (string) $device->owner_phone;

        $this->loadRoute();
    }

    /**
     * @return Collection<int, Alert>
     */
    #[Computed]
    public function alerts(): Collection
    {
        return $this->device->alerts()->latest()->limit(20)->get();
    }

    public function updatedRouteDate(): void
    {
        $this->validate(['routeDate' => ['required', 'date']]);
        $this->loadRoute();
    }

    public function toggleArm(): void
    {
        $this->authorize('update', $this->device);

        app(DeviceTelemetry::class)->setArmed($this->device, ! $this->device->is_armed);

        Flux::toast(text: $this->device->is_armed
            ? __('Armed. The device applies this on its next heartbeat.')
            : __('Disarmed. The device applies this on its next heartbeat.'));
    }

    public function placeZone(float $lat, float $lng): void
    {
        $this->zoneLat = round($lat, 6);
        $this->zoneLng = round($lng, 6);
    }

    public function saveZone(): void
    {
        $this->authorize('update', $this->device);

        $this->validate([
            'zoneLat' => ['required', 'numeric', 'between:-90,90'],
            'zoneLng' => ['required', 'numeric', 'between:-180,180'],
            'zoneRadius' => ['required', 'integer', 'between:50,2000'],
        ], ['zoneLat.required' => __('Click the map to place the safe zone.')]);

        $this->device->update([
            'safe_zone_center' => new GeoPoint((float) $this->zoneLat, (float) $this->zoneLng),
            'safe_zone_radius_m' => $this->zoneRadius,
        ]);

        Flux::toast(text: __('Safe zone saved.'), variant: 'success');
    }

    public function clearZone(): void
    {
        $this->authorize('update', $this->device);

        $this->device->update(['safe_zone_center' => null, 'safe_zone_radius_m' => null]);
        $this->zoneLat = null;
        $this->zoneLng = null;

        Flux::toast(text: __('Safe zone removed.'));
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->device);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'plate_number' => ['nullable', 'string', 'max:20'],
            'owner_phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        $this->device->update([...$validated, 'plate_number' => $validated['plate_number'] ?: null]);

        Flux::toast(text: __('Details saved.'), variant: 'success');
    }

    public function acknowledge(?int $alertId = null): void
    {
        $this->authorize('update', $this->device);

        $alert = $alertId ? $this->device->alerts()->findOrFail($alertId) : null;

        app(DeviceTelemetry::class)->acknowledge($this->device, $alert);
    }

    /**
     * @param  array{alert: array{label: string}}  $event
     */
    #[On('echo-private:devices.{device.id},AlertTriggered')]
    public function onAlertTriggered(array $event): void
    {
        $this->device->refresh();

        Flux::toast(text: __('New alert on this motorcycle.'), heading: $event['alert']['label'], variant: 'danger');
    }

    #[On('echo-private:devices.{device.id},DeviceStatusChanged')]
    public function onDeviceStatusChanged(): void
    {
        $this->device->refresh();
    }

    private function loadRoute(): void
    {
        $day = rescue(fn () => Carbon::parse($this->routeDate), now(), report: false);

        $this->route = $this->device->locationLogs()
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->limit(2000)
            ->get(['id', 'location'])
            ->map(fn (LocationLog $log) => [$log->location->lat, $log->location->lng])
            ->all();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('devices.index')" wire:navigate class="text-sm">&larr; {{ __('Devices') }}</flux:link>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $device->name }}</flux:heading>
                <flux:badge :color="$device->status->color()">{{ $device->status->label() }}</flux:badge>
                <flux:badge :icon="$device->is_armed ? 'lock-closed' : 'lock-open'" color="zinc">
                    {{ $device->is_armed ? __('Armed') : __('Disarmed') }}
                </flux:badge>
            </div>
            <flux:text class="mt-1">
                {{ $device->plate_number ?? __('No plate') }} · {{ __('Serial') }} {{ $device->serial }} ·
                {{ __('Battery') }} {{ $device->battery_voltage ? number_format($device->battery_voltage, 1).' V' : '—' }} ·
                {{ __('Last seen') }} {{ $device->last_seen_at?->diffForHumans() ?? __('never') }}
            </flux:text>
        </div>

        <div class="flex gap-2">
            @if ($device->status === DeviceStatus::Alert)
                <flux:button wire:click="acknowledge" icon="check">{{ __('Acknowledge all') }}</flux:button>
            @endif
            <flux:button wire:click="toggleArm" :variant="$device->is_armed ? 'filled' : 'primary'" :icon="$device->is_armed ? 'lock-open' : 'lock-closed'">
                {{ $device->is_armed ? __('Disarm') : __('Arm') }}
            </flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:heading>{{ __('Live location and route') }}</flux:heading>
                <div class="w-44">
                    <flux:input type="date" wire:model.live="routeDate" size="sm" :aria-label="__('Route date')" />
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div
                    wire:ignore
                    class="h-[460px] w-full"
                    x-data="motoMap({
                        devices: [@js($device->livePayload())],
                        deviceId: {{ $device->id }},
                        route: @js($route),
                        zone: @js($device->safeZonePayload()),
                        editableZone: true,
                        today: @js(now()->toDateString()),
                    })"
                ></div>
            </div>
            <flux:text class="text-xs">{{ __('Blue line: route for the selected day. Click the map to move the safe zone center.') }}</flux:text>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>{{ __('Safe zone (geofence)') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('While armed, you get an alert and SMS when the motorcycle leaves this circle.') }}</flux:text>

                <div class="mt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Latitude') }}</div>
                            <div class="font-mono text-zinc-900 dark:text-white">{{ $zoneLat !== null ? number_format($zoneLat, 6) : '—' }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Longitude') }}</div>
                            <div class="font-mono text-zinc-900 dark:text-white">{{ $zoneLng !== null ? number_format($zoneLng, 6) : '—' }}</div>
                        </div>
                    </div>
                    <flux:error name="zoneLat" />

                    <div>
                        <label for="zone-radius" class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('Radius') }}: <span class="tabular-nums" x-text="$wire.zoneRadius"></span> m
                        </label>
                        <input id="zone-radius" type="range" min="50" max="2000" step="50" wire:model.live.debounce.250ms="zoneRadius" class="mt-2 w-full accent-zinc-800 dark:accent-white" />
                    </div>

                    <div class="flex gap-2">
                        <flux:button size="sm" variant="primary" wire:click="saveZone">{{ __('Save zone') }}</flux:button>
                        @if ($device->hasSafeZone())
                            <flux:button size="sm" variant="ghost" wire:click="clearZone">{{ __('Remove') }}</flux:button>
                        @endif
                    </div>
                </div>
            </div>

            <form wire:submit="saveDetails" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>{{ __('Details') }}</flux:heading>
                <flux:input wire:model="name" :label="__('Motorcycle name')" required />
                <flux:input wire:model="plate_number" :label="__('Plate number')" />
                <flux:input wire:model="owner_phone" :label="__('Owner phone for SMS')" required />
                <flux:button type="submit" size="sm">{{ __('Save details') }}</flux:button>
            </form>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading>{{ __('Latest alerts') }}</flux:heading>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Alert') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('When') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Location') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('SMS') }}</th>
                        <th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 text-zinc-900 dark:divide-zinc-700 dark:text-zinc-100">
                    @forelse ($this->alerts as $alert)
                        <tr wire:key="alert-{{ $alert->id }}">
                            <td class="px-4 py-3 font-medium">{{ $alert->type->label() }}</td>
                            <td class="px-4 py-3" title="{{ $alert->created_at->toDayDateTimeString() }}">{{ $alert->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-3">
                                @if ($alert->location)
                                    <flux:link :href="$alert->location->mapsUrl()" target="_blank" rel="noopener">{{ __('Open map') }}</flux:link>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $alert->sms_sent ? __('Sent') : __('Pending') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($alert->acknowledged_at)
                                    <flux:badge size="sm" color="zinc">{{ __('Seen') }}</flux:badge>
                                @else
                                    <flux:button size="sm" wire:click="acknowledge({{ $alert->id }})">{{ __('Acknowledge') }}</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">{{ __('No alerts for this motorcycle.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
