{{-- Server-calculated footer totals (PUR-02). Calculated by the server without saving and verified again on save. --}}
@php
    $formatter = app(\App\Services\RegionalDisplayFormatter::class);
    $sourceItems = old('items') ?? (isset($pr) ? $pr->items?->toArray() : null) ?? [];
    $initialQty = 0;
    $initialKg = \App\Support\Money::normalize(0, 4);
    foreach ($sourceItems as $rawItem) {
        $itemArr = is_array($rawItem) ? $rawItem : (method_exists($rawItem, 'toArray') ? $rawItem->toArray() : []);
        $rawQty = is_int($itemArr['quantity'] ?? null) ? (string) $itemArr['quantity'] : trim((string) ($itemArr['quantity'] ?? ''));
        $qty = preg_match('/^[1-9]\d*$/', $rawQty) === 1 ? (int) $rawQty : null;
        if ($qty !== null) {
            $initialQty += $qty;
        }
        $uKg = is_numeric($itemArr['weight_needed'] ?? null) ? (float) $itemArr['weight_needed'] : 0.0;
        if ($uKg > 0 && $qty !== null) {
            $initialKg = \App\Support\Money::add($initialKg, \App\Support\Money::multiply($uKg, $qty, 4), 4);
        }
    }
    $initialQtyDisplay = $formatter->number(number_format($initialQty, 0, '.', ','), 'international');
    $initialKgDisplay = $formatter->money($initialKg, 4, 'international');
@endphp
<tfoot
    id="prFormTotals"
    class="table-light"
    data-totals-url="{{ route('purchasing.material-calculations.totals') }}"
    data-calculating-text="{{ __('procurement.totals.calculating') }}"
    data-error-text="{{ __('procurement.totals.sync_error') }}"
>
    <tr>
        <th scope="row" colspan="4" class="text-end fw-semibold text-nowrap">
            {{ __('procurement.totals.total') }}
        </th>
        <td class="text-center fw-bold ui-tabular-nums text-nowrap">
            <span class="tw:sr-only">{{ __('procurement.totals.total_qty') }}:</span>
            <output id="prFormTotalQty" aria-live="polite">{{ $initialQtyDisplay }}</output>
        </td>
        <td colspan="{{ count(\App\Models\PrItem::FIXED_DIMENSION_ORDER) + 1 }}" class="text-end fw-semibold tw:text-on-surface-variant text-nowrap">{{ __('procurement.totals.total_kg') }}</td>
        <td class="text-end fw-bold ui-tabular-nums text-nowrap">
            <output id="prFormTotalKg" aria-live="polite">{{ $initialKgDisplay }}</output>
        </td>
        <td colspan="2"></td>
    </tr>
</tfoot>
