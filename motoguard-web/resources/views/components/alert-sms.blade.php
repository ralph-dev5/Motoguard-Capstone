@props([
    'alert',
])

@if ($alert->sms_sent)
    {{ __('Sent') }}
    {{-- Networks drop texts that contain a link, so the SMS carried bare coordinates; the clickable map link is shown here instead. --}}
    @if ($alert->location)
        <flux:link :href="$alert->location->mapsUrl()" target="_blank" rel="noopener" class="block max-w-56 whitespace-normal break-all text-xs">{{ $alert->location->mapsUrl() }}</flux:link>
    @endif
@elseif ($alert->level && ! $alert->level->notifiesOwner())
    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Not needed') }}</span>
@else
    {{ __('Pending') }}
@endif
