@props([
    'name',
    'id' => null,
    'label' => null,
    'helper' => null,
    'error' => null,
    'value' => null,
    'options' => [],
    'placeholder' => __('common.select.placeholder'),
    'searchPlaceholder' => __('common.select.search'),
    'required' => false,
    'disabled' => false,
    'emptyMessage' => __('common.select.empty'),
    'showSublabelOnTrigger' => true,
    'allowCustom' => false,
    'suggestionsUrl' => null,
    'remote' => false,
    'maxlength' => 100,
    'labelClass' => 'tw:text-ui-sm tw:font-medium tw:text-on-surface',
])

@php
    $showSub = filter_var($showSublabelOnTrigger, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    $resolvedValue = old($validationKey, $value);
    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $message ? $resolvedId . '-error' : null;
    $describedBy = collect([$helperId, $errorId])->filter()->implode(' ');

    $normalizedOptions = collect($options)->map(function ($item, $key) {
        if (is_array($item)) {
            $val = (string) ($item['value'] ?? $key);
            $lbl = (string) ($item['label'] ?? '');
            $sub = isset($item['sublabel']) ? (string) $item['sublabel'] : null;
            $badge = isset($item['badge']) ? (string) $item['badge'] : null;
            $badgeTone = isset($item['badgeTone']) ? (string) $item['badgeTone'] : 'neutral';
            $keywords = (string) ($item['searchKeywords'] ?? ($lbl . ' ' . ($sub ?? '') . ' ' . ($badge ?? '')));
            return [
                'value' => $val,
                'label' => $lbl,
                'sublabel' => $sub,
                'badge' => $badge,
                'badgeTone' => $badgeTone,
                'searchKeywords' => strtolower($keywords),
            ];
        }
        return [
            'value' => (string) $key,
            'label' => (string) $item,
            'sublabel' => null,
            'badge' => null,
            'badgeTone' => 'neutral',
            'searchKeywords' => strtolower((string) $item),
        ];
    })->values()->all();
@endphp

<div
    {{ $attributes->class(['tw:grid tw:gap-1.5 tw:relative tw:min-w-0']) }}
    x-data="searchableSelect({
        selectedValue: @js((string) ($resolvedValue ?? '')),
        options: @js($normalizedOptions),
        allowCustom: @js(filter_var($allowCustom, FILTER_VALIDATE_BOOLEAN)),
        suggestionsUrl: @js($suggestionsUrl),
        remoteSearch: @js(filter_var($remote, FILTER_VALIDATE_BOOLEAN)),
        disabled: @js((bool) $disabled)
    })"
    @click.outside="close(false)"
    @keydown.escape.stop="close(true)"
    @focusout="if ($event.relatedTarget && !$el.contains($event.relatedTarget)) close(false)"
    x-on:set-select-value.window="if ($event.detail?.name === '{{ $name }}' || $event.detail?.id === '{{ $resolvedId }}') { selectedValue = String($event.detail.value ?? ''); syncNativeSelect(selectedValue); }"
>
    @if($label)
        {{-- The native select is visually hidden; clicking the label moves focus to the combobox trigger. --}}
        <label id="{{ $resolvedId }}-label" for="{{ $resolvedId }}" class="{{ $labelClass }}" @mousedown.prevent @click.prevent="$refs.triggerButton?.focus()">
            {{ $label }}
            @if($required)<span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only"> {{ __('common.required') }}</span>@endif
        </label>
    @endif

    {{-- Native Hidden Select for Form Submission and Validation --}}
    <select
        id="{{ $resolvedId }}"
        name="{{ $name }}"
        x-ref="nativeSelect"
        @required($required)
        @disabled($disabled)
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if($message) aria-invalid="true" @endif
        x-on:invalid.capture="open = true; $nextTick(() => $refs.searchInput?.focus())"
        x-on:focus="if (!open) $refs.triggerButton?.focus()"
        tabindex="-1"
        style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0;"
    >
        <option value="">{{ $placeholder }}</option>
        @foreach($normalizedOptions as $opt)
            <option value="{{ $opt['value'] }}" @selected((string) $resolvedValue === (string) $opt['value'])>
                {{ $opt['label'] }}
            </option>
        @endforeach
    </select>

    {{-- Trigger Button --}}
    <div class="tw:relative tw:flex tw:items-center tw:min-w-0">
        <button
            type="button"
            id="{{ $resolvedId }}-trigger"
            x-ref="triggerButton"
            @if($label) aria-labelledby="{{ $resolvedId }}-label {{ $resolvedId }}-trigger" @endif
            :aria-describedby="hasAsyncError ? @js(trim(($describedBy ? $describedBy.' ' : '').$resolvedId.'-async-error')) : (@js($describedBy) || null)"
            :aria-invalid="({{ $message ? 'true' : 'false' }} || hasAsyncError) ? 'true' : null"
            @click="toggle()"
            @keydown.arrow-down.prevent="toggle()"
            @keydown.enter.prevent="toggle()"
            @disabled($disabled)
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-controls="'{{ $resolvedId }}-listbox'"
            class="ui-motion tw:flex tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:min-w-0 tw:items-center tw:justify-between tw:gap-2 tw:rounded-ui-sm tw:border tw:bg-surface tw:px-3 tw:py-2 tw:text-start tw:text-ui-sm tw:text-on-surface tw:shadow-none ui-focus-ring tw:disabled:cursor-not-allowed tw:disabled:bg-surface-container tw:disabled:opacity-50"
            :class="{
                'tw:border-error': {{ $message ? 'true' : 'false' }} || hasAsyncError,
                'tw:border-outline-strong': !({{ $message ? 'true' : 'false' }} || hasAsyncError) && !open,
                'tw:border-primary tw:ring-2 tw:ring-primary/20': open
            }"
        >
            <div class="tw:flex-1 tw:min-w-0 tw:truncate" :class="selectedOption && !@js((bool) $disabled) ? 'tw:pe-8' : ''">
                <template x-if="selectedOption">
                    @if($showSub)
                        <div class="tw:flex tw:flex-col tw:gap-0.5 tw:min-w-0">
                            <span class="ui-tabular-nums tw:font-medium tw:text-on-surface tw:truncate" :title="selectedOption.label" x-text="selectedOption.label"></span>
                            <template x-if="selectedOption.sublabel">
                                <span class="tw:text-ui-xs tw:text-on-surface-variant tw:truncate" x-text="selectedOption.sublabel"></span>
                            </template>
                        </div>
                    @else
                        <span class="ui-tabular-nums tw:font-medium tw:text-on-surface tw:truncate tw:block" :title="selectedOption.label" x-text="selectedOption.label"></span>
                    @endif
                </template>
                <template x-if="!selectedOption">
                    <span class="tw:text-on-surface-variant">{{ $placeholder }}</span>
                </template>
            </div>

            <x-ui.icon
                name="chevron-down"
                size="sm"
                class="tw:shrink-0 tw:text-on-surface-variant ui-motion"
                x-bind:class="{ 'tw:rotate-180': open }"
            />
        </button>

        <template x-if="selectedOption && !{{ $disabled ? 'true' : 'false' }}">
            <button
                type="button"
                @click.stop="clear(); $nextTick(() => $refs.triggerButton?.focus())"
                class="tw:absolute tw:end-8 tw:z-10 tw:inline-flex tw:size-6 tw:items-center tw:justify-center tw:rounded-full tw:border-0 tw:bg-transparent tw:p-0 tw:text-on-surface-variant tw:hover:text-error tw:hover:bg-surface-container tw:cursor-pointer ui-motion ui-focus-ring"
                title="{{ __('common.select.clear') }}"
                aria-label="{{ __('common.select.clear') }}"
            >
                <x-ui.icon name="x" size="sm" class="tw:w-3.5 tw:h-3.5" />
            </button>
        </template>
    </div>

    {{-- Dropdown Menu Popover --}}
    <div
        id="{{ $resolvedId }}-popover"
        x-show="open"
        x-cloak
        x-transition:enter="ui-motion tw:transition tw:ease-out tw:duration-150"
        x-transition:enter-start="tw:opacity-0 tw:translate-y-1"
        x-transition:enter-end="tw:opacity-100 tw:translate-y-0"
        x-transition:leave="ui-motion tw:transition tw:ease-in tw:duration-100"
        x-transition:leave-start="tw:opacity-100 tw:translate-y-0"
        x-transition:leave-end="tw:opacity-0 tw:translate-y-1"
        class="tw:absolute tw:top-full tw:left-0 tw:right-0 tw:mt-1.5 tw:z-dropdown tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:shadow-ui-2 tw:overflow-hidden"
        style="background-color: var(--ui-menu-bg);"
    >
        {{-- Search Input in Popover --}}
        <div class="tw:p-2 tw:border-b tw:border-outline-variant/60 tw:bg-surface-container-lowest">
            <label for="{{ $resolvedId }}-search" class="tw:sr-only">{{ $searchPlaceholder }}</label>
            <div style="position: relative; display: flex; align-items: center; width: 100%;">
                <span aria-hidden="true" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; pointer-events: none; display: flex; align-items: center; justify-content: center; z-index: 2;" class="tw:text-on-surface-variant">
                    <x-ui.icon name="search" size="sm" class="tw:w-4 tw:h-4" />
                </span>
                <input
                    id="{{ $resolvedId }}-search"
                    type="text"
                    x-ref="searchInput"
                    x-model="search"
                    data-ignore-dirty="true"
                    aria-controls="{{ $resolvedId }}-listbox"
                    :aria-activedescendant="highlightedIndex >= 0 ? (showCustomOption && highlightedIndex === filteredOptions.length ? '{{ $resolvedId }}-opt-custom' : '{{ $resolvedId }}-opt-' + highlightedIndex) : null"
                    @keydown.arrow-down.prevent="navigate(1)"
                    @keydown.arrow-up.prevent="navigate(-1)"
                    @keydown.enter.prevent="selectHighlighted()"
                    placeholder="{{ $searchPlaceholder }}"
                    autocomplete="off"
                    class="form-control form-control-sm ui-motion"
                    style="padding-left: 2.25rem !important; padding-right: 2.25rem !important; font-size: 0.8125rem; width: 100%; position: relative; z-index: 1;"
                >
                <button
                    type="button"
                    x-show="search.length > 0"
                    @mousedown.prevent
                    @click="search = ''; $refs.searchInput.focus()"
                    style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: transparent; border: 0; padding: 0; cursor: pointer; z-index: 2;"
                    class="tw:text-on-surface-variant tw:hover:text-on-surface tw:hover:bg-surface-container tw:rounded-full ui-motion ui-focus-ring"
                    aria-label="{{ __('common.select.clear_search') }}"
                >
                    <x-ui.icon name="x" size="sm" class="tw:w-3.5 tw:h-3.5" />
                </button>
            </div>
        </div>

        {{-- Options List --}}
        <div
            id="{{ $resolvedId }}-listbox"
            x-ref="optionsList"
            role="listbox"
            class="tw:max-h-60 tw:overflow-y-auto tw:p-1 tw:divide-y tw:divide-outline-variant/30"
        >
            <template x-for="(opt, idx) in filteredOptions" :key="opt.value">
                <div
                    :id="'{{ $resolvedId }}-opt-' + idx"
                    @mousedown.prevent
                    @click="select(opt)"
                    @mouseenter="highlightedIndex = idx"
                    role="option"
                    tabindex="-1"
                    :aria-selected="String(opt.value) === String(selectedValue)"
                    class="tw:flex tw:items-start tw:justify-between tw:gap-2 tw:p-2.5 tw:rounded-ui-sm tw:cursor-pointer ui-motion"
                    :class="{
                        'tw:bg-primary/10 tw:text-primary': idx === highlightedIndex,
                        'tw:bg-surface-container-low': String(opt.value) === String(selectedValue) && idx !== highlightedIndex,
                        'tw:hover:bg-surface-container': idx !== highlightedIndex
                    }"
                >
                    <div class="tw:flex tw:flex-col tw:gap-0.5 tw:min-w-0 tw:flex-1">
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-2 tw:gap-y-1 tw:min-w-0">
                            <span class="ui-tabular-nums tw:min-w-0 tw:max-w-full tw:text-ui-sm tw:font-semibold tw:text-on-surface tw:break-words" style="overflow-wrap: anywhere;" x-text="opt.label"></span>
                            <template x-if="opt.badge">
                                <span
                                    class="tw:shrink-0 tw:inline-flex tw:items-center tw:px-1.5 tw-py-0.2 tw:rounded-full tw:text-[10px] tw:font-bold tw:uppercase tw:tracking-wider"
                                    :class="{
                                        'tw:bg-emerald-100 tw:text-emerald-800': opt.badgeTone === 'success' || opt.badge === 'OPEN',
                                        'tw:bg-amber-100 tw:text-amber-800': opt.badgeTone === 'warning',
                                        'tw:bg-rose-100 tw:text-rose-800': opt.badgeTone === 'error',
                                        'tw:bg-slate-100 tw:text-slate-800': opt.badgeTone === 'neutral'
                                    }"
                                    x-text="opt.badge"
                                ></span>
                            </template>
                        </div>
                        <template x-if="opt.sublabel">
                            <span class="ui-tabular-nums tw:min-w-0 tw:text-ui-xs tw:text-on-surface-variant tw:break-words" style="overflow-wrap: anywhere;" x-text="opt.sublabel"></span>
                        </template>
                    </div>

                    <div class="tw:shrink-0 tw:mt-0.5" x-show="String(opt.value) === String(selectedValue)">
                        <x-ui.icon name="check" size="sm" class="tw:text-primary" />
                    </div>
                </div>
            </template>

            {{-- Creatable Custom Option --}}
            <template x-if="showCustomOption">
                <div
                    id="{{ $resolvedId }}-opt-custom"
                    @mousedown.prevent
                    @click="selectCustom(search.trim())"
                    @mouseenter="highlightedIndex = filteredOptions.length"
                    role="option"
                    tabindex="-1"
                    class="tw:flex tw:items-center tw:gap-2 tw:p-2.5 tw:rounded-ui-sm tw:cursor-pointer tw:border tw:border-dashed tw:border-primary/40 tw:bg-primary/5 tw:hover:bg-primary/10 ui-motion"
                    :class="{ 'tw:ring-1 tw:ring-primary tw:bg-primary/10': highlightedIndex === filteredOptions.length }"
                >
                    <x-ui.icon name="plus-circle" size="sm" class="tw:text-primary tw:shrink-0" />
                    <div class="tw:min-w-0 tw:flex-1 tw:text-ui-xs tw:break-words" style="overflow-wrap: anywhere;">
                        <span class="tw:text-on-surface-variant">{{ __('common.select.use_custom') }}: </span>
                        <span class="ui-tabular-nums tw:font-bold tw:text-primary" x-text="search.trim()"></span>
                    </div>
                </div>
            </template>

            {{-- Empty State --}}
            <div
                x-show="filteredOptions.length === 0 && !showCustomOption"
                x-cloak
                class="tw:py-6 tw:px-3 tw:text-center tw:text-on-surface-variant"
            >
                <x-ui.icon name="search-x" size="md" class="tw:mx-auto tw:mb-1 tw:opacity-50" />
                <p class="tw:text-ui-xs tw:m-0">{{ $emptyMessage }}</p>
            </div>
        </div>
    </div>

    @if($helper)<p id="{{ $helperId }}" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ $helper }}</p>@endif
    @if($message)
        <p id="{{ $errorId }}" class="tw:m-0 tw:flex tw:items-start tw:gap-1.5 tw:text-ui-xs tw:font-medium tw:text-error" role="alert">
            <x-ui.icon name="circle-alert" size="sm" class="tw:mt-0.5" /><span>{{ $message }}</span>
        </p>
    @endif
</div>
