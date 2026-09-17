@props([
    'name',
    'id' => null,
    'label' => null,
    'helper' => null,
    'error' => null,
    'value' => [],
    'options' => [],
    'placeholder' => 'Pilih opsi...',
    'disabledPlaceholder' => 'Pilih PO terlebih dahulu...',
    'searchPlaceholder' => 'Ketik nomor atau nominal...',
    'required' => false,
    'disabled' => false,
    'emptyMessage' => 'Tidak ada data yang cocok',
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
            $sub = isset($item['sublabel']) ? (string) $item['sublabel'] : null;
            $amt = isset($item['amount']) ? (float) $item['amount'] : 0.0;
            $date = isset($item['date']) ? (string) $item['date'] : null;
            $keywords = (string) ($item['searchKeywords'] ?? ($lbl . ' ' . ($sub ?? '') . ' ' . $amt));
            return [
                'value' => $val,
                'label' => $lbl,
                'sublabel' => $sub,
                'amount' => $amt,
                'date' => $date,
                'searchKeywords' => strtolower($keywords),
            ];
        }
        return [
            'value' => (string) $key,
            'label' => (string) $item,
            'sublabel' => null,
            'amount' => 0.0,
            'date' => null,
            'searchKeywords' => strtolower((string) $item),
        ];
    })->values()->all();
@endphp

<div
    {{ $attributes->only('class')->class(['tw-grid tw-gap-1.5 tw-relative']) }}
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
            const totalStr = this.formatRupiah(this.totalAmount);
            if (count === 1) {
                const first = this.selectedOptions[0];
                const lbl = first ? first.label : '1 GR';
                return { count: 1, badgeText: '1 GR', text: lbl, totalFormatted: totalStr };
            }
            return { count: count, badgeText: `${count} GR`, text: 'Terpilih', totalFormatted: totalStr };
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

        close() {
            this.open = false;
            this.highlightedIndex = -1;
        },

        setOptions(newOptions, autoSelectAll = false, emptyPlaceholder = null) {
            this.options = (newOptions || []).map(opt => ({
                value: String(opt.id ?? opt.value),
                label: String(opt.number ?? opt.label ?? ''),
                sublabel: opt.date ? ('Tanggal: ' + opt.date) : (opt.sublabel ?? null),
                amount: parseFloat(opt.amount ?? 0),
                date: opt.date ?? null,
                searchKeywords: (String(opt.number ?? opt.label ?? '') + ' ' + (opt.amount ?? '') + ' ' + (opt.date ?? '')).toLowerCase(),
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
    @click.outside="close()"
    @keydown.escape.stop="close()"
>
    @if($label)
        <label for="{{ $resolvedId }}" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
            {{ $label }}
            @if($required)<span class="tw-text-error" aria-hidden="true">*</span><span class="tw-sr-only"> required</span>@endif
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
        x-on:invalid.prevent="if (!isDisabled) { open = true; $refs.triggerButton?.focus(); }"
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
    <button
        type="button"
        x-ref="triggerButton"
        @click="toggle()"
        @keydown.arrow-down.prevent="toggle()"
        @keydown.enter.prevent="toggle()"
        x-bind:disabled="isDisabled"
        aria-haspopup="listbox"
        :aria-expanded="open"
        class="ui-motion tw-flex tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-items-center tw-justify-between tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-py-2 tw-text-start tw-text-ui-sm tw-text-on-surface tw-shadow-none ui-focus-ring disabled:tw-cursor-not-allowed disabled:tw-bg-surface-container disabled:tw-opacity-60"
        :class="{
            'tw-border-error': {{ $message ? 'true' : 'false' }},
            'tw-border-outline-strong': !{{ $message ? 'true' : 'false' }} && !open,
            'tw-border-primary tw-ring-2 tw-ring-primary/20': open
        }"
    >
        <div class="tw-flex-1 tw-min-w-0 tw-pe-2 tw-flex tw-items-center tw-gap-2">
            <template x-if="summaryDisplay.count > 0">
                <div class="tw-flex tw-items-center tw-gap-2 tw-flex-wrap">
                    <span
                        class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-bold tw-bg-primary/10 tw-text-primary"
                        x-text="summaryDisplay.badgeText"
                    ></span>
                    <span class="tw-font-medium tw-text-on-surface tw-truncate" x-text="summaryDisplay.text"></span>
                    <template x-if="summaryDisplay.totalFormatted">
                        <span class="tw-font-mono tw-font-semibold tw-text-primary" x-text="'· ' + summaryDisplay.totalFormatted"></span>
                    </template>
                </div>
            </template>
            <template x-if="summaryDisplay.count === 0">
                <span class="tw-text-on-surface-variant" x-text="summaryDisplay.text"></span>
            </template>
        </div>

        <div class="tw-flex tw-items-center tw-gap-1.5 tw-shrink-0">
            <template x-if="selectedValues.length > 0 && !isDisabled">
                <span
                    @click.stop="clearAll()"
                    class="tw-p-1 tw-rounded-full tw-text-on-surface-variant hover:tw-text-error hover:tw-bg-surface-container tw-cursor-pointer ui-motion"
                    title="Hapus semua pilihan GR"
                    aria-label="Hapus semua pilihan"
                >
                    <x-ui.icon name="x" size="sm" class="tw-w-3.5 tw-h-3.5" />
                </span>
            </template>
            <x-ui.icon
                name="chevron-down"
                size="sm"
                class="tw-text-on-surface-variant ui-motion"
                x-bind:class="{ 'tw-rotate-180': open }"
            />
        </div>
    </button>

    {{-- Dropdown Menu Popover --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="ui-motion tw-transition tw-ease-out tw-duration-150"
        x-transition:enter-start="tw-opacity-0 tw-translate-y-1"
        x-transition:enter-end="tw-opacity-100 tw-translate-y-0"
        x-transition:leave="ui-motion tw-transition tw-ease-in tw-duration-100"
        x-transition:leave-start="tw-opacity-100 tw-translate-y-0"
        x-transition:leave-end="tw-opacity-0 tw-translate-y-1"
        class="tw-absolute tw-top-full tw-left-0 tw-right-0 tw-mt-1.5 tw-z-50 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-shadow-lg tw-overflow-hidden"
        role="listbox"
        aria-multiselectable="true"
    >
        {{-- Search Input in Popover --}}
        <div class="tw-p-2 tw-border-b tw-border-outline-variant/60 tw-bg-surface-container-lowest">
            <div style="position: relative; display: flex; align-items: center; width: 100%;">
                <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; pointer-events: none; display: flex; align-items: center; justify-content: center; z-index: 2;" class="tw-text-on-surface-variant">
                    <x-ui.icon name="search" size="sm" class="tw-w-4 tw-h-4" />
                </span>
                <input
                    type="text"
                    x-ref="searchInput"
                    x-model="search"
                    placeholder="{{ $searchPlaceholder }}"
                    autocomplete="off"
                    class="form-control form-control-sm ui-motion"
                    style="padding-left: 2.25rem !important; padding-right: 2.25rem !important; font-size: 0.8125rem; width: 100%; position: relative; z-index: 1;"
                >
                <button
                    type="button"
                    x-show="search.length > 0"
                    @click="search = ''; $refs.searchInput.focus()"
                    style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; background: transparent; border: 0; padding: 0; cursor: pointer; z-index: 2;"
                    class="tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface-container tw-rounded-full ui-motion"
                    aria-label="Bersihkan pencarian"
                >
                    <x-ui.icon name="x" size="sm" class="tw-w-3.5 tw-h-3.5" />
                </button>
            </div>
        </div>

        {{-- Header Shortcut Actions --}}
        <div class="tw-flex tw-items-center tw-justify-between tw-px-3 tw-py-2 tw-bg-surface-container-low tw-border-b tw-border-outline-variant/40 tw-text-ui-xs">
            <span class="tw-text-on-surface-variant">
                Terpilih: <strong class="tw-text-on-surface" x-text="selectedValues.length"></strong> dari <span x-text="options.length"></span>
            </span>
            <div class="tw-flex tw-items-center tw-gap-2">
                <button
                    type="button"
                    @click="selectAll()"
                    class="tw-text-primary hover:tw-underline tw-font-medium tw-bg-transparent tw-border-0 tw-p-0"
                >
                    Pilih Semua
                </button>
                <span class="tw-text-outline-variant">·</span>
                <button
                    type="button"
                    @click="clearAll()"
                    class="tw-text-on-surface-variant hover:tw-text-error hover:tw-underline tw-font-medium tw-bg-transparent tw-border-0 tw-p-0"
                >
                    Batal Semua
                </button>
            </div>
        </div>

        {{-- Options List with Checkboxes --}}
        <div
            x-ref="optionsList"
            class="tw-max-h-60 tw-overflow-y-auto tw-p-1 tw-divide-y tw-divide-outline-variant/30"
        >
            <template x-for="(opt, idx) in filteredOptions" :key="opt.value">
                <div
                    @click="toggleOption(opt)"
                    role="option"
                    :aria-selected="isSelected(opt.value)"
                    class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-cursor-pointer ui-motion"
                    :class="{
                        'tw-bg-surface-container-low': isSelected(opt.value),
                        'hover:tw-bg-surface-container': !isSelected(opt.value)
                    }"
                >
                    <div class="tw-flex tw-items-center tw-gap-3 tw-min-w-0 tw-flex-1 tw-pe-2">
                        {{-- Custom Checkbox UI --}}
                        <div
                            class="tw-w-4 tw-h-4 tw-rounded tw-border tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion"
                            :class="{
                                'tw-bg-primary tw-border-primary tw-text-white': isSelected(opt.value),
                                'tw-border-outline-strong tw-bg-surface': !isSelected(opt.value)
                            }"
                        >
                            <template x-if="isSelected(opt.value)">
                                <x-ui.icon name="check" size="xs" class="tw-w-3 tw-h-3 tw-stroke-[3]" />
                            </template>
                        </div>

                        <div class="tw-flex tw-flex-col tw-gap-0.5 tw-min-w-0">
                            <span class="tw-font-mono tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-truncate" x-text="opt.label"></span>
                            <template x-if="opt.sublabel">
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-truncate" x-text="opt.sublabel"></span>
                            </template>
                        </div>
                    </div>

                    <div class="tw-shrink-0 tw-text-end">
                        <span class="tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-primary" x-text="formatRupiah(opt.amount)"></span>
                    </div>
                </div>
            </template>

            {{-- Empty State --}}
            <div
                x-show="filteredOptions.length === 0"
                x-cloak
                class="tw-py-6 tw-px-3 tw-text-center tw-text-on-surface-variant"
            >
                <x-ui.icon name="search-x" size="md" class="tw-mx-auto tw-mb-1 tw-opacity-50" />
                <p class="tw-text-ui-xs tw-m-0">{{ $emptyMessage }}</p>
            </div>
        </div>

        {{-- Footer with Total Nominal & Done Button --}}
        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-bg-surface-container-lowest tw-border-t tw-border-outline-variant/60">
            <div class="tw-text-ui-xs tw-text-on-surface">
                <span class="tw-text-on-surface-variant">Total: </span>
                <strong class="tw-font-mono tw-text-primary" x-text="formatRupiah(totalAmount)"></strong>
            </div>
            <button
                type="button"
                @click="close()"
                class="btn btn-sm btn-primary tw-py-1 tw-px-3 tw-text-ui-xs tw-font-medium"
            >
                <x-ui.icon name="check" size="xs" class="tw-me-1" /> Selesai
            </button>
        </div>
    </div>

    @if($helper)<p id="{{ $helperId }}" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $helper }}</p>@endif
    @if($message)
        <p id="{{ $errorId }}" class="tw-m-0 tw-flex tw-items-start tw-gap-1.5 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">
            <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5" /><span>{{ $message }}</span>
        </p>
    @endif
</div>
