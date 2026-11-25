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
    @php
        $consolidateResetUrl = route('purchasing.purchase-orders.consolidate-awards');
        $consolidateRemoveUrl = static function (array $dropKeys) use ($consolidateResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $consolidateResetUrl : $consolidateResetUrl.'?'.http_build_query($query);
        };

        $consolidateChips = [];
        if (request()->filled('search')) {
            $consolidateChips[] = [
                'key' => 'search',
                'label' => __('purchasing.copy.search'),
                'value' => (string) request('search'),
                'removeUrl' => $consolidateRemoveUrl(['search']),
            ];
        }
        if (request()->filled('supplier_id')) {
            $selectedConsSupplier = $suppliers->firstWhere('hash', request('supplier_id'));
            $consolidateChips[] = [
                'key' => 'supplier_id',
                'label' => __('purchasing.copy.supplier'),
                'value' => $selectedConsSupplier ? $selectedConsSupplier->name : (string) request('supplier_id'),
                'removeUrl' => $consolidateRemoveUrl(['supplier_id']),
            ];
        }
        if (request()->filled('currency')) {
            $consolidateChips[] = [
                'key' => 'currency',
                'label' => __('purchasing.copy.currency'),
                'value' => \App\Models\ExchangeRate::currencyLabel((string) request('currency')),
                'removeUrl' => $consolidateRemoveUrl(['currency']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="consolidateAwardsFilterForm"
        :action="$consolidateResetUrl"
        :has-active-filters="count($consolidateChips) > 0"
        :reset-url="$consolidateResetUrl"
        :reset-label="__('purchasing.copy.reset')"
        :submit-label="__('purchasing.copy.filter')"
    >
        <x-slot:search>
            <label for="consolidateSearchInput" class="tw:sr-only">{{ __('purchasing.copy.search_pr_or_material') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                id="consolidateSearchInput"
                type="search"
                name="search"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
                value="{{ request('search') }}"
                placeholder="{{ __('purchasing.copy.search_pr_or_material') }}"
                aria-label="{{ __('purchasing.copy.search_pr_or_material') }}"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="consolidateSupplierFilter" class="tw:sr-only">{{ __('purchasing.copy.supplier') }}</label>
            <select id="consolidateSupplierFilter" name="supplier_id" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-56" aria-label="{{ __('purchasing.copy.supplier') }}">
                <option value="">{{ __('purchasing.copy.all_suppliers') }}</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->hash }}" @selected(request('supplier_id') === $supplier->hash)>{{ $supplier->name }}</option>
                @endforeach
            </select>

            <label for="consolidateCurrencyFilter" class="tw:sr-only">{{ __('purchasing.copy.currency') }}</label>
            <select id="consolidateCurrencyFilter" name="currency" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-36" aria-label="{{ __('purchasing.copy.currency') }}">
                <option value="">{{ __('purchasing.copy.all_currencies') }}</option>
                @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                    <option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ \App\Models\ExchangeRate::currencyLabel($currency) }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips :items="$consolidateChips" :clear-url="$consolidateResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>
    <form id="awardConsolidationForm" method="POST" action="{{ route('purchasing.purchase-orders.consolidate-awards.store') }}">
        @csrf
        @unless($poGenerationEnabled)
            <x-ui.alert tone="warning" class="tw:mb-3">{{ __('procurement.po_generation.disabled_notice') }}</x-ui.alert>
        @endunless
        @error('award_ids')<x-ui.alert tone="error" class="tw:mb-3">{{ $message }}</x-ui.alert>@enderror
        @php
            $orderQtyEnabled = \App\Support\Procurement\ProcurementFeatures::orderedQuantityWriteEnabled();
        @endphp
        {{-- Q1 pre-warning only; the server guard is authoritative. --}}
        <x-ui.alert id="mixedLogisticsWarning" tone="warning" class="tw:mb-3 tw:hidden" role="status">{{ __('procurement.logistics.mixed_selection_warning') }}</x-ui.alert>
        <div class="ui-table-responsive">
            <table class="ui-table ui-table--hover tw:align-middle">
                <thead><tr><th scope="col">{{ __('purchasing.copy.select') }}</th><th scope="col">{{ __('purchasing.copy.pr_no') }}</th><th scope="col">{{ __('purchasing.copy.material') }}</th><th scope="col">{{ __('purchasing.copy.supplier') }}</th><th scope="col">{{ __('purchasing.copy.quotation') }}</th><th scope="col">{{ __('purchasing.copy.currency') }}</th><th scope="col" class="tw:text-end">{{ __('purchasing.copy.weight') }}</th><th scope="col" class="tw:text-end">{{ __('purchasing.copy.amount') }}</th><th scope="col">{{ __('procurement.logistics.port_of_discharge') }}</th><th scope="col">{{ __('procurement.logistics.incoterm') }}</th>@if($orderQtyEnabled)<th scope="col" style="min-width: 11rem;">{{ __('procurement.ordered_quantity.order_qty') }}</th>@endif</tr></thead>
                <tbody>
                    @forelse($awards as $award)
                        <tr>
                            <td><input type="checkbox" name="award_ids[]" value="{{ $award->id }}" aria-label="{{ __('purchasing.a11y.select_item', ['material' => $award->prItem->material_name]) }}" @checked(in_array($award->id, old('award_ids', []))) data-logistics="{{ $award->purchaseRequisition->port_of_discharge }}|{{ $award->purchaseRequisition->incoterm }}"></td>
                            <td>{{ $award->purchaseRequisition->pr_number }}</td>
                            <td>{{ $award->prItem->material_name }}</td>
                            <td>{{ $award->supplier->name }}</td>
                            <td><a href="{{ route('purchasing.quotations.show', $award->quotation) }}">{{ __('purchasing.copy.view_quotation') }}</a></td>
                            <td>{{ $award->quotation->currency }}</td>
                            <td class="tw:text-end">{{ \App\Support\NumberFormat::maxDecimals($award->quotationItem->offered_total_weight) }}</td>
                            <td class="tw:text-end">{{ $regionalFormatter->groupedDecimal($award->quotationItem->resolved_amount) }}</td>
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
                        <tr><td colspan="{{ $orderQtyEnabled ? 11 : 10 }}" class="tw:text-center">{{ __('purchasing.copy.no_eligible_selected_offers_match_these_filters') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="tw:text-on-surface-variant">{{ __('purchasing.copy.only_checked_items_on_this_page_will_be_included') }}</p>
        <div class="tw:mb-3" style="max-width: 320px">
            <x-ui.date-picker
                id="consolidationArrival"
                name="estimated_arrival"
                :label="__('purchasing.copy.estimated_arrival_944622')"
                :value="old('estimated_arrival', now()->addDays(14)->toDateString())"
                required
            />
        </div>
        <div class="tw:mb-3">
            <label for="consolidationNotes" class="ui-label">{{ __('purchasing.copy.notes') }}</label>
            <textarea id="consolidationNotes" name="notes" class="ui-textarea" maxlength="5000">{{ old('notes') }}</textarea>
        </div>
        @php
            $firstAwardPort = $awards->first()?->purchaseRequisition?->port_of_discharge;
            $defaultConsolidationPort = old('port_of_discharge', $firstAwardPort ?: (\App\Support\Procurement\LogisticsTerms::ports()[0] ?? 'TANJUNG_PRIOK'));
        @endphp
        <div class="tw:mb-3 tw:p-3 tw:border tw:border-outline-variant tw:rounded-ui-sm tw:bg-surface-container-low">
            <div class="tw:mb-2" style="max-width: 360px">
                <label for="consolidationPortOfDischarge" class="ui-label tw:font-semibold">
                    {{ __('purchasing.copy.confirm_port_of_discharge_label') }} <span class="tw:text-error">*</span>
                </label>
                <select name="port_of_discharge" id="consolidationPortOfDischarge" class="ui-select ui-select--sm @error('port_of_discharge') is-invalid @enderror" required>
                    @foreach(\App\Support\Procurement\LogisticsTerms::ports() as $portCode)
                        <option value="{{ $portCode }}" {{ $defaultConsolidationPort === $portCode ? 'selected' : '' }}>
                            {{ \App\Support\Procurement\LogisticsTerms::portLabel($portCode) }}
                        </option>
                    @endforeach
                </select>
                @error('port_of_discharge')<div class="ui-field-error">{{ $message }}</div>@enderror
            </div>
            <div class="ui-check-group">
                <input
                    type="checkbox"
                    name="confirm_port_of_discharge"
                    id="consolidationConfirmPort"
                    value="1"
                    class="ui-check @error('confirm_port_of_discharge') is-invalid @enderror"
                    {{ old('confirm_port_of_discharge') ? 'checked' : '' }}
                    required
                >
                <label for="consolidationConfirmPort" class="tw:text-ui-xs tw:font-semibold tw:cursor-pointer">
                    {{ __('purchasing.copy.confirm_port_of_discharge_checkbox') }}
                </label>
                @error('confirm_port_of_discharge')<div class="ui-field-error tw:block">{{ $message }}</div>@enderror
            </div>
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
        warning.classList.toggle('tw:hidden', values.size <= 1);
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
