@props([
    'device',
    'size' => null,
])

@php
    use App\Support\GpsStatus;

    $gps = $device->gpsStatus();

    // Satellite count is the number that tells the owner whether waiting will help, so show it
    // whenever the receiver is hearing anything at all.
    $count = $device->gps_satellites;
    $showCount = $count !== null && in_array($gps, [GpsStatus::Acquiring, GpsStatus::Fix], true);

    $title = trim($gps->label().($gps->hint() !== '' ? ' — '.$gps->hint() : ''));

    if ($gps === GpsStatus::Fix && $device->gps_fix_at !== null) {
        $title = __('Position fixed :time', ['time' => $device->gps_fix_at->diffForHumans()]);
    }
@endphp

<flux:badge :size="$size" :color="$gps->color()" :icon="$gps->icon()" {{ $attributes }} title="{{ $title }}">
    {{ $gps->label() }}@if ($showCount) <span class="ml-1 tabular-nums opacity-70">{{ $count }}</span>@endif
</flux:badge>
