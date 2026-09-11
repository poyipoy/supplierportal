@php
    $hasAdvancedFilters = request()->filled('from') || request()->filled('to') || request()->filled('due_from') || request()->filled('due_to') || request()->filled('supplier') || request()->boolean('overdue') || request()->boolean('history');
@endphp

<form method="GET" action="{{ url()->current() }}" class="tw-mb-4" id="invoiceFilterForm">
    <x-ui.toolbar aria-label="Invoice filter controls">
        <x-slot:search>
            <div class="tw-relative tw-w-full">
                <input
                    type="search"
                    name="q"
                    id="invoice-search"
                    class="form-control form-control-sm"
                    placeholder="Search invoice, submission, PO, or receipt..."
                    value="{{ request('q') }}"
                    maxlength="100"
                    autocomplete="off"
                >
            </div>
        </x-slot:search>

        <x-slot:filters>
            <div class="tw-flex tw-items-center tw-gap-2">
                <label for="invoice-status" class="visually-hidden">Status</label>
                <select id="invoice-status" class="form-select form-select-sm tw-min-w-[160px]" name="status">
                    <option value="">All Statuses</option>
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
                    class="tw-relative"
                >
                    <x-ui.icon name="sliders-horizontal" size="sm" />
                    <span>More Filters</span>
                    @if($hasAdvancedFilters)
                        <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-2 tw-h-2 tw-rounded-full tw-bg-primary"></span>
                    @endif
                </x-ui.button>
            </div>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button type="submit" size="sm">
                <x-ui.icon name="filter" size="sm" />
                <span>Apply</span>
            </x-ui.button>
            @if(request()->hasAny(['q', 'status', 'from', 'to', 'due_from', 'due_to', 'supplier', 'overdue', 'history']))
                <x-ui.button :href="url()->current()" variant="ghost" size="sm">
                    <x-ui.icon name="rotate-ccw" size="sm" />
                    <span>Reset</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.toolbar>

    {{-- Collapsible Advanced Filters Drawer --}}
    <div class="collapse {{ $hasAdvancedFilters ? 'show' : '' }}" id="invoiceMoreFilters">
        <div class="tw-mb-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4">
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">Submission Date Range</label>
                    <x-ui.date-range-picker
                        id="invoice-submitted-range"
                        start-name="from"
                        end-name="to"
                        start-label="Submitted From"
                        end-label="Submitted To"
                        :start-value="request('from')"
                        :end-value="request('to')"
                    />
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">Due Date Range</label>
                    <x-ui.date-range-picker
                        id="invoice-due-range"
                        start-name="due_from"
                        end-name="due_to"
                        start-label="Due From"
                        end-label="Due To"
                        :start-value="request('due_from')"
                        :end-value="request('due_to')"
                    />
                </div>

                @if(! auth()->user()->isSupplier())
                    <div>
                        <label for="invoice-supplier" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">Supplier Organization</label>
                        <select name="supplier" id="invoice-supplier" class="form-select form-select-sm">
                            <option value="">All Suppliers</option>
                            @foreach($suppliers ?? [] as $supplier)
                                <option value="{{ $supplier->hash }}" @selected(request('supplier') === $supplier->hash)>
                                    {{ $supplier->supplier?->company_name ?: $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:tw-col-span-2 lg:tw-col-span-3 tw-flex tw-flex-wrap tw-items-center tw-gap-4 tw-pt-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="overdue" name="overdue" value="1" @checked(request('overdue'))>
                            <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-error" for="overdue">
                                <x-ui.icon name="alert-triangle" size="xs" class="tw-inline" /> Show Overdue Only
                            </label>
                        </div>
                        @if($payments ?? false)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="history" name="history" value="1" @checked(request('history'))>
                                <label class="form-check-label tw-text-ui-sm tw-text-on-surface" for="history">
                                    Include Completed Payments
                                </label>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</form>

@if($errors->any())
    <div class="alert alert-danger tw-mb-4" role="alert">
        <x-ui.icon name="alert-circle" size="sm" class="tw-inline tw-me-1" />
        {{ $errors->first() }}
    </div>
@endif
