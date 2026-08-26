@props([
    'sticky' => false,
])

<div {{ $attributes->class(['ui-toolbar tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-p-3.5 tw-bg-surface-container tw-border tw-border-outline tw-rounded-ui-md tw-mb-4', 'ui-toolbar--sticky' => $sticky]) }}>
    @isset($search)
        <div class="tw-flex-1 tw-min-w-[220px] tw-max-w-md">
            {{ $search }}
        </div>
    @endisset

    @if(isset($filters) || isset($slot))
        <div class="ui-toolbar__left tw-flex tw-flex-wrap tw-items-center tw-gap-2.5 tw-flex-1 tw-min-w-0">
            @isset($filters)
                {{ $filters }}
            @endisset
            {{ $slot }}
        </div>
    @endif

    @isset($actions)
        <div class="ui-toolbar__right tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-shrink-0 tw-ms-auto">
            {{ $actions }}
        </div>
    @endisset
</div>
