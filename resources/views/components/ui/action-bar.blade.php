@props([
    'sticky' => true,
])

<div {{ $attributes->class([
    'ui-action-bar tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-py-3 tw-px-4 md:tw-px-6 tw-bg-surface tw-border-t tw-border-outline-variant',
    'tw-sticky tw-bottom-0 tw-z-20' => $sticky,
]) }}>
    <div class="ui-action-bar__left tw-flex tw-items-center tw-gap-2.5 tw-flex-wrap">
        @isset($left)
            {{ $left }}
        @endisset
    </div>

    <div class="ui-action-bar__right tw-flex tw-items-center tw-gap-2.5 tw-flex-wrap tw-ms-auto">
        @isset($right)
            {{ $right }}
        @else
            {{ $slot }}
        @endisset
    </div>
</div>
