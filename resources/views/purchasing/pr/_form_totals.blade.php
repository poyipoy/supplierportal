{{-- Live footer totals (decision 06). Preview only: the server recomputes on save. --}}
<tfoot id="prFormTotals" class="table-light">
    <tr>
        <th scope="row" colspan="4" class="text-end">
            {{ __('procurement.totals.total') }}
            <span class="tw-ml-1 tw-font-normal tw-text-on-surface-variant">({{ __('procurement.totals.preview_hint') }})</span>
        </th>
        <td class="text-center fw-bold ui-tabular-nums">
            <span class="tw-sr-only">{{ __('procurement.totals.total_qty') }}:</span>
            <output id="prFormTotalQty" aria-live="polite">0</output>
        </td>
        <td colspan="{{ count(\App\Models\PrItem::FIXED_DIMENSION_ORDER) }}" class="text-end tw-text-on-surface-variant">{{ __('procurement.totals.total_kg') }}</td>
        <td class="text-end fw-bold ui-tabular-nums">
            <output id="prFormTotalKg" aria-live="polite">0</output>
        </td>
        <td colspan="2"></td>
    </tr>
</tfoot>

@push('scripts')
<script>
(() => {
    const body = document.getElementById('itemsBody');
    const qtyOut = document.getElementById('prFormTotalQty');
    const kgOut = document.getElementById('prFormTotalKg');
    if (!body || !qtyOut || !kgOut) return;

    const locale = document.documentElement.lang || undefined;
    const qtyFormat = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });
    const kgFormat = new Intl.NumberFormat(locale, { maximumFractionDigits: 4 });
    const number = (value) => {
        const parsed = parseFloat(String(value ?? '').replace(',', '.'));
        return Number.isFinite(parsed) ? parsed : 0;
    };

    const recalc = () => {
        let qty = 0;
        let kg = 0;
        body.querySelectorAll('tr').forEach((row) => {
            const rowQty = Math.max(0, Math.trunc(number(row.querySelector('.material-quantity')?.value)));
            qty += rowQty;
            kg += rowQty * number(row.querySelector('.weight-unit-display')?.value);
        });
        qtyOut.textContent = qtyFormat.format(qty);
        kgOut.textContent = kgFormat.format(Math.round(kg * 10000) / 10000);
    };

    body.addEventListener('input', recalc);
    body.addEventListener('change', recalc);
    new MutationObserver(recalc).observe(body, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
    recalc();
})();
</script>
@endpush
