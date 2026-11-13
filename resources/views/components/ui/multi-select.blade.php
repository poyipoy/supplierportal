@props([
    'name',
    'id' => null,
    'label' => null,
    'helper' => null,
    'error' => null,
    'value' => [],
    'options' => [],
    'placeholder' => __('common.select.placeholder'),
    'disabledPlaceholder' => __('common.select.choose_po'),
    'searchPlaceholder' => __('common.select.search_number'),
    'required' => false,
    'disabled' => false,
    'emptyMessage' => __('common.select.empty'),
    'eventName' => null,
])

@php
    $inputBaseName = rtrim($name, '[]');
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $inputBaseName);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $inputBaseName), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    
    // Normalisasi nilai awal
    $rawVal = old($validationKey, $value);
    if (!is_array($rawVal) && !($rawVal instanceof \Illuminate\Support\Collection)) {
        $rawVal = !empty($rawVal) ? [$rawVal] : [];
    }
    $resolvedValues = collect($rawVal)->map(fn ($v) => (string) $v)->values()->all();

    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $message ? $resolvedId . '-error' : null;
    $describedBy = collect([$helperId, $errorId])->filter()->implode(' ');

    // Normalisasi opsi awal
    $normalizedOptions = collect($options)->map(function ($item, $key) {
        if (is_array($item)) {
            $val = (string) ($item['value'] ?? $key);
            $lbl = (string) ($item['label'] ?? '');
            $desc = isset($item['description']) ? (string) $item['description'] : null;
            $sub = isset($item['sublabel']) ? (string) $item['sublabel'] : null;
            $amt = isset($item['amount']) ? (float) $item['amount'] : 0.0;
            $qty = isset($item['qty']) ? (float) $item['qty'] : null;
            $date = isset($item['date']) ? (string) $item['date'] : null;
            $dateFormatted = isset($item['dateFormatted']) ? (string) $item['dateFormatted'] : null;
            $keywords = (string) ($item['searchKeywords'] ?? ($lbl . ' ' . ($desc ?? '') . ' ' . ($sub ?? '') . ' ' . $amt));
            return [
                'value' => $val,
                'label' => $lbl,
                'description' => $desc,
                'sublabel' => $sub ?? ($date ? __('js.select.date', ['date' => $dateFormatted ?? $date]) : null),
                'amount' => $amt,
                'qty' => $qty,
                'uom' => $item['uom'] ?? null,
                'receipt_amount' => isset($item['receipt_amount']) && $item['receipt_amount'] !== null ? (float) $item['receipt_amount'] : null,
                'quantityLabel' => $item['quantityLabel'] ?? null,
                'date' => $date,
                'dateFormatted' => $dateFormatted,
                'searchKeywords' => strtolower($keywords),
            ];
        }
        return [
            'value' => (string) $key,
            'label' => (string) $item,
            'description' => null,
            'sublabel' => null,
            'amount' => 0.0,
            'qty' => null,
            'date' => null,
            'dateFormatted' => null,
            'searchKeywords' => strtolower((string) $item),
        ];
    })->values()->all();
@endphp

<div
    {{ $attributes->only('class')->class(['tw:grid tw:gap-1.5 tw:relative tw:min-w-0']) }}
    x-data="{
        open: false,
        search: '',
        selectedValues: @js($resolvedValues),
        options: @js($normalizedOptions),
        highlightedIndex: -1,
        isDisabled: @js((bool) $disabled),
        defaultPlaceholder: @js($placeholder),
        disabledPlaceholder: @js($disabledPlaceholder),

        init() {
            this.syncNativeElements();
            this.$watch('selectedValues', () => {
                this.syncNativeElements();
            });
            this.$watch('search', () => {
                this.highlightedIndex = -1;
            });
            this.$watch('isDisabled', (val) => {
                if (val) this.open = false;
            });
        },

        get filteredOptions() {
            if (!this.search.trim()) return this.options;
            const q = this.search.toLowerCase().trim();
            return this.options.filter(opt =>
                (opt.searchKeywords && opt.searchKeywords.includes(q)) ||
                opt.label.toLowerCase().includes(q) ||
                (opt.description && opt.description.toLowerCase().includes(q)) ||
                (opt.sublabel && opt.sublabel.toLowerCase().includes(q))
            );
        },

        get selectedOptions() {
            return this.options.filter(opt => this.selectedValues.includes(String(opt.value)));
        },

        get totalAmount() {
            return this.selectedOptions.reduce((acc, opt) => acc + (Number(opt.amount) || 0), 0);
        },

        formatRupiah(num) {
            return 'Rp ' + Number(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        get summaryDisplay() {
            if (this.isDisabled && !this.options.length) {
                return { count: 0, badgeText: '', text: this.disabledPlaceholder, totalFormatted: '' };
            }
            const count = this.selectedValues.length;
            if (count === 0) {
                return { count: 0, badgeText: '', text: this.defaultPlaceholder, totalFormatted: '' };
            }
            const totalStr = this.totalAmount > 0 ? this.formatRupiah(this.totalAmount) : '';
            if (count === 1) {
                const first = this.selectedOptions[0];
                const lbl = first ? first.label : '1 GR';
                return { count: 1, badgeText: '1 GR', text: lbl, totalFormatted: totalStr };
            }
            return { count: count, badgeText: `${count} GR`, text: @js(__('common.select.selected_state')), totalFormatted: totalStr };
        },

        isSelected(val) {
            return this.selectedValues.includes(String(val));
        },

        toggleOption(option) {
            if (this.isDisabled) return;
            const val = String(option.value);
            const idx = this.selectedValues.indexOf(val);
            if (idx === -1) {
                this.selectedValues.push(val);
            } else {
                this.selectedValues.splice(idx, 1);
            }
        },

        selectAll() {
            if (this.isDisabled || !this.options.length) return;
            const visibleVals = this.filteredOptions.map(o => String(o.value));
            visibleVals.forEach(v => {
                if (!this.selectedValues.includes(v)) {
                    this.selectedValues.push(v);
                }
            });
        },

        clearAll() {
            if (this.isDisabled) return;
            this.selectedValues = [];
        },

        toggle() {
            if (this.isDisabled) return;
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.highlightedIndex = -1;
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus();
                });
            }
        },

        close(focusTrigger = false) {
            this.open = false;
            this.highlightedIndex = -1;
            if (focusTrigger) {
                this.$nextTick(() => {
                    this.$refs.triggerButton?.focus();
                });
            }
        },

        navigate(step) {
            const count = this.filteredOptions.length;
            if (!count) return;
            this.highlightedIndex = (this.highlightedIndex + step + count) % count;
            this.scrollHighlightedIntoView();
        },

        navigateTo(index) {
            const count = this.filteredOptions.length;
            if (!count) return;
            this.highlightedIndex = Math.max(0, Math.min(index, count - 1));
            this.scrollHighlightedIntoView();
        },

        scrollHighlightedIntoView() {
            this.$nextTick(() => {
                const itemEl = this.$refs.optionsList?.querySelector?.(`[data-option-index='${this.highlightedIndex}']`)
                    || this.$refs.optionsList?.children?.[this.highlightedIndex];
                itemEl?.scrollIntoView?.({ block: 'nearest' });
            });
        },

        toggleHighlighted() {
            if (this.highlightedIndex >= 0 && this.highlightedIndex < this.filteredOptions.length) {
                this.toggleOption(this.filteredOptions[this.highlightedIndex]);
            } else if (this.filteredOptions.length === 1) {
                this.toggleOption(this.filteredOptions[0]);
            }
        },

        setOptions(newOptions, autoSelectAll = false, emptyPlaceholder = null) {
            this.options = (newOptions || []).map(opt => ({
                value: String(opt.id ?? opt.value),
                label: String(opt.number ?? opt.label ?? ''),
                description: opt.description ?? null,
                sublabel: opt.sublabel ?? (opt.date ? window.AdasiI18n.t('js.select.date', { date: opt.dateFormatted ?? opt.date }) : null),
                amount: parseFloat(opt.amount ?? 0),
                qty: opt.qty !== undefined ? parseFloat(opt.qty) : null,
                uom: opt.uom ?? null,
                receipt_amount: opt.receipt_amount !== undefined ? (opt.receipt_amount !== null ? parseFloat(opt.receipt_amount) : null) : null,
                quantityLabel: opt.quantityLabel ?? null,
                date: opt.date ?? null,
                dateFormatted: opt.dateFormatted ?? null,
                searchKeywords: (String(opt.number ?? opt.label ?? '') + ' ' + (opt.description ?? '') + ' ' + (opt.sublabel ?? '') + ' ' + (opt.amount ?? '') + ' ' + (opt.date ?? '')).toLowerCase(),
            }));

            this.isDisabled = this.options.length === 0;

            if (emptyPlaceholder) {
                this.disabledPlaceholder = emptyPlaceholder;
            }

            if (autoSelectAll && this.options.length > 0) {
                this.selectedValues = this.options.map(o => String(o.value));
            } else {
                // Pertahankan pilihan yang masih valid dalam opsi baru
                const validIds = this.options.map(o => String(o.value));
                this.selectedValues = this.selectedValues.filter(v => validIds.includes(v));
            }
            this.syncNativeElements();
        },

        syncNativeElements() {
            const selectEl = this.$refs.nativeMultiSelect;
            if (selectEl) {
                selectEl.innerHTML = '';
                this.options.forEach(opt => {
                    const optEl = document.createElement('option');
                    optEl.value = String(opt.value);
                    optEl.textContent = String(opt.label);
                    const isSelected = this.selectedValues.includes(String(opt.value));
                    optEl.selected = isSelected;
                    if (isSelected) {
                        optEl.setAttribute('selected', 'selected');
                    }
                    selectEl.appendChild(optEl);
                });
                selectEl.dispatchEvent(new CustomEvent('multi-select-change', {
                    bubbles: true,
                    detail: {
                        values: this.selectedValues,
                        total: this.totalAmount,
                        options: this.selectedOptions
                    }
                }));
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }"
    @if($eventName)
        x-on:{{ $eventName }}.window="setOptions($event.detail.options, $event.detail.autoSelect, $event.detail.emptyPlaceholder)"
    @endif
    x-on:set-multi-options.window="if ($event.detail.id === '{{ $resolvedId }}') setOptions($event.detail.options, $event.detail.autoSelect, $event.detail.emptyPlaceholder)"
    @click.outside="close(false)"
    @keydown.escape.stop="close(true)"
    @focusout="if ($event.relatedTarget && !$el.contains($event.relatedTarget)) close(false)"
>
    @if($label)
        <label id="{{ $resolvedId }}-label" for="{{ $resolvedId }}" class="tw:text-ui-sm tw:font-medium tw:text-on-surface" @mousedown.prevent @click.prevent="$refs.triggerButton?.focus()">
            {{ $label }}
            @if($required)<span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only"> {{ __('common.required') }}</span>@endif
        </label>
    @endif

    {{-- Native hidden multi-select for robust Laravel Form Submission & Validation --}}
    <select
        id="{{ $resolvedId }}"
        name="{{ $name }}"
        multiple
        x-ref="nativeMultiSelect"
        @required($required)
        x-bind:disabled="isDisabled"
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if($message) aria-invalid="true" @endif
        x-on:invalid.prevent="if (!isDisabled) { open = true; $nextTick(() => $refs.triggerButton?.focus()); }"
        x-on:focus="if (!open) $refs.triggerButton?.focus()"
        tabindex="-1"
        style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0;"
    >
        @foreach($normalizedOptions as $opt)
            <option value="{{ $opt['value'] }}" @selected(in_array((string)$opt['value'], $resolvedValues, true))>
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
            :aria-describedby="@js($describedBy) || null"
            :aria-invalid="({{ $message ? 'true' : 'false' }}) ? 'true' : null"
            @click="toggle()"
            @keydown.arrow-down.prevent="open ? navigate(1) : toggle()"
            @keydown.enter.prevent="toggle()"
            @keydown.space.prevent="toggle()"
            x-bind:disabled="isDisabled"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            aria-controls="{{ $resolvedId }}-listbox"
            class="ui-motion tw:flex tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:min-w-0 tw:items-center tw:justify-between tw:gap-2 tw:rounded-ui-sm tw:border tw:bg-surface tw:px-3 tw:py-2 tw:text-start tw:text-ui-sm tw:text-on-surface tw:shadow-none ui-focus-ring tw:disabled:cursor-not-allowed tw:disabled:bg-surface-container tw:disabled:opacity-60"
            :class="{
                'tw:border-error': {{ $message ? 'true' : 'false' }},
                'tw:border-outline-strong': !{{ $message ? 'true' : 'false' }} && !open,
                'tw:border-primary tw:ring-2 tw:ring-primary/20': open
            }"
        >
            <div class="tw:flex-1 tw:min-w-0" :class="selectedValues.length > 0 && !isDisabled ? 'tw:pe-8' : ''">
                <template x-if="summaryDisplay.count > 0">
                    <div class="tw:flex tw:min-w-0 tw:flex-wrap tw:items-center tw:gap-x-2 tw:gap-y-0.5">
                        <span
                            class="ui-tabular-nums tw:inline-flex tw:shrink-0 tw:items-center tw:px-2 tw:py-0.5 tw:rounded-full tw:text-ui-xs tw:font-bold tw:bg-primary/10 tw:text-primary"
                            x-text="summaryDisplay.badgeText"
                        ></span>
                        <span class="ui-tabular-nums tw:min-w-0 tw:max-w-full tw:font-medium tw:text-on-surface tw:break-words" style="overflow-wrap: anywhere;" x-text="summaryDisplay.text"></span>
                        <template x-if="summaryDisplay.totalFormatted">
                            <span class="ui-tabular-nums tw:min-w-0 tw:max-w-full tw:font-semibold tw:text-primary tw:break-words" style="overflow-wrap: anywhere;" x-text="'· ' + summaryDisplay.totalFormatted"></span>
                        </template>
                    </div>
                </template>
                <template x-if="summaryDisplay.count === 0">
                    <span class="tw:block tw:truncate tw:text-on-surface-variant" x-text="summaryDisplay.text"></span>
                </template>
            </div>

            <x-ui.icon
                name="chevron-down"
                size="sm"
                class="tw:shrink-0 tw:text-on-surface-variant ui-motion"
                x-bind:class="{ 'tw:rotate-180': open }"
            />
        </button>

        <template x-if="selectedValues.length > 0 && !isDisabled">
            <button
                type="button"
                @click.stop="clearAll(); $nextTick(() => $refs.triggerButton?.focus())"
                class="tw:absolute tw:end-8 tw:z-10 tw:inline-flex tw:size-6 tw:items-center tw:justify-center tw:rounded-full tw:border-0 tw:bg-transparent tw:p-0 tw:text-on-surface-variant tw:hover:text-error tw:hover:bg-surface-container tw:cursor-pointer ui-motion ui-focus-ring"
                title="{{ __('common.select.clear_gr') }}"
                aria-label="{{ __('common.select.clear_all') }}"
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
        <span class="tw:sr-only" role="status" aria-live="polite" x-text="open ? (@js(__('common.select.selected')) + ' ' + selectedValues.length + ' ' + @js(__('common.select.of')) + ' ' + filteredOptions.length) : ''"></span>

        {{-- Search Input in Popover --}}
        <div class="tw:p-2 tw:border-b tw:border-outline-variant/60 tw:bg-surface-container-lowest">
            <label for="{{ $resolvedId }}-search" class="tw:sr-only">{{ $searchPlaceholder }}</label>
            <div style="position: relative; display: flex; align-items: center; width: 100%;">
                <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; pointer-events: none; display: flex; align-items: center; justify-content: center; z-index: 2;" class="tw:text-on-surface-variant" aria-hidden="true">
                    <x-ui.icon name="search" size="sm" class="tw:w-4 tw:h-4" />
                </span>
                <input
                    id="{{ $resolvedId }}-search"
                    type="text"
                    x-ref="searchInput"
                    x-model="search"
                    data-ignore-dirty="true"
                    aria-controls="{{ $resolvedId }}-listbox"
                    :aria-activedescendant="highlightedIndex >= 0 && filteredOptions[highlightedIndex] ? '{{ $resolvedId }}-opt-' + highlightedIndex : null"
                    @keydown.arrow-down.prevent="navigate(1)"
                    @keydown.arrow-up.prevent="navigate(-1)"
                    @keydown.home.prevent="navigateTo(0)"
                    @keydown.end.prevent="navigateTo(filteredOptions.length - 1)"
                    @keydown.enter.prevent="toggleHighlighted()"
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

        {{-- Header Shortcut Actions --}}
        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:px-3 tw:py-2 tw:bg-surface-container-low tw:border-b tw:border-outline-variant/40 tw:text-ui-xs">
            <span class="tw:text-on-surface-variant">
                {{ __('common.select.selected') }} <strong class="ui-tabular-nums tw:text-on-surface" x-text="selectedValues.length"></strong> {{ __('common.select.of') }} <span class="ui-tabular-nums" x-text="options.length"></span>
            </span>
            <div class="tw:flex tw:items-center tw:gap-1.5">
                <button
                    type="button"
                    @mousedown.prevent
                    @click="selectAll()"
                    class="tw:min-h-6 tw:px-1.5 tw:inline-flex tw:items-center tw:rounded-ui-xs tw:text-primary tw:hover:underline tw:font-medium tw:bg-transparent tw:border-0 tw:cursor-pointer ui-focus-ring"
                >
                    {{ __('common.actions.all') }}
                </button>
                <span class="tw:text-on-surface-variant" aria-hidden="true">·</span>
                <button
                    type="button"
                    @mousedown.prevent
                    @click="clearAll()"
                    class="tw:min-h-6 tw:px-1.5 tw:inline-flex tw:items-center tw:rounded-ui-xs tw:text-on-surface-variant tw:hover:text-error tw:hover:underline tw:font-medium tw:bg-transparent tw:border-0 tw:cursor-pointer ui-focus-ring"
                >
                    {{ __('common.actions.cancel') }}
                </button>
            </div>
        </div>

        {{-- Options List with Checkboxes --}}
        <div
            id="{{ $resolvedId }}-listbox"
            x-ref="optionsList"
            role="listbox"
            aria-multiselectable="true"
            @if($label) aria-labelledby="{{ $resolvedId }}-label" @endif
            class="tw:max-h-60 tw:overflow-y-auto tw:p-1 tw:divide-y tw:divide-outline-variant/30"
        >
            <template x-for="(opt, idx) in filteredOptions" :key="opt.value">
                <div
                    :id="'{{ $resolvedId }}-opt-' + idx"
                    :data-option-index="idx"
                    @mousedown.prevent
                    @click="toggleOption(opt)"
                    @mouseenter="highlightedIndex = idx"
                    role="option"
                    tabindex="-1"
                    :aria-selected="isSelected(opt.value)"
                    class="tw:flex tw:items-start tw:gap-3 tw:p-2.5 tw:rounded-ui-sm tw:cursor-pointer ui-motion"
                    :class="{
                        'tw:bg-primary/10': idx === highlightedIndex && !isSelected(opt.value),
                        'tw:bg-primary/15': idx === highlightedIndex && isSelected(opt.value),
                        'tw:bg-surface-container-low': isSelected(opt.value) && idx !== highlightedIndex,
                        'tw:hover:bg-surface-container': !isSelected(opt.value) && idx !== highlightedIndex
                    }"
                >
                    {{-- Custom Checkbox UI --}}
                    <div
                        class="tw:mt-0.5 tw:w-4 tw:h-4 tw:rounded-ui-xs tw:border tw:flex tw:items-center tw:justify-center tw:shrink-0 ui-motion"
                        :class="{
                            'tw:bg-primary tw:border-primary tw:text-on-primary': isSelected(opt.value),
                            'tw:border-outline-strong tw:bg-surface': !isSelected(opt.value)
                        }"
                        aria-hidden="true"
                    >
                        <template x-if="isSelected(opt.value)">
                            <x-ui.icon name="check" size="xs" class="tw:w-3 tw:h-3 tw:stroke-[3]" />
                        </template>
                    </div>

                    <div class="tw:flex-1 tw:min-w-0 tw:grid tw:gap-1">
                        <div class="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-x-3 tw:gap-y-0.5 tw:min-w-0">
                            <span class="ui-tabular-nums tw:min-w-0 tw:max-w-full tw:text-ui-sm tw:font-semibold tw:text-on-surface tw:break-words" style="overflow-wrap: anywhere;" x-text="opt.label"></span>
                            <template x-if="opt.amount !== undefined && Number(opt.amount) > 0">
                                <span class="ui-tabular-nums tw:ms-auto tw:max-w-full tw:text-end tw:text-ui-sm tw:font-semibold tw:text-primary tw:break-words" style="overflow-wrap: anywhere;" x-text="formatRupiah(opt.amount)"></span>
                            </template>
                        </div>

                        <template x-if="opt.description">
                            <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant tw:line-clamp-2 tw:break-words" style="overflow-wrap: anywhere;" :title="opt.description">
                                <span class="tw:font-semibold tw:text-on-surface">{{ __('common.fields.description') }}</span>
                                <span x-text="' ' + opt.description"></span>
                            </p>
                        </template>

                        <template x-if="(opt.quantityLabel !== undefined && opt.quantityLabel !== null) || opt.sublabel">
                            <div class="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-4 tw:gap-y-0.5 tw:text-ui-xs tw:text-on-surface-variant tw:min-w-0">
                                <template x-if="opt.quantityLabel !== undefined && opt.quantityLabel !== null">
                                    <span class="tw:inline-flex tw:flex-wrap tw:items-baseline tw:gap-x-1 tw:min-w-0">
                                        <span>{{ __('common.fields.qty') }}:</span>
                                        <strong class="ui-tabular-nums tw:font-semibold tw:text-on-surface tw:break-words" style="overflow-wrap: anywhere;" x-text="opt.quantityLabel"></strong>
                                        <span class="tw:text-on-surface" x-text="opt.uom || '—'"></span>
                                    </span>
                                </template>
                                <template x-if="opt.sublabel">
                                    <span class="ui-tabular-nums tw:min-w-0 tw:break-words" style="overflow-wrap: anywhere;" x-text="opt.sublabel"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Empty State --}}
            <div
                x-show="filteredOptions.length === 0"
                x-cloak
                class="tw:py-6 tw:px-3 tw:text-center tw:text-on-surface-variant"
            >
                <x-ui.icon name="search-x" size="md" class="tw:mx-auto tw:mb-1 tw:opacity-50" />
                <p class="tw:text-ui-xs tw:m-0">{{ $emptyMessage }}</p>
            </div>
        </div>

        {{-- Footer with Total Nominal & Done Button --}}
        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:p-2.5 tw:bg-surface-container-lowest tw:border-t tw:border-outline-variant/60">
            <div class="tw:min-w-0 tw:flex-1 tw:text-ui-xs tw:text-on-surface">
                <template x-if="totalAmount > 0">
                    <div class="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-1">
                        <span class="tw:text-on-surface-variant">{{ __('common.fields.total') }}</span>
                        <strong class="ui-tabular-nums tw:min-w-0 tw:text-primary tw:break-words" style="overflow-wrap: anywhere;" x-text="formatRupiah(totalAmount)"></strong>
                    </div>
                </template>
                <template x-if="!totalAmount || totalAmount === 0">
                    <span class="ui-tabular-nums tw:text-on-surface-variant" x-text="@js(__('common.select.gr_selected')).replace(':count', selectedValues.length)"></span>
                </template>
            </div>
            <button
                type="button"
                @click="close(true)"
                class="btn btn-sm btn-primary tw:shrink-0 tw:min-h-8 tw:py-1 tw:px-3 tw:text-ui-xs tw:font-medium ui-focus-ring"
            >
                <x-ui.icon name="check" size="sm" class="tw:me-1" /> {{ __('common.review.done') }}
            </button>
        </div>
    </div>

    @if($helper)<p id="{{ $helperId }}" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ $helper }}</p>@endif
    @if($message)
        <p id="{{ $errorId }}" class="tw:m-0 tw:flex tw:items-start tw:gap-1.5 tw:text-ui-xs tw:font-medium tw:text-error" role="alert">
            <x-ui.icon name="circle-alert" size="sm" class="tw:mt-0.5" /><span>{{ $message }}</span>
        </p>
    @endif
</div>
