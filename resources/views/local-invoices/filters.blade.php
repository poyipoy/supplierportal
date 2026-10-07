@php
    $advancedFilterCount = collect([
        request()->filled('from') || request()->filled('to'),
        request()->filled('due_from') || request()->filled('due_to'),
        request()->filled('supplier'),
        request()->filled('overpayment_status') && request('overpayment_status') !== 'all',
        request()->boolean('overdue'),
        request()->boolean('history'),
    ])->filter()->count();

    $hasAdvancedFilters = $advancedFilterCount > 0;
    $hasAnyFilters = request()->hasAny(['q', 'status', 'from', 'to', 'due_from', 'due_to', 'supplier', 'overdue', 'history', 'overpayment_status']);

    $suppliers = $suppliers ?? (auth()->check() && ! auth()->user()->isSupplier()
        ? \App\Models\User::localEligible()->with('supplier')->orderBy('name')->get()
        : collect());
@endphp

<form method="GET" action="{{ url()->current() }}" class="tw-mb-4" id="invoiceFilterForm">
    <x-ui.toolbar :aria-label="__('local_invoice.filters.controls')">
        <x-slot:search>
            <div class="tw-relative tw-w-full">
                <input
                    type="search"
                    name="q"
                    id="invoice-search"
                    class="form-control form-control-sm"
                    placeholder="{{ __('local_invoice.filters.search') }}"
                    value="{{ request('q') }}"
                    maxlength="100"
                    autocomplete="off"
                >
            </div>
        </x-slot:search>

        <x-slot:filters>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                <label for="invoice-status" class="visually-hidden">{{ __('local_invoice.labels.status') }}</label>
                <select id="invoice-status" class="form-select form-select-sm tw-w-full tw-min-w-0 sm:tw-w-auto sm:tw-min-w-[160px]" name="status">
                    <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                    @foreach(\App\Models\LocalInvoice::STATUSES as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>
                            {{ \App\Support\StatusHelper::localInvoiceLabel($status) }}
                        </option>
                    @endforeach
                </select>

                <x-ui.button
                    variant="outline"
                    size="sm"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#invoiceMoreFilters"
                    aria-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}"
                    aria-controls="invoiceMoreFilters"
                    class="tw-relative tw-gap-1.5"
                >
                    <x-ui.icon name="sliders-horizontal" size="sm" />
                    <span>{{ __('local_invoice.filters.advanced') }}</span>
                    @if($advancedFilterCount > 0)
                        <span class="tw-inline-flex tw-items-center tw-justify-center tw-min-w-5 tw-h-5 tw-px-1.5 tw-rounded-ui-full tw-bg-primary tw-text-primary-foreground tw-text-[11px] tw-font-bold tw-leading-none">
                            {{ $advancedFilterCount }}
                        </span>
                    @endif
                </x-ui.button>
            </div>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button type="submit" size="sm">
                <x-ui.icon name="filter" size="sm" />
                <span>{{ __('local_invoice.actions.apply') }}</span>
            </x-ui.button>
            @if($hasAnyFilters)
                <x-ui.button :href="url()->current()" variant="ghost" size="sm">
                    <x-ui.icon name="rotate-ccw" size="sm" />
                    <span>{{ __('local_invoice.filters.reset') }}</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.toolbar>

    {{-- Collapsible Advanced Filters Panel --}}
    <div class="collapse {{ $hasAdvancedFilters ? 'show' : '' }}" id="invoiceMoreFilters">
        <div class="tw-mb-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-low tw-p-4 tw-shadow-sm">
            {{-- Panel Header --}}
            <div class="tw-flex tw-items-center tw-justify-between tw-pb-3.5 tw-border-b tw-border-outline-variant">
                <div class="tw-flex tw-items-center tw-gap-2.5">
                    <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-7 tw-h-7 tw-rounded-ui-sm tw-bg-primary-container tw-text-primary">
                        <x-ui.icon name="sliders-horizontal" size="sm" />
                    </span>
                    <div>
                        <h4 class="tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-m-0">{{ __('local_invoice.filters.criteria') }}</h4>
                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">{{ __('local_invoice.filters.criteria_help') }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn-close tw-text-ui-xs"
                    data-bs-toggle="collapse"
                    data-bs-target="#invoiceMoreFilters"
                    aria-label="{{ __('local_invoice.filters.close_advanced') }}"
                ></button>
            </div>

            {{-- 3-Column Inputs Grid --}}
            <div class="tw-pt-3.5 tw-grid tw-gap-4 sm:tw-grid-cols-2 lg:tw-grid-cols-3 tw-items-start">
                <div>
                    <label class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1.5">{{ __('local_invoice.filters.submission_range') }}</label>
                    <x-ui.date-range-picker
                        id="invoice-submitted-range"
                        start-name="from"
                        end-name="to"
                        :start-label="__('local_invoice.filters.submitted_from')"
                        :end-label="__('local_invoice.filters.submitted_to')"
                        :start-value="request('from')"
                        :end-value="request('to')"
                        :compact="true"
                    />
                </div>

                <div>
                    <label class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1.5">{{ __('local_invoice.filters.due_range') }}</label>
                    <x-ui.date-range-picker
                        id="invoice-due-range"
                        start-name="due_from"
                        end-name="due_to"
                        :start-label="__('local_invoice.filters.due_from')"
                        :end-label="__('local_invoice.filters.due_to')"
                        :start-value="request('due_from')"
                        :end-value="request('due_to')"
                        :compact="true"
                    />
                </div>

                <div>
                    <label for="invoice-overpayment-status" class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1.5">{{ __('finance.refund.status') }}</label>
                    <select name="overpayment_status" id="invoice-overpayment-status" class="form-select form-select-sm tw-min-h-[var(--ui-control-height-md)] tw-w-full">
                        <option value="all" @selected(request('overpayment_status') === 'all' || !request()->filled('overpayment_status'))>{{ __('finance.refund.all_statuses') }}</option>
                        <option value="open" @selected(request('overpayment_status') === 'open')>{{ __('local_invoice.filters.refund_open') }}</option>
                        <option value="settled" @selected(request('overpayment_status') === 'settled')>{{ __('finance.refund.completed_status') }}</option>
                    </select>
                </div>

                @if(! auth()->user()->isSupplier())
                    <div>
                        <label for="invoice-supplier" class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1.5">{{ __('local_invoice.filters.supplier_organization') }}</label>
                        <select name="supplier" id="invoice-supplier" class="form-select form-select-sm tw-min-h-[var(--ui-control-height-md)] tw-w-full">
                            <option value="">{{ __('local_invoice.labels.all_suppliers') }}</option>
                            @foreach($suppliers ?? [] as $supplier)
                                <option value="{{ $supplier->hash }}" @selected(request('supplier') === $supplier->hash)>
                                    {{ $supplier->supplier?->company_name ?: $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            {{-- Panel Footer: Quick Toggle Chips & Actions --}}
            <div class="tw-mt-4 tw-pt-3.5 tw-border-t tw-border-outline-variant tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
                {{-- Quick Filter Chips --}}
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                    @if(! auth()->user()->isSupplier())
                        <label
                            for="overdue"
                            class="ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-px-3 tw-py-1.5 tw-text-ui-xs tw-font-medium tw-cursor-pointer select-none {{ request('overdue') ? 'tw-border-error tw-bg-error-container tw-text-on-error-container' : 'tw-border-outline-variant tw-bg-surface tw-text-on-surface hover:tw-border-outline hover:tw-bg-surface-container' }}"
                        >
                            <input
                                class="form-check-input tw-m-0 tw-cursor-pointer"
                                type="checkbox"
                                id="overdue"
                                name="overdue"
                                value="1"
                                @checked(request('overdue'))
                            >
                            <x-ui.icon name="alert-triangle" size="xs" class="{{ request('overdue') ? 'tw-text-error' : 'tw-text-on-surface-variant' }}" />
                            <span>{{ __('local_invoice.filters.overdue') }}</span>
                        </label>

                        @if($payments ?? false)
                            <label
                                for="history"
                                class="ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-px-3 tw-py-1.5 tw-text-ui-xs tw-font-medium tw-cursor-pointer select-none {{ request('history') ? 'tw-border-primary tw-bg-primary-container tw-text-on-primary-container' : 'tw-border-outline-variant tw-bg-surface tw-text-on-surface hover:tw-border-outline hover:tw-bg-surface-container' }}"
                            >
                                <input
                                    class="form-check-input tw-m-0 tw-cursor-pointer"
                                    type="checkbox"
                                    id="history"
                                    name="history"
                                    value="1"
                                    @checked(request('history'))
                                >
                                <x-ui.icon name="check-circle" size="xs" class="{{ request('history') ? 'tw-text-primary' : 'tw-text-on-surface-variant' }}" />
                                <span>{{ __('local_invoice.filters.include_paid') }}</span>
                            </label>
                        @endif
                    @endif
                </div>

                {{-- Action Buttons --}}
                <div class="tw-flex tw-items-center tw-justify-end tw-gap-2">
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-bs-toggle="collapse"
                        data-bs-target="#invoiceMoreFilters"
                    >
                        <span>{{ __('local_invoice.actions.close') }}</span>
                    </x-ui.button>

                    @if($hasAnyFilters)
                        <x-ui.button :href="url()->current()" variant="ghost" size="sm">
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>{{ __('local_invoice.filters.reset') }}</span>
                        </x-ui.button>
                    @endif

                    <x-ui.button type="submit" variant="primary" size="sm">
                        <x-ui.icon name="filter" size="sm" />
                        <span>{{ __('local_invoice.actions.apply_filters') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
</form>

@if(isset($errors) && $errors->any())
    <div class="alert alert-danger tw-mb-4" role="alert">
        <x-ui.icon name="alert-circle" size="sm" class="tw-inline tw-me-1" />
        {{ $errors->first() }}
    </div>
@endif
