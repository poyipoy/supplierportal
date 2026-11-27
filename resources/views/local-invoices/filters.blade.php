@php
    $serverTabs = (bool) ($serverTabs ?? false);
    $activePaymentStatus = strtoupper((string) ($paymentStatus ?? request('payment_status', 'ALL')));
    if (! in_array($activePaymentStatus, ['ALL', 'UNPAID', 'PAID'], true)) {
        $activePaymentStatus = 'ALL';
    }

    $isSupplierUser = (bool) auth()->user()?->isSupplier();

    $suppliers = $suppliers ?? (auth()->check() && ! $isSupplierUser
        ? \App\Models\User::localEligible()->with('supplier')->orderBy('name')->get()
        : collect());

    $resetUrl = $serverTabs && $activePaymentStatus !== 'ALL'
        ? url()->current().'?'.http_build_query(['payment_status' => $activePaymentStatus])
        : url()->current();

    $buildRemoveUrl = static function (array $dropKeys) use ($serverTabs, $activePaymentStatus): string {
        $query = request()->query();
        foreach ($dropKeys as $key) {
            unset($query[$key]);
        }
        if ($serverTabs && $activePaymentStatus !== 'ALL') {
            $query['payment_status'] = $activePaymentStatus;
        } elseif ($serverTabs) {
            unset($query['payment_status']);
        }

        return $query === []
            ? url()->current()
            : url()->current().'?'.http_build_query($query);
    };

    $overpaymentFilter = request('overpayment_status');
    $hasOverpaymentFilter = filled($overpaymentFilter) && $overpaymentFilter !== 'all';

    $advancedFilterCount = collect([
        request()->filled('from') || request()->filled('to'),
        request()->filled('due_from') || request()->filled('due_to'),
        ! $isSupplierUser && request()->filled('supplier'),
        $hasOverpaymentFilter,
        ! $isSupplierUser && request()->boolean('overdue'),
        ! $isSupplierUser && ($payments ?? false) && request()->boolean('history'),
    ])->filter()->count();

    $hasAdvancedFilters = $advancedFilterCount > 0;

    $activeChips = [];
    if (request()->filled('q')) {
        $activeChips[] = [
            'key' => 'q',
            'label' => __('local_invoice.actions.search'),
            'value' => (string) request('q'),
            'removeUrl' => $buildRemoveUrl(['q']),
        ];
    }
    if (request()->filled('status')) {
        $activeChips[] = [
            'key' => 'status',
            'label' => __('local_invoice.labels.status'),
            'value' => \App\Support\StatusHelper::localInvoiceLabel((string) request('status')),
            'removeUrl' => $buildRemoveUrl(['status']),
        ];
    }
    if (request()->filled('from') || request()->filled('to')) {
        $subValue = request()->filled('from') && request()->filled('to')
            ? request('from').' - '.request('to')
            : (string) (request('from') ?: request('to'));
        $activeChips[] = [
            'key' => 'submitted_range',
            'label' => __('local_invoice.filters.submission_range'),
            'value' => $subValue,
            'removeUrl' => $buildRemoveUrl(['from', 'to']),
        ];
    }
    if (request()->filled('due_from') || request()->filled('due_to')) {
        $dueValue = request()->filled('due_from') && request()->filled('due_to')
            ? request('due_from').' - '.request('due_to')
            : (string) (request('due_from') ?: request('due_to'));
        $activeChips[] = [
            'key' => 'due_range',
            'label' => __('local_invoice.filters.due_range'),
            'value' => $dueValue,
            'removeUrl' => $buildRemoveUrl(['due_from', 'due_to']),
        ];
    }
    if ($hasOverpaymentFilter) {
        $opValue = match ($overpaymentFilter) {
            'open' => __('local_invoice.filters.refund_open'),
            'settled' => __('finance.refund.completed_status'),
            default => (string) $overpaymentFilter,
        };
        $activeChips[] = [
            'key' => 'overpayment_status',
            'label' => __('finance.refund.status'),
            'value' => $opValue,
            'removeUrl' => $buildRemoveUrl(['overpayment_status']),
        ];
    }
    if (! $isSupplierUser && request()->filled('supplier')) {
        $selectedSupplier = $suppliers->firstWhere('hash', request('supplier'));
        $supplierLabel = $selectedSupplier
            ? ($selectedSupplier->supplier?->company_name ?: $selectedSupplier->name)
            : (string) request('supplier');
        $activeChips[] = [
            'key' => 'supplier',
            'label' => __('local_invoice.filters.supplier_organization'),
            'value' => $supplierLabel,
            'removeUrl' => $buildRemoveUrl(['supplier']),
        ];
    }
    if (! $isSupplierUser && request()->boolean('overdue')) {
        $activeChips[] = [
            'key' => 'overdue',
            'label' => __('local_invoice.labels.status'),
            'value' => __('local_invoice.filters.overdue'),
            'removeUrl' => $buildRemoveUrl(['overdue']),
        ];
    }
    if (! $isSupplierUser && ($payments ?? false) && request()->boolean('history')) {
        $activeChips[] = [
            'key' => 'history',
            'label' => __('local_invoice.labels.status'),
            'value' => __('local_invoice.filters.include_paid'),
            'removeUrl' => $buildRemoveUrl(['history']),
        ];
    }

    $hasAnyFilters = count($activeChips) > 0;
@endphp

<x-ui.filter-bar
    id="invoiceFilterForm"
    :action="url()->current()"
    :server-tabs="$serverTabs"
    :aria-label="__('local_invoice.filters.controls')"
    advanced-id="invoiceMoreFilters"
    :advanced-count="$advancedFilterCount"
    :advanced-open="$hasAdvancedFilters"
    :advanced-label="__('local_invoice.filters.advanced')"
    :advanced-title="__('local_invoice.filters.criteria')"
    :advanced-description="__('local_invoice.filters.criteria_help')"
    :has-active-filters="$hasAnyFilters"
    :reset-url="$resetUrl"
    :reset-label="__('local_invoice.filters.reset')"
    :submit-label="__('local_invoice.actions.apply_filters')"
>
    @if($serverTabs)
        <x-slot:hidden>
            <input
                type="hidden"
                id="invoice-payment-status"
                @if($activePaymentStatus !== 'ALL') name="payment_status" @endif
                value="{{ $activePaymentStatus !== 'ALL' ? $activePaymentStatus : '' }}"
            >
        </x-slot:hidden>
    @endif

    <x-slot:search>
        <label for="invoice-search" class="tw:sr-only">{{ __('local_invoice.actions.search') }}</label>
        <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
        <input
            type="search"
            name="q"
            id="invoice-search"
            class="ui-input ui-input--sm ui-filter-bar__search-input"
            placeholder="{{ __('local_invoice.filters.search') }}"
            value="{{ request('q') }}"
            maxlength="100"
            autocomplete="off"
        >
    </x-slot:search>

    <x-slot:filters>
        <label for="invoice-status" class="tw:sr-only">{{ __('local_invoice.labels.status') }}</label>
        <select id="invoice-status" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-auto tw:sm:min-w-[160px]" name="status">
            <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
            @foreach(\App\Models\LocalInvoice::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>
                    {{ \App\Support\StatusHelper::localInvoiceLabel($status) }}
                </option>
            @endforeach
        </select>
    </x-slot:filters>

    <x-slot:advanced>
        <div>
            <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('local_invoice.filters.submission_range') }}</label>
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
            <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('local_invoice.filters.due_range') }}</label>
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
            <label for="invoice-overpayment-status" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('finance.refund.status') }}</label>
            <select name="overpayment_status" id="invoice-overpayment-status" class="ui-select ui-select--sm tw:w-full">
                <option value="all" @selected(request('overpayment_status') === 'all' || !request()->filled('overpayment_status'))>{{ __('finance.refund.all_statuses') }}</option>
                <option value="open" @selected(request('overpayment_status') === 'open')>{{ __('local_invoice.filters.refund_open') }}</option>
                <option value="settled" @selected(request('overpayment_status') === 'settled')>{{ __('finance.refund.completed_status') }}</option>
            </select>
        </div>

        @if(! $isSupplierUser)
            <div>
                <label for="invoice-supplier" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('local_invoice.filters.supplier_organization') }}</label>
                <select name="supplier" id="invoice-supplier" class="ui-select ui-select--sm tw:w-full">
                    <option value="">{{ __('local_invoice.labels.all_suppliers') }}</option>
                    @foreach($suppliers ?? [] as $supplier)
                        <option value="{{ $supplier->hash }}" @selected(request('supplier') === $supplier->hash)>
                            {{ $supplier->supplier?->company_name ?: $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif
    </x-slot:advanced>

    @if(! $isSupplierUser)
        <x-slot:advancedFooter>
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                <label
                    for="overdue"
                    class="ui-motion tw:inline-flex tw:items-center tw:gap-2 tw:rounded-ui-sm tw:border tw:px-3 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:cursor-pointer select-none {{ request('overdue') ? 'tw:border-error tw:bg-error-container tw:text-on-error-container' : 'tw:border-outline-variant tw:bg-surface tw:text-on-surface tw:hover:border-outline tw:hover:bg-surface-container' }}"
                >
                    <input
                        class="ui-check tw:m-0 tw:cursor-pointer"
                        type="checkbox"
                        id="overdue"
                        name="overdue"
                        value="1"
                        @checked(request('overdue'))
                    >
                    <x-ui.icon name="alert-triangle" size="xs" class="{{ request('overdue') ? 'tw:text-error' : 'tw:text-on-surface-variant' }}" />
                    <span>{{ __('local_invoice.filters.overdue') }}</span>
                </label>

                @if($payments ?? false)
                    <label
                        for="history"
                        class="ui-motion tw:inline-flex tw:items-center tw:gap-2 tw:rounded-ui-sm tw:border tw:px-3 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:cursor-pointer select-none {{ request('history') ? 'tw:border-primary tw:bg-primary-container tw:text-on-primary-container' : 'tw:border-outline-variant tw:bg-surface tw:text-on-surface tw:hover:border-outline tw:hover:bg-surface-container' }}"
                    >
                        <input
                            class="ui-check tw:m-0 tw:cursor-pointer"
                            type="checkbox"
                            id="history"
                            name="history"
                            value="1"
                            @checked(request('history'))
                        >
                        <x-ui.icon name="check-circle" size="xs" class="{{ request('history') ? 'tw:text-primary' : 'tw:text-on-surface-variant' }}" />
                        <span>{{ __('local_invoice.filters.include_paid') }}</span>
                    </label>
                @endif
            </div>
        </x-slot:advancedFooter>
    @endif

    <x-slot:chips>
        <x-ui.filter-chips :items="$activeChips" :clear-url="$resetUrl" :server-tabs="$serverTabs" />
    </x-slot:chips>
</x-ui.filter-bar>

@if(isset($errors) && $errors->any())
    <div class="tw:rounded-ui-md tw:border tw:border-error/40 tw:bg-error-container tw:text-error-container-foreground tw:p-3.5 tw:text-ui-sm tw:mb-4" role="alert">
        <x-ui.icon name="alert-circle" size="sm" class="tw:inline tw:me-1" />
        {{ $errors->first() }}
    </div>
@endif
