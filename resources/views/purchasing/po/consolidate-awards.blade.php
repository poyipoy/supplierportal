@extends('layouts.app')
@section('title', 'Consolidate Selected Items - ADASI Portal')
@section('page-title', 'Consolidate Selected Items')
@section('content')
<div class="tw-grid tw-gap-4">
    <x-ui.page-header title="Consolidate Selected Items" eyebrow="Purchasing"
        description="Select previously chosen item offers from one supplier and currency to combine into a Purchase Order. Selections may come from multiple requisitions.">
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.purchase-orders.index')" variant="outline">Purchase Orders</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    <form method="GET" class="d-flex flex-wrap gap-2">
        <input name="search" class="form-control" style="max-width: 260px" value="{{ request('search') }}" placeholder="Search PR or material" aria-label="Search PR or material">
        <select name="supplier_id" class="form-select" style="max-width: 240px" aria-label="Supplier">
            <option value="">All suppliers</option>
            @foreach($suppliers as $supplier)
                <option value="{{ $supplier->hash }}" @selected(request('supplier_id') === $supplier->hash)>{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select name="currency" class="form-select" style="max-width: 140px" aria-label="Currency">
            <option value="">All currencies</option>
            @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                <option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit" variant="outline">Filter</x-ui.button>
    </form>
    <form id="awardConsolidationForm" method="POST" action="{{ route('purchasing.purchase-orders.consolidate-awards.store') }}">
        @csrf
        @error('award_ids')<div class="alert alert-danger">{{ $message }}</div>@enderror
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Select</th><th>PR</th><th>Material</th><th>Supplier</th><th>Quotation</th><th>Currency</th><th class="text-end">Weight</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                    @forelse($awards as $award)
                        <tr>
                            <td><input type="checkbox" name="award_ids[]" value="{{ $award->id }}" aria-label="Select {{ $award->prItem->material_name }}" @checked(in_array($award->id, old('award_ids', [])))></td>
                            <td>{{ $award->purchaseRequisition->pr_number }}</td>
                            <td>{{ $award->prItem->material_name }}</td>
                            <td>{{ $award->supplier->name }}</td>
                            <td><a href="{{ route('purchasing.quotations.show', $award->quotation) }}">View quotation</a></td>
                            <td>{{ $award->quotation->currency }}</td>
                            <td class="text-end">{{ \App\Support\NumberFormat::maxDecimals($award->quotationItem->offered_total_weight) }}</td>
                            <td class="text-end">{{ \App\Support\NumberFormat::maxDecimals($award->quotationItem->resolved_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center">No eligible selected offers match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-muted">Only checked items on this page will be included.</p>
        <div class="mb-3" style="max-width: 320px">
            <x-ui.date-picker
                id="consolidationArrival"
                name="estimated_arrival"
                label="Estimated arrival"
                :value="old('estimated_arrival', now()->addDays(14)->toDateString())"
                required
            />
        </div>
        <div class="mb-3">
            <label for="consolidationNotes" class="form-label">Notes</label>
            <textarea id="consolidationNotes" name="notes" class="form-control" maxlength="5000">{{ old('notes') }}</textarea>
        </div>
        <x-ui.button id="createConsolidatedPo" type="submit" data-no-auto-spinner><span id="consolidationSpinner" class="ui-spinner" hidden aria-hidden="true"></span>Create consolidated PO</x-ui.button>
    </form>
    {{ $awards->links() }}
</div>
@endsection

@push('scripts')
<script>
document.getElementById('awardConsolidationForm')?.addEventListener('submit', () => {
    const button = document.getElementById('createConsolidatedPo');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    document.getElementById('consolidationSpinner').hidden = false;
});
</script>
@endpush
