<?php

use App\Models\Alert;
use App\Models\Device;
use App\Services\DeviceTelemetry;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public int $userId;

    public function mount(): void
    {
        $this->userId = (int) Auth::id();
    }

    /**
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        return Auth::user()->devices()->orderBy('name')->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function stats(): array
    {
        return [
            __('Devices') => $this->devices->count(),
            __('Online now') => $this->devices->filter->isRecentlySeen()->count(),
            __('Armed') => $this->devices->where('is_armed', true)->count(),
            __('Open alerts') => Alert::query()->whereIn('device_id', $this->devices->modelKeys())->unacknowledged()->count(),
        ];
    }

    /**
     * @return Collection<int, Alert>
     */
    #[Computed]
    public function recentAlerts(): Collection
    {
        return Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->with('device')
            ->latest()
            ->limit(8)
            ->get();
    }

    /**
     * @return array{labels: list<string>, dates: list<string>, counts: list<int>}
     */
    #[Computed]
    public function alertsPerDay(): array
    {
        $counts = Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = collect(range(6, 0))->map(fn (int $daysAgo) => now()->subDays($daysAgo));

        return [
            'labels' => $days->map(fn ($day) => $day->format('D j'))->all(),
            'dates' => $days->map(fn ($day) => $day->format('l, M j'))->all(),
            'counts' => $days->map(fn ($day) => (int) ($counts[$day->toDateString()] ?? 0))->all(),
        ];
    }

    public function toggleArm(int $deviceId): void
    {
        $device = Auth::user()->devices()->findOrFail($deviceId);

        app(DeviceTelemetry::class)->setArmed($device, ! $device->is_armed);

        Flux::toast(text: __($device->is_armed ? ':name armed.' : ':name disarmed.', ['name' => $device->name]));
    }

    /**
     * @param  array{alert: array{label: string, device_name: string}}  $event
     */
    #[On('echo-private:App.Models.User.{userId},AlertTriggered')]
    public function onAlertTriggered(array $event): void
    {
        Flux::toast(text: $event['alert']['device_name'], heading: $event['alert']['label'], variant: 'danger');
    }

    #[On('echo-private:App.Models.User.{userId},DeviceStatusChanged')]
    public function onDeviceStatusChanged(): void
    {
        // Re-rendering refreshes the status cards.
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:text>{{ __('Live status of your motorcycles.') }}</flux:text>
        </div>
        <flux:button :href="route('devices.index')" icon="plus" wire:navigate>{{ __('Add device') }}</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->stats as $label => $value)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text>{{ $label }}</flux:text>
                <div class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="overflow-hidden rounded-xl border border-zinc-200 lg:col-span-2 dark:border-zinc-700">
            <div
                wire:ignore
                class="h-[420px] w-full"
                x-data="motoMap({
                    devices: @js($this->devices->map->livePayload()->values()),
                    userId: {{ $userId }},
                    deviceUrl: @js(route('devices.show', '__ID__')),
                })"
            ></div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading>{{ __('Recent alerts') }}</flux:heading>
                <flux:link :href="route('alerts.index')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
            </div>

            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->recentAlerts as $alert)
                    <li wire:key="alert-{{ $alert->id }}" class="flex items-start justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $alert->type->label() }}</div>
                            <flux:text class="truncate text-xs">{{ $alert->device->name }} · {{ $alert->created_at->diffForHumans() }}</flux:text>
                        </div>
                        @if ($alert->acknowledged_at)
                            <flux:badge size="sm" color="zinc">{{ __('Seen') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('New') }}</flux:badge>
                        @endif
                    </li>
                @empty
                    <li class="py-8 text-center"><flux:text>{{ __('No alerts yet.') }}</flux:text></li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->devices as $device)
            <div wire:key="device-{{ $device->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('devices.show', $device) }}" wire:navigate class="block truncate font-semibold text-zinc-900 hover:underline dark:text-white">{{ $device->name }}</a>
                        <flux:text class="text-xs">{{ $device->plate_number ?? $device->serial }}</flux:text>
                    </div>
                    <flux:badge size="sm" :color="$device->status->color()">{{ $device->status->label() }}</flux:badge>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Battery') }}</dt>
                        <dd class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ $device->battery_voltage ? number_format($device->battery_voltage, 1).' V' : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last seen') }}</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $device->last_seen_at?->diffForHumans() ?? __('Never') }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <flux:text class="flex items-center gap-1 text-sm">
                        <flux:icon :name="$device->is_armed ? 'lock-closed' : 'lock-open'" variant="micro" />
                        {{ $device->is_armed ? __('Armed') : __('Disarmed') }}
                    </flux:text>
                    <flux:button size="sm" wire:click="toggleArm({{ $device->id }})" :variant="$device->is_armed ? 'filled' : 'primary'">
                        {{ $device->is_armed ? __('Disarm') : __('Arm') }}
                    </flux:button>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:heading>{{ __('No motorcycles yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Register your ESP32 unit to start monitoring.') }}</flux:text>
                <flux:button class="mt-4" variant="primary" :href="route('devices.index')" wire:navigate>{{ __('Register device') }}</flux:button>
            </div>
        @endforelse
    </div>

    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading>{{ __('Alerts per day') }}</flux:heading>
        <flux:text class="text-sm">{{ __('Last 7 days, all motorcycles') }}</flux:text>

        <div wire:ignore class="relative mt-4 h-56">
            <canvas x-data="alertsChart(@js($this->alertsPerDay))" role="img" aria-label="{{ __('Bar chart of alerts per day for the last 7 days') }}"></canvas>
        </div>

        <table class="sr-only">
            <caption>{{ __('Alerts per day') }}</caption>
            <tbody>
                @foreach ($this->alertsPerDay['dates'] as $i => $date)
                    <tr><th scope="row">{{ $date }}</th><td>{{ $this->alertsPerDay['counts'][$i] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
