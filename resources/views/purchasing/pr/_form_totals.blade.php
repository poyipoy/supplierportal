{{-- Live footer totals (decision 06, PUR-02). Preview only: the server recalculates on save. --}}
<tfoot id="prFormTotals" class="table-light">
    <tr>
        <th scope="row" colspan="4" class="text-end">
            {{ __('procurement.totals.total') }}
            <span class="tw:ml-1 tw:font-normal tw:text-on-surface-variant">({{ __('procurement.totals.preview_hint') }})</span>
        </th>
        <td class="text-center fw-bold ui-tabular-nums">
            <span class="tw:sr-only">{{ __('procurement.totals.total_qty') }}:</span>
            <output id="prFormTotalQty" aria-live="polite">0</output>
        </td>
        <td colspan="{{ count(\App\Models\PrItem::FIXED_DIMENSION_ORDER) + 1 }}" class="text-end tw:text-on-surface-variant">{{ __('procurement.totals.total_kg') }}</td>
        <td class="text-end fw-bold ui-tabular-nums">
            <output id="prFormTotalKg" aria-live="polite">0</output>
        </td>
        <td colspan="2"></td>
    </tr>
</tfoot>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('itemsBody');
    const qtyOut = document.getElementById('prFormTotalQty');
    const kgOut = document.getElementById('prFormTotalKg');
    const totals = window.AdasiPrTotals;
    if (!body || !qtyOut || !kgOut || !totals) return;

    const locale = document.documentElement.lang || undefined;
    const qtyFormat = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });
    const kgFormat = new Intl.NumberFormat(locale, { maximumFractionDigits: 4 });
    // Exact 4 dp text from AdasiPrTotals; Intl only adds locale separators for display.
    const kgText = (scaled) => kgFormat.format(Number(totals.toDecimalString(scaled)));

    const recalc = () => {
        const rows = [];
        body.querySelectorAll('tr').forEach((row) => {
            const quantity = row.querySelector('.material-quantity')?.value;
            const unitKg = row.querySelector('.weight-unit-display')?.value;
            rows.push({ quantity, unitKg });
            const cell = row.querySelector('[data-pr-row-total-kg]');
            if (cell) {
                const rowKg = totals.rowTotal(quantity, unitKg);
                const text = rowKg === null ? '—' : kgText(rowKg);
                // The cell sits inside the observed body: rewriting equal text would loop the observer.
                if (cell.textContent !== text) cell.textContent = text;
            }
        });
        const grand = totals.grandTotals(rows);
        qtyOut.textContent = qtyFormat.format(grand.quantity);
        kgOut.textContent = kgText(grand.weight);
    };

    body.addEventListener('input', recalc);
    body.addEventListener('change', recalc);
    // Programmatic .val() writes fire no input event, so writers dispatch this explicitly.
    document.addEventListener('adasi:pr-items-changed', recalc);
    new MutationObserver(recalc).observe(body, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
    recalc();
});
</script>
@endpush
