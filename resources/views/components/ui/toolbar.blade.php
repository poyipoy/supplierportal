@props([
    'sticky' => false,
])

<div {{ $attributes->class(['ui-toolbar tw-flex tw-flex-col sm:tw-flex-row tw-flex-wrap tw-items-center tw-justify-between tw-bg-surface-container tw-border tw-border-outline tw-rounded-ui-md', 'ui-toolbar--sticky' => $sticky]) }}>
    @isset($search)
        <div class="tw-flex-1 tw-min-w-[220px] tw-max-w-md">
            {{ $search }}
        </div>
    @endisset

    @if(isset($filters) || isset($slot))
        <div class="ui-toolbar__left tw-flex tw-flex-wrap tw-items-center tw-flex-1 tw-min-w-0">
            @isset($filters)
                {{ $filters }}
            @endisset
            {{ $slot }}
        </div>
    @endif

    @isset($actions)
        <div class="ui-toolbar__right tw-flex tw-flex-wrap tw-items-center tw-shrink-0 tw-ms-auto">
            {{ $actions }}
        </div>
    @endisset
</div>
