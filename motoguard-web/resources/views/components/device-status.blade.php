@props([
    'device',
    'size' => null,
])

@php
    // Rendered server-side first so the badge is correct before Alpine boots and never flashes
    // the wrong state; devicePresence then keeps it honest between polls.
    $online = $device->isRecentlySeen();
    [$lastSeen, $threshold] = $device->presenceSignal();

    $base = 'inline-flex items-center font-medium whitespace-nowrap [print-color-adjust:exact] rounded-md '
        .($size === 'sm' ? 'text-xs px-1.5 py-0.5' : 'text-sm px-2 py-1');

    $onTone = 'text-green-700 dark:text-green-200 bg-green-400/20 dark:bg-green-400/40';
    $offTone = 'text-zinc-700 dark:text-zinc-200 bg-zinc-400/20 dark:bg-zinc-400/40';

    $title = $device->last_seen_at === null
        ? __('No heartbeat received yet.')
        : ($online
            ? __('Last heartbeat :time', ['time' => $device->last_seen_at->diffForHumans()])
            : __('Silent since :time', ['time' => $device->last_seen_at->diffForHumans()]));
@endphp

<span
    x-data="devicePresence({ lastSeen: {{ $lastSeen ?? 'null' }}, threshold: {{ $threshold }}, deviceId: {{ $device->id }} })"
    :title="detail"
    title="{{ $title }}"
    {{ $attributes->class([$base, $onTone => $online, $offTone => ! $online]) }}
    :class="online
        ? '{{ $onTone }}'
        : '{{ $offTone }}'"
>
    <span class="relative mr-1.5 flex size-2">
        {{-- Only the live state pulses, so on/off reads without having to parse the label. --}}
        <span
            x-show="online"
            x-cloak
            class="absolute inline-flex size-full animate-ping rounded-full bg-green-500 opacity-75"
        ></span>
        <span
            class="relative inline-flex size-2 rounded-full"
            :class="online ? 'bg-green-500' : 'bg-zinc-400 dark:bg-zinc-500'"
            @class(['bg-green-500' => $online, 'bg-zinc-400 dark:bg-zinc-500' => ! $online])
        ></span>
    </span>
    <span x-text="label">{{ $online ? __('Device on') : __('Device off') }}</span>
</span>
