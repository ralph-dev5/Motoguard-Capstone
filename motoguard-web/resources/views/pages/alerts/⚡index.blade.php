<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Device;
use App\Services\DeviceTelemetry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
    public string $state = '';

    public function mount(): void
    {
        $this->userId = (int) Auth::id();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['device', 'type', 'state'], true)) {
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
     * @return LengthAwarePaginator<int, Alert>
     */
    #[Computed]
    public function alerts(): LengthAwarePaginator
    {
        return Alert::query()
            ->whereIn('device_id', $this->devices->modelKeys())
            ->when($this->device !== '', fn ($query) => $query->where('device_id', (int) $this->device))
            ->when(AlertType::tryFrom($this->type), fn ($query, AlertType $type) => $query->where('type', $type))
            ->when($this->state === 'open', fn ($query) => $query->whereNull('acknowledged_at'))
            ->when($this->state === 'seen', fn ($query) => $query->whereNotNull('acknowledged_at'))
            ->with('device')
            ->latest()
            ->paginate(15);
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
    <div>
        <flux:heading size="xl" level="1">{{ __('Alerts') }}</flux:heading>
        <flux:text>{{ __('Every alert from your motorcycles, newest first.') }}</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
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

        <flux:select wire:model.live="state" :label="__('Status')">
            <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
            <flux:select.option value="open">{{ __('Not acknowledged') }}</flux:select.option>
            <flux:select.option value="seen">{{ __('Acknowledged') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
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
                    <tr wire:key="alert-{{ $alert->id }}">
                        <td class="px-4 py-3 font-medium">{{ $alert->type->label() }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('devices.show', $alert->device) }}" wire:navigate class="hover:underline">{{ $alert->device->name }}</a>
                        </td>
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
                        <td colspan="6" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">{{ __('No alerts match these filters.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->alerts->links() }}
</section>
