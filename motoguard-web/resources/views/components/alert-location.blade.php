@props([
    'alert',
])

@php
    use App\Support\GpsStatus;

    // An alert stores whatever position the device had when it fired, so a missing one always
    // means the receiver had no fix at that moment. A bare dash left the owner guessing whether
    // the coordinates failed to save or were never available, which are very different problems.
    $device = $alert->relationLoaded('device') ? $alert->device : null;
    $gps = $device?->gpsStatus();

    $why = match (true) {
        $gps === GpsStatus::NoData => __('the GPS module was not sending data'),
        $gps === GpsStatus::Searching, $gps === GpsStatus::Acquiring => __('the GPS had not locked on yet'),
        default => null,
    };

    $title = $why !== null
        ? __('No position recorded — :why.', ['why' => $why])
        : __('No position recorded. The device had no GPS fix when this alert fired.');
@endphp

@if ($alert->location)
    <flux:link :href="$alert->location->mapsUrl()" target="_blank" rel="noopener">{{ __('Open map') }}</flux:link>
@else
    <span
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-zinc-500 dark:text-zinc-400']) }}
        title="{{ $title }}"
    >
        <flux:icon name="map-pin" variant="micro" class="opacity-60" />
        {{ __('No GPS fix') }}
    </span>
@endif
