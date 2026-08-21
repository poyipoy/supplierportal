@extends('layouts.app')
@section('title', 'Exchange Rates - ADASI Portal')
@section('page-title', 'Exchange Rates')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Exchange Rate History"
        description="Review every effective rate and add a dated record without overwriting prior history."
        eyebrow="Admin"
    />

    <div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] xl:tw-items-start">
        <x-ui.data-table
            title="Saved Rates"
            description="Showing {{ $rates->count() }} of {{ $rates->total() }} records{{ request('currency') ? ' for ' . request('currency') : '' }}."
        >
            <x-slot:filters>
                <div class="tw-flex tw-flex-wrap tw-gap-2" aria-label="Filter by currency">
                    <x-ui.button :href="route('admin.exchange-rates.index')" size="sm" :variant="request('currency') ? 'ghost' : 'secondary'">All ({{ $totalRates }})</x-ui.button>
                    @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                        <x-ui.button
                            :href="route('admin.exchange-rates.index', ['currency' => $currency])"
                            size="sm"
                            :variant="request('currency') === $currency ? 'secondary' : 'ghost'"
                        >{{ $currency }} ({{ $currencyCounts[$currency] ?? 0 }})</x-ui.button>
                    @endforeach
                </div>
            </x-slot:filters>

            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light">
                    <tr><th scope="col">Currency</th><th scope="col" class="text-end">Value to IDR</th><th scope="col">Valid From</th><th scope="col">Updated By</th><th scope="col">Update Time</th></tr>
                </thead>
                <tbody>
                    @forelse($rates as $rate)
                        <tr>
                            <td><x-ui.status-chip tone="neutral">{{ $rate->currency }}</x-ui.status-chip></td>
                            <td class="ui-tabular-nums text-end fw-medium">Rp {{ number_format($rate->rate_to_idr, 2, ',', '.') }}</td>
                            <td>{{ \Carbon\Carbon::parse($rate->valid_from)->format('d M Y') }}</td>
                            <td>{{ $rate->creator->name ?? '-' }}</td>
                            <td class="text-muted small">{{ $rate->created_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="badge-dollar-sign" title="No exchange rates found" description="Add the first effective rate using the form." /></td></tr>
                    @endforelse
                </tbody>
            </table>

            @if($rates->hasPages())
                <x-slot:pagination>
                    <x-ui.pagination>
                        <span>Page {{ $rates->currentPage() }} of {{ $rates->lastPage() }}</span>
                        <span>{{ $rates->onEachSide(1)->links('pagination::bootstrap-5') }}</span>
                    </x-ui.pagination>
                </x-slot:pagination>
            @endif
        </x-ui.data-table>

        <x-ui.card title="Add Effective Rate" description="A new row is appended to history; existing rates are preserved." variant="tonal">
            <form action="{{ route('admin.exchange-rates.store') }}" method="POST" class="tw-grid tw-gap-4">
                @csrf
                <x-ui.select name="currency" label="Currency" :options="\App\Models\ExchangeRate::CURRENCY_LABELS" :value="old('currency')" required />
                <x-ui.input name="rate_to_idr" type="number" label="Value to Rupiah (IDR)" step="0.01" min="1" placeholder="Example: 15500" required />
                <x-ui.input name="valid_from" type="date" label="Valid From" :value="old('valid_from', date('Y-m-d'))" required />
                <x-ui.button type="submit" class="tw-w-full">
                    <x-slot:leading><x-ui.icon name="save" /></x-slot:leading>
                    Save Rate History
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
