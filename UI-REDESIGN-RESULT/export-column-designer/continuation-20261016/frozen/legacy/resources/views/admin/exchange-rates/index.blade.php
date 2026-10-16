@extends('layouts.app')
@section('title', __('admin.copy.exchange_rates_adasi_portal'))
@section('page-title', __('admin.copy.exchange_rates'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="__('admin.copy.exchange_rates')" :description="__('admin.copy.maintain_append_only_effective_currency_values_used_by_procurement_calculations')" :eyebrow="__('admin.copy.admin_finance')">
        <x-slot:actions><x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#rateModal"><x-ui.icon name="plus" /> {{ __('admin.copy.add_effective_rate') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <section class="tw-border-y tw-border-outline tw-bg-surface-container" aria-labelledby="rate-summary-title">
        <h2 id="rate-summary-title" class="tw-sr-only">{{ __('admin.copy.rate_history_summary') }}</h2>
        <dl class="tw-m-0 tw-grid tw-grid-cols-2 lg:tw-grid-cols-5">
            <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 lg:tw-border-b-0"><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.all_records') }}</dt><dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($totalRates) }}</dd></div>
            @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 last:tw-border-r-0 lg:tw-border-b-0"><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('common.final_review.currency_records', ['currency' => $currency]) }}</dt><dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($currencyCounts[$currency] ?? 0) }}</dd></div>
            @endforeach
        </dl>
    </section>

    <x-ui.toolbar aria-label="{{ __('admin.copy.exchange_rate_filters') }}">
        <x-slot:filters>
            <form method="GET" action="{{ route('admin.exchange-rates.index') }}" class="tw-flex tw-flex-wrap tw-items-end tw-gap-2">
                <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="currencyFilter">{{ __('admin.copy.currency') }}
                    <select name="currency" id="currencyFilter" class="form-select form-select-sm tw-min-w-40"><option value="">{{ __('admin.copy.all_currencies') }}</option>@foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)<option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ \App\Models\ExchangeRate::currencyLabel($currency) }}</option>@endforeach</select>
                </label>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __('admin.copy.apply_filter') }}</x-ui.button>
                @if(request('currency'))<x-ui.button :href="route('admin.exchange-rates.index')" variant="ghost" size="sm">{{ __('admin.copy.clear') }}</x-ui.button>@endif
            </form>
        </x-slot:filters>
    </x-ui.toolbar>

    <x-ui.data-table
        :title="__('admin.copy.effective_rate_history')"
        :description="request('currency')
            ? __('admin.copy.exchange_rates_count_for_currency', ['count' => $rates->count(), 'total' => $rates->total(), 'currency' => \App\Models\ExchangeRate::currencyLabel(request('currency'))])
            : __('admin.copy.exchange_rates_count', ['count' => $rates->count(), 'total' => $rates->total()])"
    >
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light"><tr><th scope="col">{{ __('admin.copy.currency') }}</th><th scope="col" class="text-end">{{ __('admin.copy.rate_to_idr') }}</th><th scope="col">{{ __('admin.copy.effective_date') }}</th><th scope="col">{{ __('admin.copy.recorded_by') }}</th><th scope="col">{{ __('admin.copy.recorded_at') }}</th></tr></thead>
                <tbody>
                    @forelse($rates as $rate)
                        <tr><td class="tw-font-mono tw-font-semibold tw-text-primary">{{ $rate->currency }}</td><td class="ui-tabular-nums text-end tw-font-semibold">Rp {{ number_format($rate->rate_to_idr, 2, ',', '.') }}</td><td class="text-nowrap">{{ $regionalFormatter->date(\Carbon\Carbon::parse($rate->valid_from), 'human') }}</td><td>{{ $rate->creator->name ?? '-' }}</td><td class="text-nowrap tw-text-ui-xs tw-text-on-surface-variant">{{ $regionalFormatter->timestamp($rate->created_at, 'datetime_comma') }}</td></tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="badge-dollar-sign" :title="__('admin.copy.no_exchange_rates_found')" :description="__('admin.copy.add_an_effective_rate_or_clear_the_current_filter')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rates->hasPages())
            <x-slot:pagination>
                <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-text-ui-sm">
                    <span>{{ __('common.final_review.page', ['page' => $rates->currentPage(), 'last' => $rates->lastPage()]) }}</span>
                    <span>{{ $rates->onEachSide(1)->links('pagination::bootstrap-5') }}</span>
                </div>
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>

<div class="modal fade" id="rateModal" tabindex="-1" aria-labelledby="rateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="{{ route('admin.exchange-rates.store') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><div><h2 class="modal-title fs-6 fw-bold" id="rateModalTitle">{{ __('admin.copy.add_effective_rate') }}</h2><p class="mb-0 mt-1 small text-muted">{{ __('admin.copy.a_new_record_is_appended_existing_values_remain_unchanged') }}</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('admin.copy.close') }}"></button></div>
            <div class="modal-body tw-grid tw-gap-4"><x-ui.select name="currency" :label="__('admin.copy.currency')" :options="\App\Models\ExchangeRate::currencyOptions()" :value="old('currency')" required /><x-ui.input name="rate_to_idr" type="number" :label="__('admin.copy.rate_to_idr')" step="0.01" min="1" :placeholder="__('admin.copy.example_15500')" required /><x-ui.date-picker name="valid_from" :label="__('admin.copy.effective_date')" :value="old('valid_from', date('Y-m-d'))" required /></div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('admin.copy.cancel') }}</x-ui.button><x-ui.button type="submit"><x-ui.icon name="save" /> {{ __('admin.copy.save_rate') }}</x-ui.button></div>
        </form>
    </div>
</div>
@endsection

@if($errors->any())
    @push('scripts')<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('rateModal')).show());</script>@endpush
@endif
