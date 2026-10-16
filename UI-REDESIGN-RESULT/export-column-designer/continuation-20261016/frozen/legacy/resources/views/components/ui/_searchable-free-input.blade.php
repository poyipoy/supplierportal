<div {{ $attributes->class(['ui-searchable-free-input tw-relative tw-min-w-0']) }}
    x-data="searchableFreeInput(@js(['value' => $resolvedValue, 'url' => $suggestionsUrl, 'messages' => [
        'minimum' => __('common.select.free_minimum'), 'loading' => __('common.select.free_loading'),
        'failed' => __('common.select.free_failed'), 'results' => __('common.select.free_results'), 'empty' => __('common.select.free_empty'),
    ]]))" @click.outside="close()">
    @if($label)<label for="{{ $resolvedId }}" class="form-label tw-flex tw-items-center tw-min-h-6 tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1">{{ $label }}</label>@endif
    <div class="tw-relative tw-min-w-0">
    <span class="ui-searchable-free-input__search" aria-hidden="true"><x-ui.icon name="search" size="sm" /></span>
    <input id="{{ $resolvedId }}" name="{{ $name }}" type="text" value="{{ $resolvedValue }}" x-ref="input"
        maxlength="{{ $maxlength }}" autocomplete="off" role="combobox" aria-autocomplete="list"
        aria-controls="{{ $resolvedId }}-suggestions" :aria-expanded="open"
        :aria-activedescendant="open && highlighted >= 0 ? '{{ $resolvedId }}-option-' + highlighted : null"
        aria-describedby="{{ $resolvedId }}-status{{ $describedBy ? ' '.$describedBy : '' }}"
        @if($message) aria-invalid="true" @endif @required($required) @disabled($disabled)
        x-model="value" @input="input($event.target.value)" @focus="focus()" @blur="close()"
        @keydown.arrow-down.prevent="navigate(1)" @keydown.arrow-up.prevent="navigate(-1)"
        @keydown.enter.prevent="enter()" @keydown.escape.prevent.stop="close()" @keydown.tab="close()"
        placeholder="{{ $placeholder }}" class="ui-searchable-free-input__input form-control form-control-sm tw-w-full tw-min-w-0 {{ $message ? 'is-invalid' : '' }}">
    <span class="ui-searchable-free-input__chevron" :class="open ? 'is-open' : ''" aria-hidden="true"><x-ui.icon name="chevron-down" size="sm" /></span>
    <div x-show="open" x-cloak class="ui-searchable-free-input__menu tw-absolute tw-top-full tw-left-0 tw-right-0 tw-z-50 tw-border tw-border-outline-variant tw-rounded-ui-sm tw-bg-surface tw-text-on-surface tw-max-h-60 tw-overflow-y-auto">
        <ul id="{{ $resolvedId }}-suggestions" role="listbox" aria-label="{{ $label ?: $name }}" x-ref="list" class="tw-list-none tw-m-0 tw-p-1">
            <template x-for="(number, index) in options" :key="number">
                <li role="option" :id="'{{ $resolvedId }}-option-' + index" :aria-selected="highlighted === index"
                    @mousedown.prevent @click="choose(number)" @mouseenter="highlighted = index"
                    :class="{ 'is-highlighted': highlighted === index, 'is-selected': value === number }"
                    class="ui-searchable-free-input__option tw-flex tw-items-center tw-gap-2 tw-min-h-10 tw-p-2 tw-cursor-pointer tw-rounded-ui-sm">
                    <span class="tw-min-w-0 tw-flex-1 tw-font-mono" style="overflow-wrap: anywhere;" x-text="number"></span>
                    <span x-show="value === number" aria-hidden="true" class="tw-shrink-0"><x-ui.icon name="check" size="sm" /></span>
                </li>
            </template>
        </ul>
    </div>
    </div>
    <span id="{{ $resolvedId }}-status" role="status" aria-live="polite" class="tw-block tw-text-ui-xs tw-text-on-surface-variant" x-text="status"></span>
    @if($helper)<p id="{{ $helperId }}" class="form-text tw-m-0">{{ $helper }}</p>@endif
    @if($message)<p id="{{ $errorId }}" class="invalid-feedback tw-block tw-m-0">{{ $message }}</p>@endif
</div>
