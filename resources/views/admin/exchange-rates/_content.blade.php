<div data-exchange-rates-restored="{{ $activeTab === 'annual' && $annualHasErrors ? 'true' : 'false' }}">
@if($activeTab === 'annual')
        @include('admin.exchange-rates._annual_matrix', ['annualManagement' => true])
    @else
    <section class="tw:border-y tw:border-outline tw:bg-surface-container" aria-labelledby="rate-summary-title">
        <h2 id="rate-summary-title" class="tw:sr-only">{{ __('admin.copy.rate_history_summary') }}</h2>
        <dl class="tw:m-0 tw:grid tw:grid-cols-2 tw:lg:grid-cols-5">
            <div class="tw:border-b tw:border-r tw:border-outline-variant tw:p-4 tw:lg:border-b-0"><dt class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wide tw:text-on-surface-variant">{{ __('admin.copy.all_records') }}</dt><dd class="ui-tabular-nums tw:m-0 tw:mt-1 tw:text-xl tw:font-semibold">{{ number_format($totalRates) }}</dd></div>
            @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                <div class="tw:border-b tw:border-r tw:border-outline-variant tw:p-4 tw:last:border-r-0 tw:lg:border-b-0"><dt class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wide tw:text-on-surface-variant">{{ __('common.final_review.currency_records', ['currency' => $currency]) }}</dt><dd class="ui-tabular-nums tw:m-0 tw:mt-1 tw:text-xl tw:font-semibold">{{ number_format($currencyCounts[$currency] ?? 0) }}</dd></div>
            @endforeach
        </dl>
    </section>

    <x-ui.toolbar aria-label="{{ __('admin.copy.exchange_rate_filters') }}">
        <x-slot:filters>
            <form method="GET" action="{{ route('admin.exchange-rates.index') }}" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" data-server-tabs-form>
                <input type="hidden" name="tab" value="effective">
                <label class="tw:grid tw:gap-1 tw:text-ui-xs tw:font-medium" for="currencyFilter">{{ __('admin.copy.currency') }}
                    <select name="currency" id="currencyFilter" class="form-select form-select-sm tw:min-w-40"><option value="">{{ __('admin.copy.all_currencies') }}</option>@foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)<option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ \App\Models\ExchangeRate::currencyLabel($currency) }}</option>@endforeach</select>
                </label>
                <x-ui.button type="submit" variant="primary" size="sm">{{ __('admin.copy.apply_filter') }}</x-ui.button>
                @if(request('currency'))<x-ui.button :href="route('admin.exchange-rates.index', ['tab' => 'effective'])" data-exchange-rates-link variant="ghost" size="sm">{{ __('admin.copy.clear') }}</x-ui.button>@endif
            </form>
        </x-slot:filters>
    </x-ui.toolbar>

    <x-ui.data-table
        :title="__('admin.copy.effective_rate_history')"
        :description="request('currency')
            ? __('admin.copy.exchange_rates_count_for_currency', ['count' => $rates->count(), 'total' => $rates->total(), 'currency' => \App\Models\ExchangeRate::currencyLabel(request('currency'))])
            : __('admin.copy.exchange_rates_count', ['count' => $rates->count(), 'total' => $rates->total()])"
    >
        <div class="ui-data-table__scroll tw:overflow-x-auto">
            <table class="table table-hover align-middle tw:m-0 tw:w-full tw:text-ui-sm">
                <thead class="table-light"><tr><th scope="col">{{ __('admin.copy.currency') }}</th><th scope="col" class="text-end">{{ __('admin.copy.rate_to_idr') }}</th><th scope="col">{{ __('admin.copy.effective_date') }}</th><th scope="col">{{ __('admin.copy.recorded_by') }}</th><th scope="col">{{ __('admin.copy.recorded_at') }}</th></tr></thead>
                <tbody>
                    @forelse($rates as $rate)
                        <tr><td class="tw:font-mono tw:font-semibold tw:text-primary">{{ $rate->currency }}</td><td class="ui-tabular-nums text-end tw:font-semibold">Rp {{ number_format($rate->rate_to_idr, 2, ',', '.') }}</td><td class="text-nowrap">{{ $regionalFormatter->date(\Carbon\Carbon::parse($rate->valid_from), 'human') }}</td><td>{{ $rate->creator->name ?? '-' }}</td><td class="text-nowrap tw:text-ui-xs tw:text-on-surface-variant">{{ $regionalFormatter->timestamp($rate->created_at, 'datetime_comma') }}</td></tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="badge-dollar-sign" :title="__('admin.copy.no_exchange_rates_found')" :description="__('admin.copy.add_an_effective_rate_or_clear_the_current_filter')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rates->hasPages())
            <x-slot:pagination>
                <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:text-ui-sm">
                    <span>{{ __('common.final_review.page', ['page' => $rates->currentPage(), 'last' => $rates->lastPage()]) }}</span>
                    <span>{{ $rates->onEachSide(1)->links('pagination::bootstrap-5') }}</span>
                </div>
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
    @endif
</div>
