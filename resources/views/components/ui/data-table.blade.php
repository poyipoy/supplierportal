@props([
    'title' => null,
    'description' => null,
    'loading' => false,
    'empty' => false,
    'error' => null,
    'density' => 'auto',
    // Opt-in bounded scroll with sticky header/footer (Decision 09). The
    // scroll region then needs an accessible name for keyboard users.
    'sticky' => false,
    'scrollLabel' => null,
])

<section {{ $attributes->class(['ui-data-table tw-min-w-0 tw-max-w-full tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface tw-shadow-none', 'ui-table--compact' => $density === 'compact']) }}>
    @if($title || $description || isset($toolbar) || isset($actions) || isset($filters))
        <header class="ui-data-table__header tw-grid tw-border-b tw-border-outline-variant tw-bg-surface-container ">
            <div class="tw-flex tw-flex-col tw-gap-2.5 shell:tw-flex-row shell:tw-items-center shell:tw-justify-between">
                <div class="tw-min-w-0">
                    @if($title)<h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">{{ $title }}</h2>@endif
                    @if($description)<p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ $description }}</p>@endif
                </div>
                @if(isset($toolbar))
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $toolbar }}</div>
                @elseif(isset($actions))
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $actions }}</div>
                @endif
            </div>
            @isset($filters)<div class="tw-flex tw-flex-col tw-gap-2.5 shell:tw-flex-row shell:tw-flex-wrap shell:tw-items-end">{{ $filters }}</div>@endisset
        </header>
    @endif

    @if($loading)
        <div class="tw-grid tw-gap-2 tw-p-4" role="status" aria-label="{{ __('common.states.loading') }}">
            @for($row = 0; $row < 5; $row++)<x-ui.skeleton class="tw-h-10 tw-w-full" />@endfor
        </div>
    @elseif($error)
        <div class="tw-p-4"><x-ui.alert tone="error" :title="__('common.states.load_failed')">{{ $error }}</x-ui.alert></div>
    @elseif($empty)
        @isset($emptyState){{ $emptyState }}@else<x-ui.empty-state :title="__('common.states.empty')" :description="__('common.states.empty_description')" />@endisset
    @else
        @if($sticky)
            <div class="ui-data-table__scroll ui-table-sticky tw-w-full tw-min-w-0 tw-max-w-full" role="region" tabindex="0" aria-label="{{ $scrollLabel ?? $title }}">{{ $slot }}</div>
        @else
            <div class="ui-data-table__scroll tw-overflow-x-auto tw-w-full tw-min-w-0 tw-max-w-full">{{ $slot }}</div>
        @endif
    @endif

    @isset($pagination)<footer class="ui-data-table__footer tw-border-t tw-border-outline-variant tw-bg-surface-low ">{{ $pagination }}</footer>@endisset
</section>
