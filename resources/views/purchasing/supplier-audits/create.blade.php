@extends('layouts.app')
@section('title', __('supplier_audit.create.title'))
@section('page-title', __('supplier_audit.title'))

@section('content')
@php
    $oldSelected = collect(old('supplier_ids', []))->map(fn ($value) => (string) $value)->all();
    $supplierRows = $suppliers->map(fn ($supplier) => [
        'value' => $supplier['value'],
        'label' => $supplier['label'],
        'email' => $supplier['email'],
        'disabled' => $supplier['active_status'] !== null,
        'status' => $supplier['active_status'] ? \App\Support\StatusHelper::supplierAuditLabel($supplier['active_status']) : null,
        'last' => $supplier['last_audit']
            ? __('supplier_audit.create_extra.last_audit', ['value' => $supplier['last_audit']])
            : __('supplier_audit.create_extra.never_audited'),
    ])->values();
    $businessToday = \App\Support\BusinessTime::today();
    $deadlinePresets = [
        $businessToday->addDays(14)->toDateString() => __('supplier_audit.create_extra.preset_14'),
        $businessToday->addDays(30)->toDateString() => __('supplier_audit.create_extra.preset_30'),
        '' => __('supplier_audit.create_extra.preset_none'),
    ];
@endphp
<script>
    function supplierAuditAssign(rows, initial, labels) {
        return {
            rows,
            selected: initial.filter((value) => rows.some((row) => row.value === value && !row.disabled)),
            search: '',
            labels,
            get visibleRows() {
                const term = this.search.trim().toLowerCase();
                if (!term) return this.rows;
                return this.rows.filter((row) => `${row.label} ${row.email}`.toLowerCase().includes(term));
            },
            get selectedLabel() {
                return this.labels.selected.replace(':count', this.selected.length);
            },
            get skippedCount() {
                return this.rows.filter((row) => row.disabled).length;
            },
            get summaryLabel() {
                return this.labels.summary.replace(':selected', this.selected.length).replace(':skipped', this.skippedCount);
            },
            setDeadline(value) {
                const input = document.getElementById('due_date');
                if (!input) return;
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            },
            activeLabel(row) {
                return this.labels.active.replace(':status', row.status);
            },
            selectAllVisible() {
                const values = new Set(this.selected);
                this.visibleRows.filter((row) => !row.disabled).forEach((row) => values.add(row.value));
                this.selected = [...values];
            },
        };
    }
</script>
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.page-header
        :title="__('supplier_audit.create.title')"
        :description="__('supplier_audit.create.description')"
        :eyebrow="__('supplier_audit.title')"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.supplier-audits.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_audit.actions.back_to_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(! $template)
        <x-ui.alert tone="warning" :title="__('supplier_audit.errors.no_active_template')">{{ __('supplier_audit.create.no_template') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('purchasing.supplier-audits.store') }}" data-async-submit class="tw-grid tw-gap-6" id="supplierAuditAssignForm"
        x-data="supplierAuditAssign(@js($supplierRows), @js($oldSelected), @js(['selected' => __('supplier_audit.labels.selected_count', ['count' => ':count']), 'active' => __('supplier_audit.labels.active_status', ['status' => ':status']), 'summary' => __('supplier_audit.create_extra.summary', ['selected' => ':selected', 'skipped' => ':skipped'])]))">
        @csrf

        <x-ui.form-section :title="__('supplier_audit.fields.suppliers')" :description="__('supplier_audit.create.suppliers_help')">
            <div class="tw-grid tw-gap-3 md:tw-col-span-2">
                <div class="tw-flex tw-flex-col tw-gap-2 sm:tw-flex-row sm:tw-items-end sm:tw-justify-between">
                    <label class="tw-grid tw-gap-1.5 sm:tw-w-80" for="supplierAuditSearch">
                        <span class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('supplier_audit.create.search_placeholder') }}</span>
                        <input type="search" id="supplierAuditSearch" x-model.debounce.150ms="search" autocomplete="off" data-unsaved-ignore
                            class="ui-motion tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-strong tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary">
                    </label>
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                        <span class="tw-text-ui-sm tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" role="status" aria-live="polite" x-text="selectedLabel"></span>
                        <x-ui.button type="button" variant="outline" size="sm" x-on:click="selectAllVisible()">{{ __('supplier_audit.actions.select_all') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="selected = []">{{ __('supplier_audit.actions.clear') }}</x-ui.button>
                    </div>
                </div>

                <fieldset class="tw-m-0 tw-min-w-0 tw-border-0 tw-p-0">
                    <legend class="tw-sr-only">{{ __('supplier_audit.fields.suppliers') }}</legend>
                    @if($supplierRows->isEmpty())
                        <x-ui.empty-state icon="building-2" :title="__('supplier_audit.create.no_suppliers')" />
                    @else
                        <ul class="tw-m-0 tw-max-h-96 tw-list-none tw-divide-y tw-divide-outline-variant tw-overflow-y-auto tw-rounded-ui-sm tw-border tw-border-outline-variant tw-p-0" data-supplier-audit-supplier-list>
                            <template x-for="row in visibleRows" :key="row.value">
                                <li>
                                    <label class="tw-m-0 tw-flex tw-min-h-11 tw-items-center tw-gap-3 tw-px-3 tw-py-2"
                                        :class="row.disabled ? 'tw-cursor-not-allowed tw-opacity-60' : 'tw-cursor-pointer hover:tw-bg-surface-container'">
                                        <input type="checkbox" name="supplier_ids[]" class="form-check-input tw-m-0 tw-shrink-0"
                                            :value="row.value" :disabled="row.disabled" x-model="selected">
                                        <span class="tw-min-w-0 tw-flex-1">
                                            <span class="tw-block tw-truncate tw-text-ui-sm tw-font-medium tw-text-on-surface" x-text="row.label"></span>
                                            <span class="tw-block tw-truncate tw-text-ui-xs tw-text-on-surface-variant" x-text="row.email"></span>
                                            <span class="tw-block tw-truncate tw-text-ui-xs tw-text-on-surface-variant" x-text="row.last"></span>
                                        </span>
                                        <template x-if="row.status">
                                            <span class="tw-shrink-0 tw-text-ui-xs tw-text-on-surface-variant" x-text="activeLabel(row)"></span>
                                        </template>
                                    </label>
                                </li>
                            </template>
                            <li x-show="visibleRows.length === 0" class="tw-px-3 tw-py-4 tw-text-ui-sm tw-text-on-surface-variant">{{ __('supplier_audit.create.no_match') }}</li>
                        </ul>
                    @endif
                    <x-ui.field-error :messages="$errors->get('supplier_ids')" class="tw-mt-2" data-field-error="supplier_ids" />
                </fieldset>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('supplier_audit.fields.period')">
            <x-ui.input name="period_label" id="period_label" :label="__('supplier_audit.fields.period')" :helper="__('supplier_audit.create.period_help')" required maxlength="100" />
            <div class="tw-grid tw-content-start tw-gap-2">
                <x-ui.date-picker name="due_date" id="due_date" :label="__('supplier_audit.fields.due_date')" :helper="__('supplier_audit.create.due_date_help')" :min="\App\Support\BusinessTime::today()->toDateString()" />
                <div class="tw-flex tw-flex-wrap tw-gap-1" data-deadline-presets>
                    @foreach($deadlinePresets as $presetValue => $presetLabel)
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="setDeadline(@js($presetValue))">{{ $presetLabel }}</x-ui.button>
                    @endforeach
                </div>
            </div>
            <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                <span class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('supplier_audit.fields.template') }}</span>
                <span class="tw-text-ui-sm tw-text-on-surface-variant">
                    {{ $template ? __('supplier_audit.create.template_label', ['title' => $template->title, 'version' => $template->version]) : '—' }}
                </span>
            </div>
        </x-ui.form-section>

        <x-ui.action-bar>
            <span class="tw-me-auto tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" role="status" aria-live="polite" x-text="summaryLabel"></span>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.supplier-audits.index')" variant="ghost">{{ __('supplier_audit.actions.back') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" :disabled="! $template || $supplierRows->isEmpty()">
                <x-ui.icon name="send" size="sm" />
                <span>{{ __('supplier_audit.actions.assign') }}</span>
            </x-ui.button>
        </x-ui.action-bar>
    </form>
</div>
@endsection
