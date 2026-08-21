@props([
    'title' => null,
    'description' => null,
    'loading' => false,
    'empty' => false,
    'error' => null,
    'density' => 'compact',
])

<section {{ $attributes->class(['ui-data-table tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-shadow-ui-1', 'ui-table--compact' => $density === 'compact']) }}>
    @if($title || $description || isset($toolbar) || isset($filters))
        <header class="tw-grid tw-gap-2.5 tw-border-b tw-border-outline-variant tw-p-3.5 shell:tw-px-4">
            <div class="tw-flex tw-flex-col tw-gap-2.5 shell:tw-flex-row shell:tw-items-center shell:tw-justify-between">
                <div class="tw-min-w-0">
                    @if($title)<h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">{{ $title }}</h2>@endif
                    @if($description)<p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ $description }}</p>@endif
                </div>
                @isset($toolbar)<div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $toolbar }}</div>@endisset
            </div>
            @isset($filters)<div class="tw-flex tw-flex-col tw-gap-2.5 shell:tw-flex-row shell:tw-flex-wrap shell:tw-items-end">{{ $filters }}</div>@endisset
        </header>
    @endif

    @if($loading)
        <div class="tw-grid tw-gap-2 tw-p-4" role="status" aria-label="Loading data">
            @for($row = 0; $row < 5; $row++)<x-ui.skeleton class="tw-h-10 tw-w-full" />@endfor
        </div>
    @elseif($error)
        <div class="tw-p-4"><x-ui.alert tone="error" title="Unable to load data">{{ $error }}</x-ui.alert></div>
    @elseif($empty)
        @isset($emptyState){{ $emptyState }}@else<x-ui.empty-state title="No data available" description="Records will appear here when they become available." />@endisset
    @else
        <div class="ui-data-table__scroll tw-overflow-x-auto tw-w-full">{{ $slot }}</div>
    @endif

    @isset($pagination)<footer class="tw-border-t tw-border-outline-variant tw-p-3.5 shell:tw-px-4">{{ $pagination }}</footer>@endisset
</section>
