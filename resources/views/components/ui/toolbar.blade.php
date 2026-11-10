@props([
    'sticky' => false,
])

<div {{ $attributes->class(['ui-toolbar tw:flex tw:flex-col tw:sm:flex-row tw:flex-wrap tw:items-stretch tw:sm:items-center tw:justify-between tw:gap-2.5 tw:bg-surface-container tw:border tw:border-outline tw:rounded-ui-md', 'ui-toolbar--sticky' => $sticky]) }}>
    @isset($search)
        <div class="tw:w-full tw:sm:w-auto tw:flex-1 tw:min-w-0 tw:sm:min-w-[220px] tw:max-w-md">
            {{ $search }}
        </div>
    @endisset

    @if(isset($filters) || isset($slot))
        <div class="ui-toolbar__left tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:flex-1 tw:min-w-0 tw:w-full tw:sm:w-auto">
            @isset($filters)
                {{ $filters }}
            @endisset
            {{ $slot }}
        </div>
    @endif

    @isset($actions)
        <div class="ui-toolbar__right tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:shrink-0 tw:w-full tw:sm:w-auto tw:justify-end tw:ms-auto">
            {{ $actions }}
        </div>
    @endisset
</div>
