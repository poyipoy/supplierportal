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

<section {{ $attributes->class(['ui-data-table tw:min-w-0 tw:max-w-full tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-ui-surface', 'ui-table--compact' => $density === 'compact']) }}>
    @if($title || $description || isset($toolbar) || isset($actions) || isset($filters))
        <header class="ui-data-table__header tw:grid tw:border-b tw:border-outline-variant">
            <div class="tw:flex tw:flex-col tw:gap-2.5 tw:shell:flex-row tw:shell:items-center tw:shell:justify-between">
                <div class="tw:min-w-0">
                    @if($title)<h2 class="tw:m-0 tw:text-base tw:font-bold tw:text-on-surface">{{ $title }}</h2>@endif
                    @if($description)<p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ $description }}</p>@endif
                </div>
                @if(isset($toolbar))
                    <div class="tw:flex tw:shrink-0 tw:flex-wrap tw:items-center tw:gap-2 tw:shell:justify-end">{{ $toolbar }}</div>
                @elseif(isset($actions))
                    <div class="tw:flex tw:shrink-0 tw:flex-wrap tw:items-center tw:gap-2 tw:shell:justify-end">{{ $actions }}</div>
                @endif
            </div>
            @isset($filters)<div class="tw:flex tw:flex-col tw:gap-2.5 tw:shell:flex-row tw:shell:flex-wrap tw:shell:items-end">{{ $filters }}</div>@endisset
        </header>
    @endif

    @if($loading)
        <div class="ui-data-table__skeleton" role="status" aria-label="{{ __('common.states.loading') }}">
            <div class="tw:grid tw:grid-cols-12 tw:gap-3 tw:px-4 tw:py-2.5 tw:bg-surface-container tw:border-b tw:border-outline-variant">
                <x-ui.skeleton class="tw:col-span-3 tw:h-3.5" />
                <x-ui.skeleton class="tw:col-span-4 tw:h-3.5" />
                <x-ui.skeleton class="tw:col-span-3 tw:h-3.5" />
                <x-ui.skeleton class="tw:col-span-2 tw:h-3.5" />
            </div>
            @foreach([['tw:w-4/5', 'tw:w-11/12', 'tw:w-3/4', 'tw:w-2/3'], ['tw:w-3/4', 'tw:w-4/5', 'tw:w-2/3', 'tw:w-3/4'], ['tw:w-11/12', 'tw:w-3/4', 'tw:w-4/5', 'tw:w-1/2'], ['tw:w-2/3', 'tw:w-11/12', 'tw:w-3/4', 'tw:w-2/3'], ['tw:w-4/5', 'tw:w-2/3', 'tw:w-11/12', 'tw:w-3/4']] as $widths)
                <div class="tw:grid tw:grid-cols-12 tw:items-center tw:gap-3 tw:px-4 tw:py-3 tw:border-b tw:border-outline-variant tw:last:border-b-0">
                    <div class="tw:min-w-0 tw:col-span-3"><x-ui.skeleton class="tw:h-3.5 {{ $widths[0] }}" /></div>
                    <div class="tw:min-w-0 tw:col-span-4"><x-ui.skeleton class="tw:h-3.5 {{ $widths[1] }}" /></div>
                    <div class="tw:min-w-0 tw:col-span-3"><x-ui.skeleton class="tw:h-3.5 {{ $widths[2] }}" /></div>
                    <div class="tw:min-w-0 tw:col-span-2"><x-ui.skeleton class="tw:h-3.5 {{ $widths[3] }}" /></div>
                </div>
            @endforeach
        </div>
    @elseif($error)
        <div class="tw:p-4"><x-ui.alert tone="error" :title="__('common.states.load_failed')">{{ $error }}</x-ui.alert></div>
    @elseif($empty)
        @isset($emptyState){{ $emptyState }}@else<x-ui.empty-state :title="__('common.states.empty')" :description="__('common.states.empty_description')" />@endisset
    @else
        @isset($cards)
            <div x-data="{ viewMode: 'cards' }" class="ui-data-table__hybrid">
                <div class="tw:md:hidden tw:flex tw:items-center tw:justify-between tw:px-3.5 tw:py-2 tw:bg-surface-container-low tw:border-b tw:border-outline-variant">
                    <span class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant" x-text="viewMode === 'cards' ? 'Mode Kartu (Mobile)' : 'Mode Tabel'"></span>
                    <div class="tw:inline-flex tw:items-center tw:rounded-ui-xs tw:bg-surface-container tw:p-0.5 tw:border tw:border-outline" role="group" aria-label="Table display mode">
                        <button type="button" class="ui-focus-ring tw:flex tw:items-center tw:gap-1 tw:px-2.5 tw:py-1 tw:rounded-ui-xs tw:text-ui-xs tw:font-semibold tw:border-0" :class="viewMode === 'cards' ? 'tw:bg-surface tw:text-primary tw:shadow-xs' : 'tw:bg-transparent tw:text-on-surface-variant'" x-on:click="viewMode = 'cards'">
                            <x-ui.icon name="layout-grid" size="xs" />
                            <span>Cards</span>
                        </button>
                        <button type="button" class="ui-focus-ring tw:flex tw:items-center tw:gap-1 tw:px-2.5 tw:py-1 tw:rounded-ui-xs tw:text-ui-xs tw:font-semibold tw:border-0" :class="viewMode === 'table' ? 'tw:bg-surface tw:text-primary tw:shadow-xs' : 'tw:bg-transparent tw:text-on-surface-variant'" x-on:click="viewMode = 'table'">
                            <x-ui.icon name="table" size="xs" />
                            <span>Table</span>
                        </button>
                    </div>
                </div>
                <div class="ui-data-table__cards-container tw:p-3 tw:grid tw:gap-2.5 tw:md:hidden" x-show="viewMode === 'cards'">
                    {{ $cards }}
                </div>
                <div class="ui-data-table__scroll tw:overflow-x-auto tw:w-full tw:min-w-0 tw:max-w-full" role="region" tabindex="0" aria-label="{{ $scrollLabel ?? $title ?? __('js.tables.scroll_region') }}" :class="{ 'tw:hidden tw:md:block': viewMode === 'cards' }">
                    {{ $slot }}
                </div>
            </div>
        @else
            @if($sticky)
                <div class="ui-data-table__scroll ui-table-sticky tw:w-full tw:min-w-0 tw:max-w-full" role="region" tabindex="0" aria-label="{{ $scrollLabel ?? $title ?? __('js.tables.scroll_region') }}">{{ $slot }}</div>
            @else
                <div class="ui-data-table__scroll tw:overflow-x-auto tw:w-full tw:min-w-0 tw:max-w-full" role="region" tabindex="0" aria-label="{{ $scrollLabel ?? $title ?? __('js.tables.scroll_region') }}">{{ $slot }}</div>
            @endif
        @endisset
    @endif

    @isset($pagination)<footer class="ui-data-table__footer tw:border-t tw:border-outline-variant tw:bg-surface-low">{{ $pagination }}</footer>@endisset
</section>
