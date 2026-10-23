@extends('layouts.app')
@section('title', __('purchasing.copy.consolidate_selected_items_adasi_portal'))
@section('page-title', __('purchasing.copy.consolidate_selected_items'))
@section('content')
<div class="tw:grid tw:gap-4">
    <x-ui.page-header :title="__('purchasing.copy.consolidate_selected_items')" :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.select_previously_chosen_item_offers_from_one_supplier_and_currency_to_combine_into_a_purchase_order')">
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.purchase-orders.index')" variant="outline">{{ __('purchasing.copy.purchase_orders') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
    <form method="GET" class="d-flex flex-wrap gap-2">
        <input name="search" class="form-control" style="max-width: 260px" value="{{ request('search') }}" placeholder="{{ __('purchasing.copy.search_pr_or_material') }}" aria-label="{{ __('purchasing.copy.search_pr_or_material') }}">
        <select name="supplier_id" class="form-select" style="max-width: 240px" aria-label="{{ __('purchasing.copy.supplier') }}">
            <option value="">{{ __('purchasing.copy.all_suppliers') }}</option>
            @foreach($suppliers as $supplier)
                <option value="{{ $supplier->hash }}" @selected(request('supplier_id') === $supplier->hash)>{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select name="currency" class="form-select" style="max-width: 140px" aria-label="{{ __('purchasing.copy.currency') }}">
            <option value="">{{ __('purchasing.copy.all_currencies') }}</option>
            @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                <option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ \App\Models\ExchangeRate::currencyLabel($currency) }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit" variant="outline">{{ __('purchasing.copy.filter') }}</x-ui.button>
    </form>
    <form id="awardConsolidationForm" method="POST" action="{{ route('purchasing.purchase-orders.consolidate-awards.store') }}">
        @csrf
        @unless($poGenerationEnabled)
            <x-ui.alert tone="warning" class="tw:mb-3">{{ __('procurement.po_generation.disabled_notice') }}</x-ui.alert>
        @endunless
        @error('award_ids')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @php($orderQtyEnabled = \App\Support\Procurement\ProcurementFeatures::orderedQuantityWriteEnabled())
        {{-- Q1 pre-warning only; the server guard is authoritative. --}}
        <x-ui.alert id="mixedLogisticsWarning" tone="warning" class="tw:mb-3 d-none" role="status">{{ __('procurement.logistics.mixed_selection_warning') }}</x-ui.alert>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>{{ __('purchasing.copy.select') }}</th><th>PR</th><th>{{ __('purchasing.copy.material') }}</th><th>{{ __('purchasing.copy.supplier') }}</th><th>{{ __('purchasing.copy.quotation') }}</th><th>{{ __('purchasing.copy.currency') }}</th><th class="text-end">{{ __('purchasing.copy.weight') }}</th><th class="text-end">{{ __('purchasing.copy.amount') }}</th><th>{{ __('procurement.logistics.port_of_discharge') }}</th><th>{{ __('procurement.logistics.incoterm') }}</th>@if($orderQtyEnabled)<th style="min-width: 11rem;">{{ __('procurement.ordered_quantity.order_qty') }}</th>@endif</tr></thead>
                <tbody>
                    @forelse($awards as $award)
                        <tr>
                            <td><input type="checkbox" name="award_ids[]" value="{{ $award->id }}" aria-label="{{ __('purchasing.a11y.select_item', ['material' => $award->prItem->material_name]) }}" @checked(in_array($award->id, old('award_ids', []))) data-logistics="{{ $award->purchaseRequisition->port_of_discharge }}|{{ $award->purchaseRequisition->incoterm }}"></td>
                            <td>{{ $award->purchaseRequisition->pr_number }}</td>
                            <td>{{ $award->prItem->material_name }}</td>
                            <td>{{ $award->supplier->name }}</td>
                            <td><a href="{{ route('purchasing.quotations.show', $award->quotation) }}">{{ __('purchasing.copy.view_quotation') }}</a></td>
                            <td>{{ $award->quotation->currency }}</td>
                            <td class="text-end">{{ \App\Support\NumberFormat::maxDecimals($award->quotationItem->offered_total_weight) }}</td>
                            <td class="text-end">{{ \App\Support\NumberFormat::maxDecimals($award->quotationItem->resolved_amount) }}</td>
                            <td>{{ \App\Support\Procurement\LogisticsTerms::portLabel($award->purchaseRequisition->port_of_discharge) }}</td>
                            <td>{{ \App\Support\Procurement\LogisticsTerms::incotermLabel($award->purchaseRequisition->incoterm) }}</td>
                            @if($orderQtyEnabled)
                                <td>
                                    @include('procurement._order_qty_control', [
                                        'prItemId' => $award->pr_item_id,
                                        'material' => $award->prItem->material_name,
                                        'requested' => $award->prItem->quantity_value,
                                        'offered' => (int) $award->quotationItem->fulfillment_quantity,
                                        'value' => $award->ordered_qty,
                                        'disabled' => false,
                                        'specDifferent' => $award->quotationItem->availability_comparison['specification']['code'] === 'different',
                                        'specAccepted' => $award->isSpecAcceptanceCurrent($award->quotationItem->specificationSnapshot()),
                                        'requestedSpec' => $award->prItem->dimension_label,
                                        'offeredSpec' => $award->quotationItem->available_dimension_label,
                                        'idSuffix' => 'Award'.$award->id,
                                    ])
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $orderQtyEnabled ? 11 : 10 }}" class="text-center">{{ __('purchasing.copy.no_eligible_selected_offers_match_these_filters') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-muted">{{ __('purchasing.copy.only_checked_items_on_this_page_will_be_included') }}</p>
        <div class="mb-3" style="max-width: 320px">
            <x-ui.date-picker
                id="consolidationArrival"
                name="estimated_arrival"
                :label="__('purchasing.copy.estimated_arrival_944622')"
                :value="old('estimated_arrival', now()->addDays(14)->toDateString())"
                required
            />
        </div>
        <div class="mb-3">
            <label for="consolidationNotes" class="form-label">{{ __('purchasing.copy.notes') }}</label>
            <textarea id="consolidationNotes" name="notes" class="form-control" maxlength="5000">{{ old('notes') }}</textarea>
        </div>
        <x-ui.button id="createConsolidatedPo" type="submit" data-no-auto-spinner :disabled="! $poGenerationEnabled"><span id="consolidationSpinner" class="ui-spinner" hidden aria-hidden="true"></span>{{ __('purchasing.copy.create_consolidated_po') }}</x-ui.button>
    </form>
    {{ $awards->links() }}
</div>
@endsection

@push('scripts')
<script>
// Q1 pre-warning: checked awards from PRs with different Port/Incoterms.
(() => {
    const form = document.getElementById('awardConsolidationForm');
    const warning = document.getElementById('mixedLogisticsWarning');
    if (!form || !warning) return;
    const refresh = () => {
        const values = new Set([...form.querySelectorAll('input[name="award_ids[]"]:checked')].map((box) => box.dataset.logistics));
        warning.classList.toggle('d-none', values.size <= 1);
    };
    form.addEventListener('change', (event) => { if (event.target.matches('input[name="award_ids[]"]')) refresh(); });
    refresh();
})();

document.getElementById('awardConsolidationForm')?.addEventListener('submit', () => {
    const button = document.getElementById('createConsolidatedPo');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    document.getElementById('consolidationSpinner').hidden = false;
});
</script>
@endpush
