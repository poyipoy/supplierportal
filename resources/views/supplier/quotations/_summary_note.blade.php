{{--
    Full-width free-text row inside a summary <dl>. Long text is clamped to two
    lines with a toggle; without Alpine the full text stays visible.

    @param string $label
    @param string $text
    @param bool   $clamp
--}}
<div class="tw:col-span-full" @if($clamp) x-data="{ open: false }" @endif>
    <dt class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:text-on-surface-variant">{{ $label }}</dt>
    <dd class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface">
        <div class="tw:whitespace-pre-line" @if($clamp) x-bind:class="{ 'tw:line-clamp-2': !open }" @endif>{{ $text }}</div>
        @if($clamp)
            <button type="button" class="btn btn-link tw:p-0 tw:text-ui-xs" x-cloak x-on:click="open = !open" x-bind:aria-expanded="open.toString()"
                x-text="open ? @js(__('supplier.copy.show_less')) : @js(__('supplier.copy.show_more'))">{{ __('supplier.copy.show_more') }}</button>
        @endif
    </dd>
</div>
