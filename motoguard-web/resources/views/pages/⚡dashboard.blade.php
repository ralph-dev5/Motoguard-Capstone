<?php

use App\Enums\AlertLevel;
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
        return Auth::user()->devices()
            ->withCount(['alerts as open_alerts_count' => fn ($query) => $query->whereNull('acknowledged_at')])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{label: string, value: int, icon: string, tone: string, hint: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $online = $this->devices->filter->isRecentlySeen()->count();
        $armed = $this->devices->filter(fn (Device $device) => $device->armState()->isGuarding())->count();
        $open = (int) $this->devices->sum('open_alerts_count');
        $charging = $this->devices->filter(fn (Device $device) => $device->battery()?->needsCharge())->count();
        $total = $this->devices->count();

        return [
            ['label' => __('Motorcycles'), 'value' => $total, 'icon' => 'map-pin', 'tone' => 'neutral', 'hint' => __('registered')],
            ['label' => __('Online now'), 'value' => $online, 'icon' => 'signal', 'tone' => $online ? 'good' : 'neutral', 'hint' => __('of :total reporting', ['total' => $total])],
            ['label' => __('Armed'), 'value' => $armed, 'icon' => 'lock-closed', 'tone' => $armed ? 'good' : 'neutral', 'hint' => __('watching for movement')],
            ['label' => __('Open alerts'), 'value' => $open, 'icon' => 'bell-alert', 'tone' => $open ? 'bad' : 'neutral', 'hint' => $open ? __('waiting for you') : __('all caught up')],
            ['label' => __('Needs charging'), 'value' => $charging, 'icon' => 'battery-50', 'tone' => $charging ? 'warn' : 'neutral', 'hint' => __('backup battery low')],
        ];
    }

    /**
     * The one-line answer to "is my motorcycle safe right now?", shown above everything else.
     *
     * @return array{tone: string, icon: string, title: string, text: string}
     */
    #[Computed]
    public function security(): array
    {
        $theft = Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->unacknowledged()
            ->where(fn ($query) => $query->where('level', AlertLevel::TheftAttempt)->orWhereNull('level'))
            ->count();
        $open = (int) $this->devices->sum('open_alerts_count');
        $online = $this->devices->filter->isRecentlySeen();

        return match (true) {
            $this->devices->isEmpty() => [
                'tone' => 'neutral', 'icon' => 'plus-circle',
                'title' => __('Add your first motorcycle'),
                'text' => __('Register your MotoGuard+ unit to start monitoring.'),
            ],
            $theft > 0 => [
                'tone' => 'bad', 'icon' => 'shield-exclamation',
                'title' => trans_choice('{1} :count theft alert needs your attention|[2,*] :count theft alerts need your attention', $theft, ['count' => $theft]),
                'text' => __('Check where your motorcycle is, then acknowledge the alerts once it is safe.'),
            ],
            $open > 0 => [
                'tone' => 'warn', 'icon' => 'exclamation-triangle',
                'title' => trans_choice('{1} :count suspicious alert to review|[2,*] :count suspicious alerts to review', $open, ['count' => $open]),
                'text' => __('Someone touched or moved a motorcycle, but not enough to sound the alarm.'),
            ],
            $online->isEmpty() => [
                'tone' => 'neutral', 'icon' => 'signal-slash',
                'title' => __('No motorcycle is online'),
                'text' => __('Alerts cannot reach you while the device is off or out of signal.'),
            ],
            $online->filter(fn (Device $device) => $device->armState()->isGuarding())->isEmpty() => [
                'tone' => 'warn', 'icon' => 'lock-open',
                'title' => __('Monitoring is paused'),
                'text' => __('Your motorcycle is online but not guarded: it is disarmed, your phone is nearby, or it is still applying the arm switch.'),
            ],
            default => [
                'tone' => 'good', 'icon' => 'shield-check',
                'title' => __('All motorcycles are secure'),
                'text' => __('Armed, online, and no open alerts.'),
            ],
        };
    }

    /**
     * @return Collection<int, Alert>
     */
    #[Computed]
    public function recentAlerts(): Collection
    {
        // Reuse the devices already loaded above instead of querying them again.
        return Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->latest()
            ->limit(8)
            ->get()
            ->each(fn (Alert $alert) => $alert->setRelation('device', $this->devices->find($alert->device_id)));
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

{{--
    The on/off badge does not depend on this poll: it counts locally and is fed by DevicePinged
    over the websocket. This is the slow safety net for everything else on the page (counts,
    alert list, battery) and for browsers where the websocket never connected.
--}}
<div class="flex w-full flex-1 flex-col gap-6" wire:poll.60s>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:text>{{ __('Live status of your motorcycles.') }}</flux:text>
        </div>
        <flux:button :href="route('devices.index')" icon="plus" wire:navigate>{{ __('Add device') }}</flux:button>
    </div>

    @php
        $security = $this->security;
        $bannerTone = [
            'bad' => 'border-red-500/40 bg-red-500/10 text-red-900 dark:text-red-100',
            'warn' => 'border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-100',
            'good' => 'border-green-500/40 bg-green-500/10 text-green-900 dark:text-green-100',
            'neutral' => 'border-zinc-300 bg-zinc-100 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100',
        ][$security['tone']];
        $bannerIcon = [
            'bad' => 'text-red-600 dark:text-red-400',
            'warn' => 'text-amber-600 dark:text-amber-400',
            'good' => 'text-green-600 dark:text-green-400',
            'neutral' => 'text-zinc-500 dark:text-zinc-400',
        ][$security['tone']];
    @endphp
    <div role="status" class="flex flex-wrap items-center gap-4 rounded-xl border p-4 {{ $bannerTone }}">
        <flux:icon :name="$security['icon']" class="size-8 shrink-0 {{ $bannerIcon }}" />
        <div class="min-w-0 flex-1 basis-52">
            <div class="font-semibold">{{ $security['title'] }}</div>
            <div class="text-sm opacity-80">{{ $security['text'] }}</div>
        </div>
        @if (in_array($security['tone'], ['bad', 'warn']) && $this->devices->sum('open_alerts_count') > 0)
            <flux:button size="sm" :href="route('alerts.index', ['state' => 'open'])" wire:navigate icon:trailing="arrow-right">{{ __('Review alerts') }}</flux:button>
        @elseif ($this->devices->isEmpty())
            <flux:button size="sm" variant="primary" :href="route('devices.index')" wire:navigate>{{ __('Register device') }}</flux:button>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        @foreach ($this->stats as $stat)
            @php
                $iconTone = [
                    'bad' => 'bg-red-500/15 text-red-600 dark:text-red-400',
                    'warn' => 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
                    'good' => 'bg-green-500/15 text-green-600 dark:text-green-400',
                    'neutral' => 'bg-zinc-500/10 text-zinc-500 dark:text-zinc-400',
                ][$stat['tone']];
            @endphp
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center justify-between gap-2">
                    <flux:text class="text-sm">{{ $stat['label'] }}</flux:text>
                    <span class="grid size-8 place-items-center rounded-lg {{ $iconTone }}">
                        <flux:icon :name="$stat['icon']" variant="mini" class="size-4" />
                    </span>
                </div>
                <div class="mt-1 text-3xl font-semibold tabular-nums {{ $stat['tone'] === 'bad' ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ number_format($stat['value']) }}</div>
                <flux:text class="text-xs">{{ $stat['hint'] }}</flux:text>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 [&>*]:min-w-0">
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
                    <li wire:key="alert-{{ $alert->id }}">
                        <a href="{{ route('devices.show', $alert->device) }}" wire:navigate class="-mx-2 flex items-start justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-700/50">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                    <x-alert-level :alert="$alert" />
                                    <span class="truncate">{{ $alert->type->label() }}</span>
                                </div>
                                <flux:text class="truncate text-xs">{{ $alert->device->name }} · {{ $alert->created_at->timezone(config('app.display_timezone'))->format('M j, g:i A') }}</flux:text>
                                <x-alert-evidence :alert="$alert" class="block truncate" />
                            </div>
                            @if ($alert->acknowledged_at)
                                <flux:badge size="sm" color="zinc">{{ __('Seen') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('New') }}</flux:badge>
                            @endif
                        </a>
                    </li>
                @empty
                    <li class="py-8 text-center"><flux:text>{{ __('No alerts yet.') }}</flux:text></li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
        @forelse ($this->devices as $device)
            <div wire:key="device-{{ $device->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('devices.show', $device) }}" wire:navigate class="block truncate font-semibold text-zinc-900 hover:underline dark:text-white">{{ $device->name }}</a>
                        <flux:text class="text-xs">{{ $device->plate_number ?? $device->serial }}</flux:text>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <x-device-status
                            :device="$device"
                            size="sm"
                            wire:key="presence-{{ $device->id }}"
                        />
                        @if ($device->open_alerts_count > 0)
                            <flux:badge size="sm" color="red" icon="exclamation-triangle">
                                {{ trans_choice('{1} :count alert|[2,*] :count alerts', $device->open_alerts_count, ['count' => $device->open_alerts_count]) }}
                            </flux:badge>
                        @endif
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Battery') }}</dt>
                        <dd class="font-medium"><x-battery :device="$device" /></dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last seen') }}</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $device->last_seen_at?->diffForHumans() ?? __('Never') }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('GPS') }}</dt>
                        <dd class="mt-0.5"><x-gps-status :device="$device" size="sm" /></dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <flux:text class="flex items-center gap-1 text-sm">
                        <flux:icon :name="$device->armState()->icon()" variant="micro" />
                        {{ __($device->armState()->label()) }}
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
