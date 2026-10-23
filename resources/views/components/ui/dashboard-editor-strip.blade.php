@props(['label', 'required' => false, 'visible' => true])

{{-- Edit-mode header of one dashboard panel. Hidden until resources/js/dashboard-editor.js enters edit mode;
     the arrow buttons are the keyboard/touch alternative to dragging. --}}
@php
    $moveButton = 'ui-button ui-focus-ring ui-motion tw:inline-flex tw:h-10 tw:w-10 tw:items-center tw:justify-center tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface tw:text-on-surface tw:hover:bg-surface-container';
@endphp

<div class="tw:flex tw:items-center tw:gap-2 tw:rounded-t-ui-sm tw:border tw:border-b-0 tw:border-outline tw:bg-surface-container tw:px-2 tw:py-1" data-editor-strip draggable="true" hidden>
    <span class="tw:hidden tw:cursor-grab tw:items-center tw:text-on-surface-variant tw:active:cursor-grabbing tw:lg:inline-flex" data-editor-handle title="{{ __('customization.drag', ['label' => $label]) }}" aria-hidden="true">
        <x-ui.icon name="grip-vertical" size="sm" />
    </span>
    <span class="tw:min-w-0 tw:flex-1 tw:truncate tw:text-ui-sm tw:font-semibold">{{ $label }}</span>
    <span class="tw:shrink-0 tw:text-ui-xs tw:text-on-surface-variant" data-editor-strip-status hidden></span>
    @if($required)
        <span class="tw:inline-flex tw:shrink-0 tw:items-center tw:gap-1 tw:text-ui-xs tw:text-on-surface-variant">
            <x-ui.icon name="lock" size="xs" />{{ __('customization.always_shown') }}
        </span>
    @else
        <label class="tw:inline-flex tw:min-h-10 tw:shrink-0 tw:cursor-pointer tw:items-center tw:gap-2 tw:text-ui-xs">
            <x-ui.switch :checked="$visible" data-editor-visibility aria-label="{{ __('customization.show', ['label' => $label]) }}" />
            <span class="tw:hidden tw:sm:inline">{{ __('customization.dashboard_visible') }}</span>
        </label>
    @endif
    <span class="tw:inline-flex tw:shrink-0 tw:gap-1">
        <button type="button" class="{{ $moveButton }}" data-editor-move="up" aria-label="{{ __('customization.move_up_label', ['label' => $label]) }}"><x-ui.icon name="arrow-up" size="sm" /></button>
        <button type="button" class="{{ $moveButton }}" data-editor-move="down" aria-label="{{ __('customization.move_down_label', ['label' => $label]) }}"><x-ui.icon name="arrow-down" size="sm" /></button>
    </span>
</div>
