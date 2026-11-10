@props([
    'sticky' => true,
])

<div {{ $attributes->class([
    'ui-action-bar tw:flex tw:flex-col-reverse tw:sm:flex-row tw:items-stretch tw:sm:items-center tw:justify-between tw:gap-2.5 tw:sm:gap-3 tw:py-3 tw:px-4 tw:md:px-6 tw:bg-surface tw:border-t tw:border-outline-variant',
    'tw:sticky tw:bottom-0 tw:z-20' => $sticky,
]) }} style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom, 0px));">
    <div class="ui-action-bar__left tw:flex tw:items-center tw:gap-2.5 tw:flex-wrap tw:w-full tw:sm:w-auto">
        @isset($left)
            {{ $left }}
        @endisset
    </div>

    <div class="ui-action-bar__right tw:flex tw:items-center tw:gap-2.5 tw:flex-wrap tw:w-full tw:sm:w-auto tw:justify-end tw:ms-auto">
        @isset($right)
            {{ $right }}
        @else
            {{ $slot }}
        @endisset
    </div>
</div>
