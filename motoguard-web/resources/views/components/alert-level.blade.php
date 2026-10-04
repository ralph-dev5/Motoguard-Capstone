@props([
    'alert',
])

{{-- Alerts stored before the device classified episodes have no level; show nothing rather than guess. --}}
@if ($alert->level)
    <flux:badge size="sm" :color="$alert->level->color()" {{ $attributes }}>{{ $alert->level->label() }}</flux:badge>
@endif
