<?php

use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\Device;
use App\Models\LocationLog;
use App\Services\DeviceTelemetry;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    public int $zoneRadius = Device::PARKING_RADIUS_DEFAULT;

    public string $plate_number = '';

    public string $owner_phone = '';

    public string $hotspot_ssid = '';

    public string $hotspot_password = '';

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
        $this->routeDate = $this->routeDate ?: now()->toDateString();
        $this->zoneRadius = $device->parkingRadius();
        $this->plate_number = (string) $device->plate_number;
        $this->owner_phone = (string) $device->owner_phone;
        $this->hotspot_ssid = (string) $device->hotspot_ssid;
        $this->hotspot_password = (string) $device->hotspot_password;

        $this->loadRoute();
    }

    /**
     * @return Collection<int, Alert>
     */
    #[Computed]
    public function alerts(): Collection
    {
        // Point each alert back at the device already in memory, so the location cell can explain
        // a missing position without a query per row.
        return $this->device->alerts()->latest()->limit(20)->get()
            ->each(fn (Alert $alert) => $alert->setRelation('device', $this->device));
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
            ? __('Arming. The badge turns green once the device confirms it.')
            : __('Disarming. The badge changes once the device confirms it.'));
    }

    public function requestCalibration(): void
    {
        $this->authorize('update', $this->device);

        $this->device->update(['calibration_requested_at' => now()]);

        Flux::toast(text: __('Calibration requested. Keep the motorcycle still: it starts within 30 seconds and takes 15.'));
    }

    // Polled while a calibration is pending, in case the broadcast that normally refreshes the page is missed.
    public function refreshDevice(): void
    {
        $this->device->refresh();
    }

    /**
     * The parking zone's center is set automatically when the motorcycle is armed; the owner only
     * chooses how far it may move before that counts as being taken.
     */
    public function saveZoneRadius(): void
    {
        $this->authorize('update', $this->device);

        $this->validate(['zoneRadius' => ['required', 'integer', 'between:50,2000']]);

        $this->device->update(['safe_zone_radius_m' => $this->zoneRadius]);

        Flux::toast(text: __('Parking zone radius saved.'), variant: 'success');
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->device);

        // The modem texts exactly what is stored, so a Philippine mobile typed as 09.., 9.. or 639..
        // is saved as +639..; a 9.. without the 0 otherwise never arrives.
        $phone = preg_replace('/[\s\-()]/', '', $this->owner_phone);
        $this->owner_phone = preg_replace('/^(?:\+?63|0)?(9\d{9})$/', '+63$1', $phone);

        $validated = $this->validate([
            'plate_number' => ['nullable', 'string', 'max:20'],
            'owner_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            // WPA2 limits: 32-byte name, 8-63 character password.
            'hotspot_ssid' => ['nullable', 'string', 'max:32'],
            'hotspot_password' => ['nullable', 'required_with:hotspot_ssid', 'string', 'min:8', 'max:63'],
        ]);

        $this->device->update([
            ...$validated,
            'plate_number' => $validated['plate_number'] ?: null,
            'owner_phone' => $validated['owner_phone'] ?: null,
            'hotspot_ssid' => $validated['hotspot_ssid'] ?: null,
            'hotspot_password' => $validated['hotspot_ssid'] ? $validated['hotspot_password'] : null,
        ]);

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

<section class="w-full space-y-6" wire:poll.60s>
    <x-page-header :title="$device->serial" :description="$device->plate_number ? __('Plate :plate', ['plate' => $device->plate_number]) : __('No plate number yet')" icon="shield-check">
        <x-slot:before>
            <flux:link :href="route('devices.index')" wire:navigate class="text-sm">&larr; {{ __('Devices') }}</flux:link>
        </x-slot:before>
        <x-slot:actions>
            @if ($device->status === DeviceStatus::Alert)
                <flux:button wire:click="acknowledge" icon="check">{{ __('Acknowledge all') }}</flux:button>
            @endif
            <flux:button wire:click="toggleArm" variant="primary" :color="$device->is_armed ? 'amber' : 'green'" :icon="$device->is_armed ? 'lock-open' : 'lock-closed'">
                {{ $device->is_armed ? __('Disarm') : __('Arm') }}
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    {{-- The unit's health in one row of tiles instead of a line of badges and text. --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6 [&>*]:min-w-0">
        <div class="moto-card col-span-2 p-3 md:col-span-1 xl:col-span-2">
            <flux:text class="text-xs">{{ __('Protection') }}</flux:text>
            <div class="mt-1.5 flex flex-wrap gap-1">
                <flux:badge :icon="$device->armState()->icon()" :color="$device->armState()->color()">{{ __($device->armState()->label()) }}</flux:badge>
                @if ($device->status === DeviceStatus::Alert)
                    <flux:badge color="red" icon="exclamation-triangle">{{ __('Alert') }}</flux:badge>
                @endif
            </div>
        </div>
        <div class="moto-card p-3">
            <flux:text class="text-xs">{{ __('Connection') }}</flux:text>
            <div class="mt-1.5"><x-device-status :device="$device" wire:key="presence-{{ $device->id }}" /></div>
        </div>
        <div class="moto-card p-3">
            <flux:text class="text-xs">{{ __('GPS') }}</flux:text>
            <div class="mt-1.5"><x-gps-status :device="$device" /></div>
        </div>
        <div class="moto-card p-3">
            <flux:text class="text-xs">{{ __('Battery') }}</flux:text>
            <div class="mt-1.5 font-medium text-zinc-900 dark:text-white"><x-battery :device="$device" /></div>
        </div>
        <div class="moto-card p-3">
            <flux:text class="text-xs">{{ __('Last seen') }}</flux:text>
            <div class="mt-1.5 font-medium text-zinc-900 dark:text-white">{{ $device->last_seen_at?->diffForHumans() ?? __('Never') }}</div>
        </div>
    </div>

    {{--
        Shown only while the device is silent. The board identifies itself by its built-in ID, so
        the owner only has to give it WiFi: there is no code or token to type.
    --}}
    @unless ($device->isRecentlySeen())
        <div class="rounded-xl border border-blue-500/40 bg-blue-500/5 p-5" wire:poll.10s="refreshDevice">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-blue-500/15 text-blue-600 dark:text-blue-400">
                        <flux:icon name="signal-slash" />
                    </span>
                    <div>
                        <flux:heading size="lg">{{ __('Connect this device') }}</flux:heading>
                        <flux:text class="text-sm">{{ __('The device is not reporting yet. It only needs WiFi: do these steps on your phone, it takes about a minute.') }}</flux:text>
                    </div>
                </div>
                <span class="inline-flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="relative flex size-2.5">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-blue-400 opacity-75"></span>
                        <span class="relative inline-flex size-2.5 rounded-full bg-blue-500"></span>
                    </span>
                    {{ __('Waiting for :id…', ['id' => $device->serial]) }}
                </span>
            </div>

            <ol class="mt-5 grid gap-4 md:grid-cols-3">
                <li class="rounded-lg border border-zinc-200 bg-white/60 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">{{ __('Step 1') }}</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ __('Turn the device on') }}</div>
                    <flux:text class="mt-1 text-sm">{{ __('If it cannot reach a saved WiFi, it opens its setup hotspot by itself. To change WiFi later, press the BOOT button within 1.5 seconds after switching it on.') }}</flux:text>
                </li>
                <li class="rounded-lg border border-zinc-200 bg-white/60 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">{{ __('Step 2') }}</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ __('Join its WiFi from your phone') }}</div>
                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('WiFi') }}</dt>
                        <dd class="font-mono text-zinc-900 dark:text-white">MotoGuard-Setup</dd>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Password') }}</dt>
                        <dd class="font-mono text-zinc-900 dark:text-white">motoguard</dd>
                    </dl>
                    <flux:text class="mt-2 text-sm">{{ __('The setup page usually opens by itself. If not, open :url and tap “Configure WiFi”.', ['url' => '192.168.4.1']) }}</flux:text>
                </li>
                <li class="rounded-lg border border-zinc-200 bg-white/60 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">{{ __('Step 3') }}</div>
                    <div class="mt-1 font-medium text-zinc-900 dark:text-white">{{ __('Pick your WiFi and tap Save') }}</div>
                    <flux:text class="mt-1 text-sm">{{ __('Choose a 2.4 GHz WiFi and type its password. The setup page shows the device’s ID: it should read :id. Nothing else to type.', ['id' => $device->serial]) }}</flux:text>
                </li>
            </ol>
            <flux:text class="mt-3 text-xs">{{ __('The device beeps twice when it has connected to your account. Away from home it also uses your phone hotspot if you add it under Details.') }}</flux:text>
        </div>
    @endunless

    {{-- Two tabs keep the live view apart from the settings, which are rarely touched. --}}
    <div x-data="{ tab: 'overview' }" class="space-y-5">
        <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800" role="tablist">
            <button type="button" role="tab" x-bind:aria-selected="tab === 'overview'"
                x-on:click="tab = 'overview'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                x-bind:class="tab === 'overview' ? 'border-accent text-accent-content' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">
                <flux:icon name="map" variant="mini" class="size-4" /> {{ __('Map & alerts') }}
            </button>
            <button type="button" role="tab" x-bind:aria-selected="tab === 'settings'"
                x-on:click="tab = 'settings'"
                class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                x-bind:class="tab === 'settings' ? 'border-accent text-accent-content' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white'">
                <flux:icon name="cog-6-tooth" variant="mini" class="size-4" /> {{ __('Settings') }}
            </button>
        </div>

        <div x-show="tab === 'overview'" class="space-y-3" x-data="{ view: '2d' }">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:heading class="flex items-center gap-2"><flux:icon name="map" variant="mini" class="size-5 text-zinc-400" />{{ __('Live location and route') }}</flux:heading>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg border border-zinc-300 bg-zinc-100 p-0.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" role="group" aria-label="{{ __('Map view') }}">
                        <button type="button" x-on:click="view = '2d'" x-bind:aria-pressed="view === '2d'"
                            class="rounded-md px-3 py-1 font-medium"
                            x-bind:class="view === '2d' ? 'bg-accent text-white shadow-sm' : 'text-zinc-700 hover:bg-zinc-200 dark:text-zinc-200 dark:hover:bg-zinc-700'">2D</button>
                        <button type="button" x-on:click="view = '3d'" x-bind:aria-pressed="view === '3d'"
                            class="rounded-md px-3 py-1 font-medium"
                            x-bind:class="view === '3d' ? 'bg-accent text-white shadow-sm' : 'text-zinc-700 hover:bg-zinc-200 dark:text-zinc-200 dark:hover:bg-zinc-700'">3D</button>
                    </div>
                    <div class="w-44">
                        <flux:input type="date" wire:model.live="routeDate" size="sm" :aria-label="__('Route date')" />
                    </div>
                </div>
            </div>

            <div class="moto-card overflow-hidden">
                <div
                    wire:ignore
                    x-show="view === '2d'"
                    class="h-[300px] w-full sm:h-[460px]"
                    x-data="motoMap({
                        devices: [@js($device->livePayload())],
                        deviceId: {{ $device->id }},
                        route: @js($route),
                        zone: @js($device->safeZonePayload()),
                        editableZone: false,
                        today: @js(now()->toDateString()),
                    })"
                ></div>

                {{-- Built only while shown: MapLibre needs a visible container, and leaving it out keeps the 2D page light. --}}
                <div wire:ignore>
                    <template x-if="view === '3d'">
                        <div
                            class="relative h-[300px] w-full sm:h-[460px]"
                            x-data="motoMap3d({
                                device: @js($device->livePayload()),
                                route: @js($route),
                                zone: @js($device->safeZonePayload()),
                                today: @js(now()->toDateString()),
                            })"
                        >
                            <div x-ref="canvas" class="h-full w-full"></div>
                            <div x-show="loading && !failed" class="absolute inset-0 grid place-items-center bg-zinc-100 text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ __('Loading 3D map…') }}</div>
                            <div x-show="failed" x-cloak class="absolute inset-0 grid place-items-center bg-zinc-100 p-6 text-center text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ __('The 3D map could not load. It needs an internet connection for map tiles; the 2D map still works.') }}</div>
                            <button type="button" x-show="!following && !loading" x-cloak x-on:click="recenter()"
                                class="absolute bottom-3 left-3 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 shadow ring-1 ring-black/10 dark:bg-zinc-900 dark:text-white dark:ring-white/10">
                                {{ __('Follow motorcycle') }}
                            </button>
                        </div>
                    </template>
                </div>
            </div>
            <flux:text class="text-xs" x-show="view === '2d'">{{ __('Blue line: where it went on the chosen day. Dashed circle: parking area.') }}</flux:text>
            <flux:text class="text-xs" x-show="view === '3d'" x-cloak>{{ __('Drag to look around. Right-drag to tilt.') }}</flux:text>

            <div class="space-y-3 pt-3">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading class="flex items-center gap-2"><flux:icon name="bell-alert" variant="mini" class="size-5 text-zinc-400" />{{ __('Latest alerts') }}</flux:heading>
                    <flux:link :href="route('alerts.index', ['device' => $device->id])" wire:navigate class="text-sm">{{ __('See all') }}</flux:link>
                </div>
                <ul class="moto-card divide-y divide-zinc-200 overflow-hidden md:hidden dark:divide-zinc-700">
                    @forelse ($this->alerts as $alert)
                        <x-alert-card :alert="$alert" wire:key="alert-card-{{ $alert->id }}" />
                    @empty
                        <li class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No alerts for this motorcycle.') }}</li>
                    @endforelse
                </ul>
                <div class="moto-card relative hidden overflow-x-auto md:block">
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
                                    <td class="px-4 py-3 font-medium">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-alert-level :alert="$alert" />
                                            <span>{{ $alert->type->label() }}</span>
                                        </div>
                                        <x-alert-evidence :alert="$alert" class="mt-0.5 block font-normal" />
                                    </td>
                                    <td class="px-4 py-3"><x-alert-time :alert="$alert" /></td>
                                    <td class="px-4 py-3">
                                        <x-alert-location :alert="$alert" />
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3"><x-alert-sms :alert="$alert" /></td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($alert->acknowledged_at)
                                            <flux:badge size="sm" color="zinc">{{ __('Seen') }}</flux:badge>
                                        @else
                                            <flux:button size="sm" variant="primary" icon="check" wire:click="acknowledge({{ $alert->id }})">{{ __('Acknowledge') }}</flux:button>
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
        </div>

        <div x-show="tab === 'settings'" x-cloak class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
            <div class="moto-card p-4">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading class="flex items-center gap-2"><flux:icon name="adjustments-horizontal" variant="mini" class="size-5 text-zinc-400" />{{ __('Motion calibration') }}</flux:heading>
                        @if ($device->calibrationPending())
                            <flux:badge size="sm" color="amber" icon="arrow-path">{{ __('Waiting for device') }}</flux:badge>
                        @elseif ($device->calibrated_at)
                            <flux:badge size="sm" color="green" icon="check">{{ __('Calibrated :time', ['time' => $device->calibrated_at->diffForHumans()]) }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Not calibrated') }}</flux:badge>
                        @endif
                    </div>

                    @if ($device->calibrationPending())
                        <div wire:poll.5s="refreshDevice"></div>
                        <flux:text class="text-sm">{{ __('Keep the motorcycle completely still. The device beeps once when it starts measuring and twice when it is done.') }}</flux:text>
                    @else
                        <flux:text class="text-sm">{{ __('Keep the motorcycle still and press Calibrate. It takes 15 seconds and stops small vibrations from setting off alarms. Do it again after moving the device.') }}</flux:text>
                    @endif

                    @if ($thresholds = $device->motionThresholds())
                        <details class="text-sm">
                        <summary class="cursor-pointer text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">{{ __('Technical details') }}</summary>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Resting noise') }}</dt>
                            <dd class="tabular-nums text-zinc-900 dark:text-white">
                                {{ __('jolt :j · shove :s · rumble :r', ['j' => number_format($device->calibration['noise_jerk'], 2), 's' => number_format($device->calibration['noise_shove'], 2), 'r' => number_format($device->calibration['noise_rumble'], 2)]) }}
                            </dd>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Alerts on') }}</dt>
                            <dd class="tabular-nums text-zinc-900 dark:text-white">
                                {{ __('jolt > :j · push > :p / :r', ['j' => number_format($thresholds['jolt'], 2), 'p' => number_format($thresholds['push_accel'], 2), 'r' => number_format($thresholds['push_rumble'], 2)]) }}
                            </dd>
                        </dl>
                        <flux:text class="text-xs">{{ __('All values in m/s².') }}</flux:text>
                        </details>
                    @endif

                    <flux:button size="sm" icon="adjustments-horizontal" wire:click="requestCalibration" :disabled="$device->calibrationPending()">
                        {{ $device->calibrated_at ? __('Calibrate again') : __('Calibrate') }}
                    </flux:button>
                </div>
            </div>

            <div class="moto-card p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading class="flex items-center gap-2"><flux:icon name="map-pin" variant="mini" class="size-5 text-zinc-400" />{{ __('Parking zone') }}</flux:heading>
                    @if ($device->hasSafeZone())
                        <flux:badge size="sm" color="green" icon="map-pin">{{ __('Active') }}</flux:badge>
                    @elseif ($device->isGuarding())
                        <flux:badge size="sm" color="amber" icon="arrow-path">{{ __('Waiting for GPS') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="mt-1 text-sm">{{ __('When you arm it, the spot where it is parked is saved. If the motorcycle leaves that area, you get an alert and a text message.') }}</flux:text>

                <flux:text class="mt-3 text-sm">
                    @if ($device->hasSafeZone())
                        {{ __('Marked :time.', ['time' => $device->parked_at?->timezone(config('app.display_timezone'))->format('M j, g:i A') ?? __('when it was armed')]) }}
                    @elseif ($device->isGuarding())
                        {{ __('Armed, waiting for a GPS fix to mark where it is parked.') }}
                    @elseif ($device->is_armed)
                        {{ __('Paused while your phone is nearby. It is marked again when you walk away.') }}
                    @else
                        {{ __('Off while disarmed. It is marked the next time the motorcycle is armed.') }}
                    @endif
                </flux:text>

                <div class="mt-4 space-y-3">
                    <div>
                        <label for="zone-radius" class="text-sm font-medium text-zinc-800 dark:text-white">
                            {{ __('Alert after moving') }}: <span class="tabular-nums" x-text="$wire.zoneRadius"></span> m
                        </label>
                        <input id="zone-radius" type="range" min="50" max="2000" step="50" wire:model.live.debounce.250ms="zoneRadius" class="mt-2 w-full accent-blue-600 dark:accent-blue-500" />
                        <flux:text class="text-xs">{{ __('100 m or more is best, because GPS can drift a little.') }}</flux:text>
                    </div>
                    <flux:error name="zoneRadius" />
                    <flux:button size="sm" variant="primary" wire:click="saveZoneRadius">{{ __('Save radius') }}</flux:button>
                </div>
            </div>

            <form wire:submit="saveDetails" class="space-y-4 moto-card p-4 lg:col-span-2 xl:col-span-1">
                <flux:heading class="flex items-center gap-2"><flux:icon name="identification" variant="mini" class="size-5 text-zinc-400" />{{ __('Details') }}</flux:heading>
                <flux:input wire:model="plate_number" :label="__('Plate number')" />
                <flux:input wire:model="owner_phone" :label="__('Phone number for text alerts')" placeholder="09171234567" />
                {{-- No app: the phone pairs once in its own Bluetooth settings and reconnects by itself when near. --}}
                <div class="space-y-1 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Pair your phone (optional)') }}</flux:heading>
                    <flux:text class="text-sm">
                        {{ __('On your phone open Settings, Bluetooth, tap “MotoGuard :id” and enter PIN', ['id' => $device->serial]) }}
                        <span class="font-mono font-semibold tracking-wider text-zinc-900 dark:text-white">{{ \App\Support\BluetoothPin::for($device->serial) ?? '—' }}</span>.
                    </flux:text>
                    <flux:text class="text-xs">{{ __('Once paired, alarms stay quiet while your phone is near with Bluetooth on and turn back on about 20 seconds after you leave. No app is needed.') }}</flux:text>
                </div>
                <div class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Backup WiFi (optional)') }}</flux:heading>
                    <flux:input wire:model="hotspot_ssid" :label="__('Hotspot name')" placeholder="e.g. Juan's iPhone" autocomplete="off" data-1p-ignore data-lpignore="true" />
                    {{-- new-password: stops the browser filling in the dashboard login, which would then be sent to the device. --}}
                    <flux:input wire:model="hotspot_password" type="password" viewable :label="__('Hotspot password')" autocomplete="new-password" data-1p-ignore data-lpignore="true" />
                    <flux:text class="text-xs">{{ __('Used when the usual WiFi is out of reach. iPhone: turn on “Maximize Compatibility” in Personal Hotspot.') }}</flux:text>
                </div>
                <flux:button type="submit" size="sm" variant="primary">{{ __('Save details') }}</flux:button>
            </form>
        </div>
    </div>
</section>
