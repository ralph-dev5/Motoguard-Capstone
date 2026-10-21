@props([
    'alert',
    'showDevice' => false,
])

{{--
    One alert as a stacked card, used instead of the alert table on phone screens: the table's
    Location, Text message and Acknowledge columns ended up off-screen behind a sideways scroll.
    Must be rendered inside a Livewire component that has acknowledge(int $alertId).
--}}
@php
    $open = ! $alert->acknowledged_at;
    $edge = match (true) {
        ! $open => 'border-l-transparent',
        $alert->level === null || $alert->level === \App\Enums\AlertLevel::TheftAttempt => 'border-l-red-500 bg-red-500/[0.04]',
        $alert->level === \App\Enums\AlertLevel::Suspicious => 'border-l-amber-500',
        default => 'border-l-transparent',
    };
@endphp

<li {{ $attributes->class(['space-y-3 border-l-4 p-4', $edge]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <x-alert-level :alert="$alert" />
                <span class="font-medium {{ $open ? 'text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $alert->type->label() }}</span>
            </div>
            <x-alert-evidence :alert="$alert" class="mt-1 block" />
            @if ($showDevice)
                <a href="{{ route('devices.show', $alert->device) }}" wire:navigate class="mt-1 block text-xs text-zinc-500 hover:underline dark:text-zinc-400">{{ $alert->device->label() }}</a>
            @endif
        </div>
        @if ($open)
            <flux:button size="sm" variant="primary" icon="check" wire:click="acknowledge({{ $alert->id }})" class="shrink-0">{{ __('Acknowledge') }}</flux:button>
        @else
            <flux:badge size="sm" color="zinc" class="shrink-0">{{ __('Seen') }}</flux:badge>
        @endif
    </div>

    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2 text-sm">
        <x-alert-time :alert="$alert" />
        <div class="flex items-center gap-4">
            <x-alert-location :alert="$alert" />
            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Text') }}: <x-alert-sms :alert="$alert" /></span>
        </div>
    </div>
</li>
