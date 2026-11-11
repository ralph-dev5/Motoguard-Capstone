@props([
    'alert',
])

{{-- Texts are sent by the device through the A7670G; the server sends none. --}}
@if ($alert->sms_sent)
    {{ __('Sent') }}
@elseif ($alert->type === \App\Enums\AlertType::GeofenceExit)
    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Website only') }}</span>
@elseif ($alert->level && ! $alert->level->notifiesOwner())
    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Not needed') }}</span>
@else
    {{ __('Pending') }}
@endif
