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
        $pvResetUrl = route($routePrefix.'.index');
        $pvBuildRemoveUrl = static function (array $dropKeys) use ($pvResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $pvResetUrl : $pvResetUrl.'?'.http_build_query($query);
        };

        $pvAdvancedCount = collect([
            ! empty($filters['payment_method']),
            ! empty($filters['payee_type']),
            ! empty($filters['date_from']) || ! empty($filters['date_to']),
        ])->filter()->count();

        $pvActiveChips = [];
        if (! empty($filters['q'])) {
            $pvActiveChips[] = [
                'key' => 'q',
                'label' => __('payment_voucher.filters.search'),
                'value' => (string) $filters['q'],
                'removeUrl' => $pvBuildRemoveUrl(['q']),
            ];
        }
        if (! empty($filters['status'])) {
            $pvActiveChips[] = [
                'key' => 'status',
                'label' => __('payment_voucher.filters.status'),
                'value' => StatusHelper::localFinanceLabel((string) $filters['status']),
                'removeUrl' => $pvBuildRemoveUrl(['status']),
            ];
        }
        if (! empty($filters['department'])) {
            $pvActiveChips[] = [
                'key' => 'department',
                'label' => __('payment_voucher.filters.department'),
                'value' => PaymentVoucher::departmentLabel((string) $filters['department']),
                'removeUrl' => $pvBuildRemoveUrl(['department']),
            ];
        }
        if (! empty($filters['payment_method'])) {
            $pvMethodLabel = $filters['payment_method'] === PaymentVoucher::METHOD_BANK
                ? __('payment_voucher.methods.bank')
                : __('payment_voucher.methods.kas');
            $pvActiveChips[] = [
                'key' => 'payment_method',
                'label' => __('payment_voucher.filters.method'),
                'value' => $pvMethodLabel,
                'removeUrl' => $pvBuildRemoveUrl(['payment_method']),
            ];
        }
        if (! empty($filters['payee_type'])) {
            $pvActiveChips[] = [
                'key' => 'payee_type',
                'label' => __('payment_voucher.filters.payee_type'),
                'value' => PaymentVoucher::payeeTypeLabel((string) $filters['payee_type']),
                'removeUrl' => $pvBuildRemoveUrl(['payee_type']),
            ];
        }
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $pvDateValue = ! empty($filters['date_from']) && ! empty($filters['date_to'])
                ? $filters['date_from'].' - '.$filters['date_to']
                : (string) ($filters['date_from'] ?: $filters['date_to']);
            $pvActiveChips[] = [
                'key' => 'date_range',
                'label' => __('payment_voucher.filters.date_range'),
                'value' => $pvDateValue,
                'removeUrl' => $pvBuildRemoveUrl(['date_from', 'date_to']),
            ];
        }
        $hasActiveFilters = count($pvActiveChips) > 0;
    @endphp

    @if(isset($errors) && $errors->any())
        <x-ui.alert tone="error">
            {{ $errors->first() }}
        </x-ui.alert>
    @endif

    <x-ui.filter-bar
        id="pvFilterForm"
        :action="$pvResetUrl"
        :aria-label="__('payment_voucher.title')"
        advanced-id="pvMoreFilters"
        :advanced-count="$pvAdvancedCount"
        :advanced-open="$pvAdvancedCount > 0"
        :has-active-filters="$hasActiveFilters"
        :reset-url="$pvResetUrl"
        :reset-label="__('payment_voucher.actions.reset_filters')"
        :submit-label="__('payment_voucher.actions.apply_filters')"
    >
        <x-slot:search>
            <label for="pv-filter-q" class="tw:sr-only">{{ __('payment_voucher.filters.search') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                id="pv-filter-q"
                type="search"
                name="q"
                value="{{ $filters['q'] ?? '' }}"
                placeholder="{{ __('payment_voucher.filters.search_placeholder') }}"
                maxlength="120"
                autocomplete="off"
                class="form-control form-control-sm ui-filter-bar__search-input"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="pv-filter-status" class="tw:sr-only">{{ __('payment_voucher.filters.status') }}</label>
            <select
                id="pv-filter-status"
                name="status"
                class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-auto tw:sm:min-w-[160px]"
            >
                <option value="">{{ __('payment_voucher.filters.all_statuses') }}</option>
                @foreach(PaymentVoucher::STATUSES as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                        {{ StatusHelper::localFinanceLabel($status) }}
                    </option>
                @endforeach
            </select>

            <label for="pv-filter-dept" class="tw:sr-only">{{ __('payment_voucher.filters.department') }}</label>
            <select
                id="pv-filter-dept"
                name="department"
                class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-auto tw:sm:min-w-[160px]"
            >
                <option value="">{{ __('payment_voucher.filters.all_departments') }}</option>
                @foreach(PaymentVoucher::DEPARTMENTS as $dept)
                    <option value="{{ $dept }}" @selected(($filters['department'] ?? '') === $dept)>
                        {{ PaymentVoucher::departmentLabel($dept) }}
                    </option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:advanced>
            <div>
                <label for="pv-filter-method" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">
                    {{ __('payment_voucher.filters.method') }}
                </label>
                <select
                    id="pv-filter-method"
                    name="payment_method"
                    class="form-select form-select-sm tw:w-full"
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

            <div>
                <label for="pv-filter-payee" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">
                    {{ __('payment_voucher.filters.payee_type') }}
                </label>
                <select
                    id="pv-filter-payee"
                    name="payee_type"
                    class="form-select form-select-sm tw:w-full"
                >
                    <option value="">{{ __('payment_voucher.filters.all_payee_types') }}</option>
                    @foreach(PaymentVoucher::PAYEE_TYPES as $payeeType)
                        <option value="{{ $payeeType }}" @selected(($filters['payee_type'] ?? '') === $payeeType)>
                            {{ PaymentVoucher::payeeTypeLabel($payeeType) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">
                    {{ __('payment_voucher.filters.date_range') }}
                </label>
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
        </x-slot:advanced>

        <x-slot:chips>
            <x-ui.filter-chips :items="$pvActiveChips" :clear-url="$pvResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

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
                            <x-ui.row-action
                                :href="route($routePrefix.'.print', $voucher)"
                                target="_blank"
                                tone="neutral"
                                icon="printer"
                                :label="__('payment_voucher.actions.print_pdf')"
                                :context="$voucher->voucher_number"
                            />
                            <x-ui.row-action
                                :href="route($routePrefix.'.show', $voucher)"
                                :context="$voucher->voucher_number"
                            />
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
                    <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-right text-end">{{ __('common.table.action') }}</th>
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
                        <td class="tw:text-right text-end">
                            <div class="tw:inline-flex tw:items-center tw:justify-end tw:gap-1.5">
                                <x-ui.row-action
                                    :href="route($routePrefix.'.print', $voucher)"
                                    target="_blank"
                                    tone="neutral"
                                    icon="printer"
                                    :label="__('payment_voucher.actions.print_pdf')"
                                    :context="$voucher->voucher_number"
                                    :title="__('payment_voucher.actions.print_pdf')"
                                />
                                <x-ui.row-action
                                    :href="route($routePrefix.'.show', $voucher)"
                                    :context="$voucher->voucher_number"
                                />
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

