{{-- Top of every page: icon, title, one-line description, and the page's main actions on the right. --}}
@props(['title', 'description' => null, 'icon' => null])

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-5 dark:border-zinc-800') }}>
    <div class="flex min-w-0 items-center gap-3">
        @if ($icon)
            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-accent/10 text-accent-content">
                <flux:icon :name="$icon" class="size-6" />
            </span>
        @endif
        <div class="min-w-0">
            {{ $before ?? '' }}
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            @if ($description)
                <flux:text class="mt-0.5">{{ $description }}</flux:text>
            @endif
        </div>
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
