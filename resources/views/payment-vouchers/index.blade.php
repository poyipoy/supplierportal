@extends('layouts.app')
@section('title', __('payment_voucher.page_title'))
@section('page-title', __('payment_voucher.title'))

@section('content')
@php
    use App\Models\PaymentVoucher;
    use App\Support\StatusHelper;
@endphp

<div class="tw:grid tw:gap-6 tw:pb-12">
    <x-ui.page-header
        :title="__('payment_voucher.list.heading')"
        :description="__('payment_voucher.list.description')"
        :eyebrow="__('payment_voucher.eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route($routePrefix.'.create')" variant="primary" size="md">
                <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                {{ __('payment_voucher.actions.new') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $hasActiveFilters = collect($filters)->filter()->isNotEmpty();
    @endphp

    <form
        method="GET"
        action="{{ route($routePrefix.'.index') }}"
        role="search"
        aria-label="{{ __('payment_voucher.title') }}"
        class="tw:m-0 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:p-4 tw:grid tw:gap-3.5"
    >
        @if(isset($errors) && $errors->any())
            <x-ui.alert tone="error">
                {{ $errors->first() }}
            </x-ui.alert>
        @endif

        <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:lg:grid-cols-6 tw:xl:grid-cols-12 tw:gap-3.5 tw:items-end">
            {{-- Row 1: Search, Department, Status --}}
            <div class="tw:grid tw:gap-1.5 tw:min-w-0 tw:sm:col-span-2 tw:lg:col-span-4 tw:xl:col-span-6">
                <label for="pv-filter-q" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">
                    {{ __('payment_voucher.filters.search') }}
                </label>
                <div class="tw:relative tw:min-w-0">
                    <input
                        id="pv-filter-q"
                        type="search"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="{{ __('payment_voucher.filters.search_placeholder') }}"
                        maxlength="120"
                        autocomplete="off"
                        class="form-control ui-focus-ring tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:ps-10 tw:pe-3 tw:text-ui-sm tw:text-on-surface tw:placeholder:text-on-surface-variant"
                    >
                    <x-ui.icon
                        name="search"
                        size="sm"
                        class="tw:pointer-events-none tw:z-10 tw:text-on-surface-variant tw:absolute tw:start-3 tw:top-1/2 tw:-translate-y-1/2"
                        aria-hidden="true"
                    />
                </div>
            </div>

            <div class="tw:grid tw:gap-1.5 tw:min-w-0 tw:lg:col-span-2 tw:xl:col-span-3">
                <label for="pv-filter-dept" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">
                    {{ __('payment_voucher.filters.department') }}
                </label>
                <select
                    id="pv-filter-dept"
                    name="department"
                    class="form-select ui-focus-ring tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-ui-sm tw:text-on-surface"
                >
                    <option value="">{{ __('payment_voucher.filters.all_departments') }}</option>
                    @foreach(PaymentVoucher::DEPARTMENTS as $dept)
                        <option value="{{ $dept }}" @selected(($filters['department'] ?? '') === $dept)>
                            {{ PaymentVoucher::departmentLabel($dept) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="tw:grid tw:gap-1.5 tw:min-w-0 tw:lg:col-span-2 tw:xl:col-span-3">
                <label for="pv-filter-status" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">
                    {{ __('payment_voucher.filters.status') }}
                </label>
                <select
                    id="pv-filter-status"
                    name="status"
                    class="form-select ui-focus-ring tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-ui-sm tw:text-on-surface"
                >
                    <option value="">{{ __('payment_voucher.filters.all_statuses') }}</option>
                    @foreach(PaymentVoucher::STATUSES as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                            {{ StatusHelper::localFinanceLabel($status) }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Row 2: Method, Payee Source, Date Range, Actions --}}
            <div class="tw:grid tw:gap-1.5 tw:min-w-0 tw:lg:col-span-2 tw:xl:col-span-2">
                <label for="pv-filter-method" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">
                    {{ __('payment_voucher.filters.method') }}
                </label>
                <select
                    id="pv-filter-method"
                    name="payment_method"
                    class="form-select ui-focus-ring tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-ui-sm tw:text-on-surface"
                >
                    <option value="">{{ __('payment_voucher.filters.all_methods') }}</option>
                    <option value="{{ PaymentVoucher::METHOD_BANK }}" @selected(($filters['payment_method'] ?? '') === PaymentVoucher::METHOD_BANK)>
                        {{ __('payment_voucher.methods.bank') }}
                    </option>
                    <option value="{{ PaymentVoucher::METHOD_KAS }}" @selected(($filters['payment_method'] ?? '') === PaymentVoucher::METHOD_KAS)>
                        {{ __('payment_voucher.methods.kas') }}
                    </option>
                </select>
            </div>

            <div class="tw:grid tw:gap-1.5 tw:min-w-0 tw:lg:col-span-2 tw:xl:col-span-3">
                <label for="pv-filter-payee" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">
                    {{ __('payment_voucher.filters.payee_type') }}
                </label>
                <select
                    id="pv-filter-payee"
                    name="payee_type"
                    class="form-select ui-focus-ring tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-ui-sm tw:text-on-surface"
                >
                    <option value="">{{ __('payment_voucher.filters.all_payee_types') }}</option>
                    @foreach(PaymentVoucher::PAYEE_TYPES as $payeeType)
                        <option value="{{ $payeeType }}" @selected(($filters['payee_type'] ?? '') === $payeeType)>
                            {{ PaymentVoucher::payeeTypeLabel($payeeType) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="tw:grid tw:gap-1.5 tw:min-w-0 tw:sm:col-span-2 tw:lg:col-span-4 tw:xl:col-span-4">
                <span class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant" aria-hidden="true">
                    {{ __('payment_voucher.filters.date_range') }}
                </span>
                <x-ui.date-range-picker
                    id="pv-filter-date-range"
                    startName="date_from"
                    endName="date_to"
                    :startValue="$filters['date_from'] ?? null"
                    :endValue="$filters['date_to'] ?? null"
                    compact
                    class="tw:w-full"
                />
            </div>

            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-end tw:gap-2 tw:min-w-0 tw:sm:col-span-2 tw:lg:col-span-2 tw:xl:col-span-3">
                @if($hasActiveFilters)
                    <x-ui.button :href="route($routePrefix.'.index')" size="md" variant="ghost">
                        <x-slot:leading><x-ui.icon name="rotate-ccw" size="sm" /></x-slot:leading>
                        {{ __('payment_voucher.actions.reset_filters') }}
                    </x-ui.button>
                @endif
                <x-ui.button type="submit" size="md" variant="primary" class="tw:w-full tw:sm:w-auto">
                    <x-slot:leading><x-ui.icon name="filter" size="sm" /></x-slot:leading>
                    {{ __('payment_voucher.actions.apply_filters') }}
                </x-ui.button>
            </div>
        </div>
    </form>

    <x-ui.data-table
        :title="__('payment_voucher.list.table_title')"
        :description="__('payment_voucher.list.summary', ['count' => $vouchers->total()])"
        :empty="$vouchers->isEmpty()"
        :scrollLabel="__('payment_voucher.table.scroll_label')"
    >
        <x-slot:emptyState>
            <x-ui.empty-state
                icon="receipt"
                :title="__('payment_voucher.list.empty')"
                :actionUrl="route($routePrefix.'.create')"
                :actionText="__('payment_voucher.list.empty_action')"
            />
        </x-slot:emptyState>

        <x-slot:cards>
            @foreach($vouchers as $voucher)
                <article class="tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface tw:p-3.5 tw:grid tw:gap-2.5">
                    <div class="tw:flex tw:items-start tw:justify-between tw:gap-2">
                        <div class="tw:min-w-0">
                            <a href="{{ route($routePrefix.'.show', $voucher) }}" class="ui-tabular-nums tw:text-ui-sm tw:font-bold tw:text-primary tw:no-underline tw:hover:underline">
                                {{ $voucher->voucher_number }}
                            </a>
                            <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">
                                {{ PaymentVoucher::departmentLabel($voucher->source_department) }} · {{ $regionalFormatter->date($voucher->voucher_date, 'human') ?? '-' }}
                            </p>
                        </div>
                        <x-ui.status-chip :tone="StatusHelper::localFinanceTone($voucher->status)">
                            {{ StatusHelper::localFinanceLabel($voucher->status) }}
                        </x-ui.status-chip>
                    </div>

                    <div class="tw:min-w-0">
                        <div class="tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ $voucher->payee_name_snapshot }}</div>
                        <div class="tw:text-ui-xs tw:text-on-surface-variant">
                            {{ PaymentVoucher::payeeTypeLabel($voucher->payee_type) }}
                            @if($voucher->reference_number)
                                · {{ __('payment_voucher.table.reference_prefix') }} {{ $voucher->reference_number }}
                            @endif
                        </div>
                    </div>

                    <div class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:border-t tw:border-outline-variant tw:pt-2.5">
                        <span class="ui-tabular-nums tw:text-ui-sm tw:font-bold tw:text-on-surface">
                            Rp {{ $regionalFormatter->money($voucher->net_amount, 2) }}
                        </span>
                        <div class="tw:flex tw:items-center tw:gap-1.5">
                            <x-ui.button :href="route($routePrefix.'.print', $voucher)" target="_blank" size="sm" variant="outline">
                                <x-slot:leading><x-ui.icon name="printer" size="sm" /></x-slot:leading>
                                {{ __('payment_voucher.actions.print_pdf') }}
                            </x-ui.button>
                            <x-ui.button :href="route($routePrefix.'.show', $voucher)" size="sm" variant="secondary">
                                {{ __('payment_voucher.actions.detail') }}
                            </x-ui.button>
                        </div>
                    </div>
                </article>
            @endforeach
        </x-slot:cards>

        <table class="table table-hover align-middle tw:m-0 tw:text-ui-sm w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('payment_voucher.labels.voucher_number') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('payment_voucher.labels.payee') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('payment_voucher.labels.department') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('payment_voucher.labels.voucher_date') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('payment_voucher.labels.payment_method') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold text-end">{{ __('payment_voucher.labels.net_amount') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold text-center">{{ __('payment_voucher.labels.status') }}</th>
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold text-end">{{ __('payment_voucher.labels.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vouchers as $voucher)
                    <tr>
                        <td>
                            <a href="{{ route($routePrefix.'.show', $voucher) }}" class="ui-tabular-nums tw:font-semibold tw:text-primary tw:no-underline tw:hover:underline">
                                {{ $voucher->voucher_number }}
                            </a>
                            @if($voucher->reference_number)
                                <div class="tw:text-[11px] tw:text-on-surface-variant">
                                    {{ __('payment_voucher.table.reference_prefix') }} {{ $voucher->reference_number }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="tw:font-medium tw:text-on-surface">{{ $voucher->payee_name_snapshot }}</div>
                            <div class="tw:text-[11px] tw:text-on-surface-variant">
                                {{ PaymentVoucher::payeeTypeLabel($voucher->payee_type) }}
                                @if($voucher->isBankMethod() && $voucher->bank_name_snapshot)
                                    · {{ $voucher->bank_name_snapshot }} {{ $voucher->bank_account_snapshot }}
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="tw:text-ui-xs tw:font-medium tw:text-on-surface">
                                {{ PaymentVoucher::departmentLabel($voucher->source_department) }}
                            </span>
                        </td>
                        <td>
                            <span class="tw:text-ui-xs tw:text-on-surface-variant">
                                {{ $regionalFormatter->date($voucher->voucher_date, 'human') ?? '-' }}
                            </span>
                        </td>
                        <td>
                            <span class="tw:text-ui-xs tw:font-medium tw:text-on-surface">
                                {{ $voucher->isBankMethod() ? __('payment_voucher.methods.bank') : __('payment_voucher.methods.kas') }}
                            </span>
                        </td>
                        <td class="text-end ui-tabular-nums tw:font-bold tw:text-on-surface">
                            Rp {{ $regionalFormatter->money($voucher->net_amount, 2) }}
                        </td>
                        <td class="text-center">
                            <x-ui.status-chip :tone="StatusHelper::localFinanceTone($voucher->status)">
                                {{ StatusHelper::localFinanceLabel($voucher->status) }}
                            </x-ui.status-chip>
                        </td>
                        <td class="text-end">
                            <div class="tw:inline-flex tw:items-center tw:justify-end tw:gap-1.5">
                                <x-ui.button :href="route($routePrefix.'.print', $voucher)" target="_blank" size="sm" variant="outline" :title="__('payment_voucher.actions.print_pdf')">
                                    <x-slot:leading><x-ui.icon name="printer" size="sm" /></x-slot:leading>
                                    {{ __('payment_voucher.actions.print_pdf') }}
                                </x-ui.button>
                                <x-ui.button :href="route($routePrefix.'.show', $voucher)" size="sm" variant="secondary">
                                    {{ __('payment_voucher.actions.detail') }}
                                </x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($vouchers->hasPages())
            <x-slot:pagination>
                {{ $vouchers->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection

