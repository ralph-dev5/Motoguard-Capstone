@props([
    'alert',
])

{{--
    Why the device chose this level, in words: the classifier weighs touches, jolts, tilt and how
    long the episode ran, and the owner should not have to trust a badge without seeing that.
--}}
@php
    $payload = $alert->payload ?? [];
    $parts = [];

    if (isset($payload['knocks']) || isset($payload['jolts'])) {
        $touches = (int) ($payload['knocks'] ?? 0);
        $jolts = (int) ($payload['jolts'] ?? 0);

        if ($touches > 0) {
            $parts[] = trans_choice('{1} :count knock|[2,*] :count knocks', $touches, ['count' => $touches]);
        }
        if ($jolts > 0) {
            $parts[] = trans_choice('{1} :count jolt|[2,*] :count jolts', $jolts, ['count' => $jolts]);
        }
        if (($payload['max_tilt_deg'] ?? 0) >= 5) {
            $parts[] = __('tilted :deg°', ['deg' => number_format((float) $payload['max_tilt_deg'], 0)]);
        }
        if (($payload['duration_ms'] ?? 0) > 0) {
            $seconds = $payload['duration_ms'] / 1000;
            $parts[] = $seconds < 60
                ? __(':s s', ['s' => number_format($seconds, $seconds < 10 ? 1 : 0)])
                : __(':m min', ['m' => number_format($seconds / 60, 1)]);
        }
    } elseif (isset($payload['distance_m'], $payload['radius_m'])) {
        $parts[] = __(':d m from the center (zone :r m)', ['d' => number_format($payload['distance_m']), 'r' => number_format($payload['radius_m'])]);
    }
@endphp

@if ($parts)
    <span {{ $attributes->class('text-xs text-zinc-500 dark:text-zinc-400') }}>{{ implode(' · ', $parts) }}</span>
@endif
