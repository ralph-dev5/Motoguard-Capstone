@props([
    'device',
])

@php
    $battery = $device->battery();
    // A stored voltage is never cleared, so once the device stops reporting the number
    // becomes a last-known reading rather than a current one. Say so instead of implying live data.
    $stale = $battery !== null && ! $device->isRecentlySeen();

    $tone = $battery === null ? null : match ($battery->color()) {
        'red' => 'text-red-600 dark:text-red-400',
        'amber' => 'text-amber-600 dark:text-amber-400',
        default => 'text-green-600 dark:text-green-400',
    };

    $title = $battery === null
        ? __('No battery reading yet.')
        : number_format($battery->volts, 2).' V'
            .($stale ? ' · '.__('as of :time', ['time' => $device->last_seen_at?->diffForHumans()]) : '');
@endphp

@if ($battery === null)
    <span {{ $attributes->merge(['class' => 'text-zinc-500 dark:text-zinc-400']) }} title="{{ $title }}">—</span>
@else
    <span
        {{ $attributes->class(['inline-flex items-center gap-1 tabular-nums', $tone, 'opacity-60' => $stale]) }}
        title="{{ $title }}"
    >
        <flux:icon :name="$battery->icon()" variant="micro" />
        {{ $battery->label() }}
    </span>
@endif
