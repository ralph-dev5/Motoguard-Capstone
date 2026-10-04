<?php

use App\Enums\AlertLevel;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Device;
use App\Services\DeviceTelemetry;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Alerts')] class extends Component {
    use WithPagination;

    public int $userId;

    #[Url]
    public string $device = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $state = '';

    public function mount(): void
    {
        $this->userId = (int) Auth::id();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['device', 'type', 'level', 'state'], true)) {
            $this->resetPage();
        }
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
     * Alerts matching the motorcycle, type and level filters; the status filter is left to callers.
     *
     * @return Builder<Alert>
     */
    private function filtered(): Builder
    {
        return Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->when($this->device !== '', fn ($query) => $query->where('device_id', (int) $this->device))
            ->when(AlertType::tryFrom($this->type), fn ($query, AlertType $type) => $query->where('type', $type))
            ->when(AlertLevel::tryFrom($this->level), fn ($query, AlertLevel $level) => $query->where('level', $level));
    }

    /**
     * @return LengthAwarePaginator<int, Alert>
     */
    #[Computed]
    public function alerts(): LengthAwarePaginator
    {
        $alerts = $this->filtered()
            ->when($this->state === 'open', fn ($query) => $query->whereNull('acknowledged_at'))
            ->when($this->state === 'seen', fn ($query) => $query->whereNotNull('acknowledged_at'))
            ->latest()
            ->paginate(15);

        // Reuse the devices already loaded for the filter instead of querying them again.
        $alerts->getCollection()->each(fn (Alert $alert) => $alert->setRelation('device', $this->devices->find($alert->device_id)));

        return $alerts;
    }

    /**
     * Open alerts per level across all motorcycles, for the summary chips. Each chip count is
     * exactly what its filter shows, so alerts without a level only appear in the total.
     *
     * @return array{theft: int, suspicious: int, total: int}
     */
    #[Computed]
    public function openCounts(): array
    {
        $counts = Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->unacknowledged()
            ->selectRaw('level, count(*) as total')
            ->groupBy('level')
            ->toBase()
            ->pluck('total', 'level');

        $theft = (int) ($counts[AlertLevel::TheftAttempt->value] ?? 0);
        $suspicious = (int) ($counts[AlertLevel::Suspicious->value] ?? 0);

        return ['theft' => $theft, 'suspicious' => $suspicious, 'total' => (int) $counts->sum()];
    }

    public function showOpen(string $level = ''): void
    {
        $this->state = 'open';
        $this->level = AlertLevel::tryFrom($level)?->value ?? '';
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->device !== '' || $this->type !== '' || $this->level !== '' || $this->state !== '';
    }

    public function clearFilters(): void
    {
        $this->reset('device', 'type', 'level', 'state');
        $this->resetPage();
    }

    /**
     * Acknowledges every open alert that matches the current filters, motorcycle by motorcycle,
     * so each device's alert badge clears the same way a single acknowledge does.
     */
    public function acknowledgeAll(): void
    {
        $ids = $this->filtered()->unacknowledged()->pluck('device_id', 'id');
        $telemetry = app(DeviceTelemetry::class);

        $ids->keys()->groupBy(fn (int $id) => $ids[$id])->each(
            fn ($alertIds, $deviceId) => $telemetry->acknowledgeMany($this->devices->find($deviceId), $alertIds->all()),
        );

        unset($this->alerts, $this->openCounts);

        Flux::toast(text: trans_choice('{0} Nothing to acknowledge.|{1} :count alert acknowledged.|[2,*] :count alerts acknowledged.', $ids->count(), ['count' => $ids->count()]), variant: 'success');
    }

    public function acknowledge(int $alertId): void
    {
        $alert = Alert::query()->whereIn('device_id', $this->devices->modelKeys())->findOrFail($alertId);

        app(DeviceTelemetry::class)->acknowledge($alert->device, $alert);
    }

    #[On('echo-private:App.Models.User.{userId},AlertTriggered')]
    public function onAlertTriggered(): void
    {
        // Re-rendering shows the new alert at the top.
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Alerts') }}</flux:heading>
            <flux:text>{{ __('Every alert from your motorcycles, newest first.') }}</flux:text>
        </div>
        @if ($this->openCounts['total'] > 0)
            <flux:button
                icon="check"
                wire:click="acknowledgeAll"
                wire:confirm="{{ $this->hasFilters() ? __('Acknowledge every open alert that matches these filters?') : __('Acknowledge all open alerts?') }}"
            >
                {{ $this->hasFilters() ? __('Acknowledge matching') : __('Acknowledge all') }}
            </flux:button>
        @endif
    </div>

    {{-- Open alerts at a glance; each chip is a shortcut to that filter. --}}
    <div class="flex flex-wrap gap-2">
        @php
            $chip = 'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition hover:opacity-80';
        @endphp
        <button type="button" wire:click="showOpen('')" class="{{ $chip }} border-zinc-300 text-zinc-800 dark:border-zinc-600 dark:text-zinc-100 {{ $state === 'open' && $level === '' ? 'ring-2 ring-zinc-400' : '' }}">
            <flux:icon name="bell-alert" variant="micro" />
            {{ __('Open') }} <span class="tabular-nums">{{ number_format($this->openCounts['total']) }}</span>
        </button>
        <button type="button" wire:click="showOpen('theft_attempt')" class="{{ $chip }} border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300 {{ $state === 'open' && $level === 'theft_attempt' ? 'ring-2 ring-red-500/60' : '' }}">
            <span class="size-2 rounded-full bg-red-500"></span>
            {{ __('Theft attempts') }} <span class="tabular-nums">{{ number_format($this->openCounts['theft']) }}</span>
        </button>
        <button type="button" wire:click="showOpen('suspicious')" class="{{ $chip }} border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300 {{ $state === 'open' && $level === 'suspicious' ? 'ring-2 ring-amber-500/60' : '' }}">
            <span class="size-2 rounded-full bg-amber-500"></span>
            {{ __('Suspicious') }} <span class="tabular-nums">{{ number_format($this->openCounts['suspicious']) }}</span>
        </button>
        @if ($this->hasFilters())
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select wire:model.live="device" :label="__('Motorcycle')">
            <flux:select.option value="">{{ __('All motorcycles') }}</flux:select.option>
            @foreach ($this->devices as $option)
                <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type" :label="__('Type')">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (AlertType::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="level" :label="__('Level')">
            <flux:select.option value="">{{ __('All levels') }}</flux:select.option>
            @foreach (AlertLevel::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="state" :label="__('Status')">
            <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
            <flux:select.option value="open">{{ __('Not acknowledged') }}</flux:select.option>
            <flux:select.option value="seen">{{ __('Acknowledged') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="relative overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            <thead class="bg-zinc-50 text-left text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ __('Alert') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Motorcycle') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('When') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Location') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('SMS') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 text-zinc-900 dark:divide-zinc-700 dark:text-zinc-100">
                @forelse ($this->alerts as $alert)
                    @php
                        $open = ! $alert->acknowledged_at;
                        $accent = match (true) {
                            ! $open => 'border-l-transparent',
                            $alert->level === null || $alert->level === \App\Enums\AlertLevel::TheftAttempt => 'border-l-red-500 bg-red-500/[0.04]',
                            $alert->level === \App\Enums\AlertLevel::Suspicious => 'border-l-amber-500',
                            default => 'border-l-transparent',
                        };
                    @endphp
                    <tr wire:key="alert-{{ $alert->id }}" class="border-l-4 {{ $accent }} {{ $open ? '' : 'text-zinc-500 dark:text-zinc-400' }}">
                        <td class="px-4 py-3 font-medium">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-alert-level :alert="$alert" />
                                <span class="{{ $open ? 'text-zinc-900 dark:text-white' : '' }}">{{ $alert->type->label() }}</span>
                            </div>
                            <x-alert-evidence :alert="$alert" class="mt-0.5 block font-normal" />
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('devices.show', $alert->device) }}" wire:navigate class="hover:underline">{{ $alert->device->name }}</a>
                        </td>
                        <td class="px-4 py-3"><x-alert-time :alert="$alert" /></td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <x-alert-location :alert="$alert" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><x-alert-sms :alert="$alert" /></td>
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
                        <td colspan="6" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">{{ __('No alerts match these filters.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->alerts->links() }}
</section>
