@props([
    'alert',
])

@if ($alert->sms_sent)
    {{ __('Sent') }}
@elseif ($alert->level && ! $alert->level->notifiesOwner())
    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Not needed') }}</span>
@else
    {{ __('Pending') }}
@endif
