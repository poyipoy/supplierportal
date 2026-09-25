@props([
    'name',
    'id' => null,
    'label' => null,
    'helper' => null,
    'error' => null,
    'value' => null,
    'options' => [],
    'placeholder' => 'Pilih opsi...',
    'searchPlaceholder' => 'Ketik untuk mencari...',
    'required' => false,
    'disabled' => false,
    'emptyMessage' => 'Tidak ada data yang cocok',
    'showSublabelOnTrigger' => true,
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
    {{ $attributes->only('class')->class(['tw-grid tw-gap-1.5 tw-relative']) }}
    x-data="{
        open: false,
        search: '',
        selectedValue: @js((string) ($resolvedValue ?? '')),
        options: @js($normalizedOptions),
        highlightedIndex: -1,

        init() {
            this.$watch('selectedValue', (val) => {
                this.syncNativeSelect(val);
            });
            if (this.selectedValue) {
                this.syncNativeSelect(this.selectedValue);
            }
        },

        get filteredOptions() {
            if (!this.search.trim()) return this.options;
            const q = this.search.toLowerCase().trim();
            return this.options.filter(opt =>
                opt.searchKeywords.includes(q) ||
                opt.label.toLowerCase().includes(q) ||
                (opt.sublabel && opt.sublabel.toLowerCase().includes(q))
            );
        },

        get selectedOption() {
            return this.options.find(opt => String(opt.value) === String(this.selectedValue)) || null;
        },

        select(option) {
            this.selectedValue = option ? String(option.value) : '';
            this.syncNativeSelect(this.selectedValue);
            this.open = false;
            this.search = '';
            this.highlightedIndex = -1;
            this.$nextTick(() => {
                this.$refs.triggerButton?.focus();
            });
        },

        clear() {
            this.select(null);
        },

        toggle() {
            if ({{ $disabled ? 'true' : 'false' }}) return;
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

        syncNativeSelect(val) {
            const selectEl = this.$refs.nativeSelect;
            if (selectEl) {
                if (selectEl.value !== val) {
                    selectEl.value = val;
                }
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },

        navigate(step) {
            const count = this.filteredOptions.length;
            if (!count) return;
            this.highlightedIndex = (this.highlightedIndex + step + count) % count;
            this.$nextTick(() => {
                const listEl = this.$refs.optionsList;
                const itemEl = listEl?.children[this.highlightedIndex];
                itemEl?.scrollIntoView({ block: 'nearest' });
            });
        },

        selectHighlighted() {
            if (this.highlightedIndex >= 0 && this.highlightedIndex < this.filteredOptions.length) {
                this.select(this.filteredOptions[this.highlightedIndex]);
            } else if (this.filteredOptions.length === 1) {
                this.select(this.filteredOptions[0]);
            }
        }
    }"
    @click.outside="close()"
    @keydown.escape.stop="close()"
>
    @if($label)
        <label for="{{ $resolvedId }}" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
            {{ $label }}
            @if($required)<span class="tw-text-error" aria-hidden="true">*</span><span class="tw-sr-only"> required</span>@endif
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
        x-on:invalid.capture="open = true"
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
    <button
        type="button"
        x-ref="triggerButton"
        @click="toggle()"
        @keydown.arrow-down.prevent="toggle()"
        @keydown.enter.prevent="toggle()"
        @disabled($disabled)
        aria-haspopup="listbox"
        :aria-expanded="open"
        class="ui-motion tw-flex tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-items-center tw-justify-between tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-py-2 tw-text-start tw-text-ui-sm tw-text-on-surface tw-shadow-none ui-focus-ring disabled:tw-cursor-not-allowed disabled:tw-bg-surface-container disabled:tw-opacity-50"
        :class="{
            'tw-border-error': {{ $message ? 'true' : 'false' }},
            'tw-border-outline-strong': !{{ $message ? 'true' : 'false' }} && !open,
            'tw-border-primary tw-ring-2 tw-ring-primary/20': open
        }"
    >
        <div class="tw-flex-1 tw-min-w-0 tw-truncate tw-pe-2">
            <template x-if="selectedOption">
                @if($showSub)
                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                        <span class="tw-font-medium tw-text-on-surface tw-truncate" x-text="selectedOption.label"></span>
                        <template x-if="selectedOption.sublabel">
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-truncate" x-text="selectedOption.sublabel"></span>
                        </template>
                    </div>
                @else
                    <span class="tw-font-medium tw-text-on-surface tw-truncate tw-block" x-text="selectedOption.label"></span>
                @endif
            </template>
            <template x-if="!selectedOption">
                <span class="tw-text-on-surface-variant">{{ $placeholder }}</span>
            </template>
        </div>

        <div class="tw-flex tw-items-center tw-gap-1 tw-shrink-0">
            <template x-if="selectedOption && !{{ $disabled ? 'true' : 'false' }}">
                <span
                    @click.stop="clear()"
                    class="tw-p-1 tw-rounded-full tw-text-on-surface-variant hover:tw-text-error hover:tw-bg-surface-container tw-cursor-pointer ui-motion"
                    title="Hapus pilihan"
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
                    @click="search = ''; $refs.searchInput.focus()"
                    style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; background: transparent; border: 0; padding: 0; cursor: pointer; z-index: 2;"
                    class="tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface-container tw-rounded-full ui-motion"
                    aria-label="Bersihkan pencarian"
                >
                    <x-ui.icon name="x" size="sm" class="tw-w-3.5 tw-h-3.5" />
                </button>
            </div>
        </div>

        {{-- Options List --}}
        <div
            x-ref="optionsList"
            class="tw-max-h-60 tw-overflow-y-auto tw-p-1 tw-divide-y tw-divide-outline-variant/30"
        >
            <template x-for="(opt, idx) in filteredOptions" :key="opt.value">
                <div
                    @click="select(opt)"
                    @mouseenter="highlightedIndex = idx"
                    role="option"
                    :aria-selected="String(opt.value) === String(selectedValue)"
                    class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-cursor-pointer ui-motion"
                    :class="{
                        'tw-bg-primary/10 tw-text-primary': idx === highlightedIndex,
                        'tw-bg-surface-container-low': String(opt.value) === String(selectedValue) && idx !== highlightedIndex,
                        'hover:tw-bg-surface-container': idx !== highlightedIndex
                    }"
                >
                    <div class="tw-flex tw-flex-col tw-gap-0.5 tw-min-w-0 tw-flex-1 tw-pe-2">
                        <div class="tw-flex tw-items-center tw-gap-2">
                            <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-truncate" x-text="opt.label"></span>
                            <template x-if="opt.badge">
                                <span
                                    class="tw-inline-flex tw-items-center tw-px-1.5 tw-py-0.2 tw-rounded-full tw-text-[10px] tw-font-bold tw-uppercase tw-tracking-wider"
                                    :class="{
                                        'tw-bg-emerald-100 tw-text-emerald-800': opt.badgeTone === 'success' || opt.badge === 'OPEN',
                                        'tw-bg-amber-100 tw-text-amber-800': opt.badgeTone === 'warning',
                                        'tw-bg-rose-100 tw-text-rose-800': opt.badgeTone === 'error',
                                        'tw-bg-slate-100 tw-text-slate-800': opt.badgeTone === 'neutral'
                                    }"
                                    x-text="opt.badge"
                                ></span>
                            </template>
                        </div>
                        <template x-if="opt.sublabel">
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-truncate" x-text="opt.sublabel"></span>
                        </template>
                    </div>

                    <div class="tw-shrink-0" x-show="String(opt.value) === String(selectedValue)">
                        <x-ui.icon name="check" size="sm" class="tw-text-primary" />
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
    </div>

    @if($helper)<p id="{{ $helperId }}" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $helper }}</p>@endif
    @if($message)
        <p id="{{ $errorId }}" class="tw-m-0 tw-flex tw-items-start tw-gap-1.5 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">
            <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5" /><span>{{ $message }}</span>
        </p>
    @endif
</div>
