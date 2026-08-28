@extends('layouts.app')
@section('title', 'Exchange Rates - ADASI Portal')
@section('page-title', 'Exchange Rates')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Exchange Rates" description="Maintain append-only effective currency values used by procurement calculations." eyebrow="Admin Finance">
        <x-slot:actions><x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#rateModal"><x-ui.icon name="plus" /> Add Effective Rate</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <section class="tw-border-y tw-border-outline tw-bg-surface-container" aria-labelledby="rate-summary-title">
        <h2 id="rate-summary-title" class="tw-sr-only">Rate history summary</h2>
        <dl class="tw-m-0 tw-grid tw-grid-cols-2 lg:tw-grid-cols-5">
            <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 lg:tw-border-b-0"><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">All Records</dt><dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($totalRates) }}</dd></div>
            @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 last:tw-border-r-0 lg:tw-border-b-0"><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ $currency }} Records</dt><dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($currencyCounts[$currency] ?? 0) }}</dd></div>
            @endforeach
        </dl>
    </section>

    <x-ui.toolbar aria-label="Exchange rate filters">
        <x-slot:filters>
            <form method="GET" action="{{ route('admin.exchange-rates.index') }}" class="tw-flex tw-flex-wrap tw-items-end tw-gap-2">
                <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="currencyFilter">Currency
                    <select name="currency" id="currencyFilter" class="form-select form-select-sm tw-min-w-40"><option value="">All currencies</option>@foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)<option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ $currency }}</option>@endforeach</select>
                </label>
                <x-ui.button type="submit" variant="secondary" size="sm">Apply Filter</x-ui.button>
                @if(request('currency'))<x-ui.button :href="route('admin.exchange-rates.index')" variant="ghost" size="sm">Clear</x-ui.button>@endif
            </form>
        </x-slot:filters>
    </x-ui.toolbar>

    <x-ui.data-table title="Effective Rate History" description="Showing {{ $rates->count() }} of {{ $rates->total() }} records{{ request('currency') ? ' for ' . request('currency') : '' }}.">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light"><tr><th scope="col">Currency</th><th scope="col" class="text-end">Rate to IDR</th><th scope="col">Effective Date</th><th scope="col">Recorded By</th><th scope="col">Recorded At</th></tr></thead>
                <tbody>
                    @forelse($rates as $rate)
                        <tr><td class="tw-font-mono tw-font-semibold tw-text-primary">{{ $rate->currency }}</td><td class="ui-tabular-nums text-end tw-font-semibold">Rp {{ number_format($rate->rate_to_idr, 2, ',', '.') }}</td><td class="text-nowrap">{{ \Carbon\Carbon::parse($rate->valid_from)->format('d M Y') }}</td><td>{{ $rate->creator->name ?? '-' }}</td><td class="text-nowrap tw-text-ui-xs tw-text-on-surface-variant">{{ $rate->created_at->format('d M Y, H:i') }}</td></tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="badge-dollar-sign" title="No exchange rates found" description="Add an effective rate or clear the current filter." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rates->hasPages())
            <x-slot:pagination>
                <x-ui.pagination>
                    <span>Page {{ $rates->currentPage() }} of {{ $rates->lastPage() }}</span>
                    <span>{{ $rates->onEachSide(1)->links('pagination::bootstrap-5') }}</span>
                </x-ui.pagination>
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>

<div class="modal fade" id="rateModal" tabindex="-1" aria-labelledby="rateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="{{ route('admin.exchange-rates.store') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><div><h2 class="modal-title fs-6 fw-bold" id="rateModalTitle">Add Effective Rate</h2><p class="mb-0 mt-1 small text-muted">A new record is appended; existing values remain unchanged.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body tw-grid tw-gap-4"><x-ui.select name="currency" label="Currency" :options="\App\Models\ExchangeRate::CURRENCY_LABELS" :value="old('currency')" required /><x-ui.input name="rate_to_idr" type="number" label="Rate to IDR" step="0.01" min="1" placeholder="Example: 15500" required /><x-ui.date-picker name="valid_from" label="Effective Date" :value="old('valid_from', date('Y-m-d'))" required /></div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button><x-ui.button type="submit"><x-ui.icon name="save" /> Save Rate</x-ui.button></div>
        </form>
    </div>
</div>
@endsection

@if($errors->any())
    @push('scripts')<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('rateModal')).show());</script>@endpush
@endif
