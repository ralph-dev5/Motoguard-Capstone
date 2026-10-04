@props([
    'alert',
])

{{-- "2 days ago" alone makes a burst of alerts look identical; the clock time tells them apart. --}}
@php
    $at = $alert->created_at->timezone(config('app.display_timezone'));
@endphp

<div {{ $attributes->class('whitespace-nowrap') }}>
    <div class="tabular-nums text-zinc-900 dark:text-zinc-100">
        {{ $at->isToday() ? __('Today') : ($at->isYesterday() ? __('Yesterday') : $at->format('M j')) }}, {{ $at->format('g:i:s A') }}
    </div>
    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $alert->created_at->diffForHumans() }}</div>
</div>
