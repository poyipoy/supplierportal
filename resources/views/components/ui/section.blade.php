@props([
    'title' => null,
    'description' => null,
    'divider' => true,
])

<section {{ $attributes->class(['ui-section tw:flex tw:flex-col']) }}>
    @if($title || $description || isset($actions))
        <header @class([
            'tw:flex tw:flex-col tw:gap-1 tw:shell:flex-row tw:shell:items-end tw:shell:justify-between',
            'tw:pb-2 tw:border-b tw:border-outline' => $divider,
        ])>
            <div class="tw:min-w-0">
                @if($title)<h2 class="ui-section__title tw:m-0 tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">{{ $title }}</h2>@endif
                @if($description)<p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="tw:flex tw:shrink-0 tw:flex-wrap tw:items-center tw:gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div>
        {{ $slot }}
    </div>
</section>
