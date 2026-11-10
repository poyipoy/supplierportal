@props([
    'title',
    'status' => null,
    'href' => null,
    'subtitle' => null,
])

<article {{ $attributes->class(['ui-mobile-card tw:flex tw:flex-col tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-3.5 tw:shadow-xs tw:transition-colors tw:hover:border-primary/50']) }}>
    {{-- Card Header: Title + Status --}}
    <div class="tw:flex tw:items-start tw:justify-between tw:gap-2 tw:mb-2.5">
        <div class="tw:min-w-0 tw:flex-1">
            @if($href)
                <a href="{{ $href }}" class="tw:font-bold tw:text-ui-sm tw:text-on-surface tw:hover:text-primary tw:decoration-transparent tw:hover:underline">
                    {{ $title }}
                </a>
            @else
                <h3 class="tw:m-0 tw:font-bold tw:text-ui-sm tw:text-on-surface">{{ $title }}</h3>
            @endif
            @if($subtitle)
                <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ $subtitle }}</p>
            @endif
        </div>
        @if($status || isset($statusSlot))
            <div class="tw:shrink-0">
                {{ $statusSlot ?? $status }}
            </div>
        @endif
    </div>

    {{-- Card Body: Key-Value Grid --}}
    @if(isset($slot) && !empty(trim((string)$slot)))
        <div class="ui-mobile-card__body tw:grid tw:grid-cols-2 tw:gap-x-3 tw:gap-y-2 tw:text-ui-xs tw:py-2.5 tw:border-t tw:border-b tw:border-outline-variant/70">
            {{ $slot }}
        </div>
    @endif

    {{-- Card Footer: Actions --}}
    @isset($actions)
        <div class="ui-mobile-card__actions tw:mt-3 tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:justify-end">
            {{ $actions }}
        </div>
    @endisset
</article>

