@props([
    'device',
])

@php
    // How the device reaches the server: the usual WiFi, the owner's phone hotspot, or the
    // A7670G's own 4G mobile data when no WiFi is in reach. The last one reported is shown;
    // while the device is silent it reads "Last via ..." so it never claims a live link.
    $network = $device->network;
    $onHotspot = $network === 'wifi'
        && $device->hotspot_ssid !== null
        && $device->network_name === $device->hotspot_ssid;

    [$icon, $label, $tone] = match (true) {
        $network === 'cellular' => ['signal', __('Mobile data (A7670G 4G)'), 'text-amber-700 dark:text-amber-300 bg-amber-400/20 dark:bg-amber-400/30'],
        $onHotspot => ['device-phone-mobile', __('Phone hotspot'), 'text-blue-700 dark:text-blue-200 bg-blue-400/20 dark:bg-blue-400/30'],
        $network === 'wifi' => ['wifi', __('Home WiFi'), 'text-sky-700 dark:text-sky-200 bg-sky-400/20 dark:bg-sky-400/30'],
        default => [null, null, null],
    };

    $live = $device->isRecentlySeen();
@endphp

@if ($label !== null)
    <span {{ $attributes->class(['inline-flex max-w-full items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium', $tone]) }}
        title="{{ $network === 'wifi' && $device->network_name ? $device->network_name : $label }}">
        <flux:icon :name="$icon" variant="micro" class="shrink-0" />
        <span class="truncate">
            {{ $live ? $label : __('Last via :network', ['network' => $label]) }}@if ($network === 'wifi' && $device->network_name && ! $onHotspot) <span class="font-normal opacity-80">· {{ $device->network_name }}</span>@endif
        </span>
    </span>
@endif
