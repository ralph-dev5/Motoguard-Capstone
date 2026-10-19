{{-- Heading above a group of cards, with an optional link or button on the right. --}}
@props(['title', 'icon' => null])

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3') }}>
    <h2 class="flex items-center gap-2 text-base font-semibold text-zinc-900 dark:text-white">
        @if ($icon)
            <flux:icon :name="$icon" variant="mini" class="size-5 text-zinc-400" />
        @endif
        {{ $title }}
    </h2>
    {{ $slot }}
</div>
