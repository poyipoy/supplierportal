{{--
    Read-only quotation detail (Decision 14): the same paired Requested / Offer
    structure as the Supplier entry form, without any input. Shared by the
    Supplier quotation page and the Purchasing quotation detail.

    @param \App\Models\Quotation $quotation  items.prItem + items.attachments + exchange_rate loaded
--}}
@php
    $dimensionOrder = \App\Models\PrItem::FIXED_DIMENSION_ORDER;
    $scalarDimensions = array_values(array_filter($dimensionOrder, static fn (string $field): bool => $field !== 'length'));
    $rate = $quotation->exchange_rate ? (string) $quotation->exchange_rate->rate_to_idr : null;
    $isIdr = $quotation->currency === 'IDR';
    $showMtc = $quotation->items->contains(fn ($item) => $item->attachments->isNotEmpty());
    $fmt = fn ($value, int $decimals = 2) => $value === null
        ? '—'
        : $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, $decimals), 'decimal');

    // Server-authoritative totals (Decision 06), BCMath like the stored amounts.
    $totals = ['req_qty' => 0, 'off_qty' => 0, 'req_kg' => '0', 'off_kg' => '0', 'req_amount' => '0', 'off_amount' => '0'];
    foreach ($quotation->items as $line) {
        $totals['req_qty'] += (int) ($line->prItem?->quantity_value ?? 0);
        $totals['req_kg'] = bcadd($totals['req_kg'], (string) ($line->requested_total_weight ?? 0), 4);
        if ($line->is_available) {
            $totals['off_qty'] += (int) ($line->available_qty ?? 0);
            $totals['off_kg'] = bcadd($totals['off_kg'], (string) ($line->offered_total_weight ?? 0), 4);
            $totals['req_amount'] = bcadd($totals['req_amount'], (string) ($line->requested_amount ?? 0), 4);
            $totals['off_amount'] = bcadd($totals['off_amount'], (string) ($line->resolved_amount ?? 0), 4);
        }
    }
    // Transactional IDR (Q4C): identity for IDR, snapshot otherwise, never 1 or 0.
    $toIdr = fn ($amount) => $isIdr ? (string) $amount : ($rate !== null ? bcmul((string) $amount, $rate, 4) : null);
    $totalIdr = $toIdr($totals['off_amount']);
    $qtyChip = static function (array $quantity): array {
        $delta = $quantity['delta'] ?? null;

        return match ($quantity['code']) {
            'surplus' => ['ui-status-chip--info', __('procurement.quotation.qty_surplus', ['delta' => $delta])],
            'shortage' => ['ui-status-chip--warning', __('procurement.quotation.qty_shortage', ['delta' => abs((int) $delta)])],
            'match' => ['ui-status-chip--success', $quantity['label']],
            default => ['ui-status-chip--neutral', $quantity['label']],
        };
    };
    $specTone = static fn (string $code): string => match ($code) {
        'different' => 'ui-status-chip--warning',
        'exact', 'within_range' => 'ui-status-chip--success',
        'not_available' => 'ui-status-chip--error',
        default => 'ui-status-chip--neutral',
    };
@endphp

<x-ui.data-table
    :title="__('procurement.quotation.detail_title')"
    :description="__('procurement.quotation.detail_description', ['currency' => $quotation->currency])"
    sticky
    :scroll-label="__('procurement.quotation.detail_title')"
    class="ui-quotation-readonly"
    data-quotation-readonly
>
    <table class="table table-bordered align-middle mb-0 tw-text-ui-xs" style="min-width: 1500px;">
        <caption class="visually-hidden">{{ __('procurement.quotation.table_caption') }}</caption>
        <thead class="table-light text-center align-middle">
            <tr>
                <th scope="col" class="ui-sticky-col" style="--ui-sticky-col-left: 0; width: 2.5rem;">#</th>
                <th scope="col" class="ui-sticky-col text-start" style="--ui-sticky-col-left: 2.5rem; min-width: 12rem;">{{ __('procurement.quotation.material') }}</th>
                <th scope="col">{{ __('procurement.quotation.row_type') }}</th>
                <th scope="col">{{ __('procurement.quotation.qty') }}</th>
                @foreach($dimensionOrder as $dimensionField)
                    <th scope="col">{{ \App\Models\PrItem::dimensionLabel($dimensionField) }}</th>
                @endforeach
                <th scope="col" class="text-end">{{ __('procurement.quotation.kg_per_unit') }}</th>
                <th scope="col" class="text-end">{{ __('procurement.quotation.total_kg') }}</th>
                <th scope="col" class="text-end">{{ __('procurement.quotation.price_per_kg', ['currency' => $quotation->currency]) }}</th>
                <th scope="col" class="text-end">{{ __('procurement.quotation.amount', ['currency' => $quotation->currency]) }}</th>
                <th scope="col" class="text-end">{{ __('procurement.quotation.est_idr') }}</th>
                <th scope="col" class="text-start">{{ __('procurement.quotation.notes') }}</th>
                <th scope="col">{{ __('procurement.quotation.status') }}</th>
                @if($showMtc)<th scope="col">MTC</th>@endif
            </tr>
        </thead>
        @foreach($quotation->items as $index => $item)
            @php
                $prItem = $item->prItem;
                $relevant = \App\Models\PrItem::relevantDimensionFields($prItem?->shape);
                $availability = $item->availability_comparison;
                $amount = $item->is_available ? $item->resolved_amount : null;
                $amountIdr = $amount === null ? null : $toIdr($amount);
                [$qtyTone, $qtyLabel] = $qtyChip($availability['quantity']);
                $offerLength = $item->available_length_min !== null && $item->available_length_max !== null
                    ? $fmt($item->available_length_min, 4).' – '.$fmt($item->available_length_max, 4)
                    : $fmt($item->available_length, 4);
            @endphp
            <tbody>
                <tr class="is-requested-row">
                    <td rowspan="2" class="ui-sticky-col text-center ui-tabular-nums tw-text-on-surface-variant" style="--ui-sticky-col-left: 0;">{{ $index + 1 }}</td>
                    <th scope="rowgroup" rowspan="2" class="ui-sticky-col text-start tw-font-normal" style="--ui-sticky-col-left: 2.5rem;">
                        <div class="fw-bold tw-text-on-surface">{{ $prItem?->material_name ?? '-' }}</div>
                        @if($prItem?->shape)
                            <span class="ui-status-chip ui-status-chip--neutral tw-mt-0.5">{{ $prItem->shape }}</span>
                        @endif
                    </th>
                    <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ __('procurement.quotation.row_requested') }}</span></td>
                    <td class="text-center ui-tabular-nums">{{ $prItem?->quantity_value ?? '—' }}</td>
                    @foreach($scalarDimensions as $field)
                        <td class="text-end ui-tabular-nums">{{ in_array($field, $relevant, true) ? $fmt($prItem?->{$field}, 4) : '—' }}</td>
                    @endforeach
                    <td class="text-end ui-tabular-nums">{{ in_array('length', $relevant, true) ? $fmt($prItem?->length, 4) : '—' }}</td>
                    <td class="text-end ui-tabular-nums">{{ $fmt($item->requested_weight_per_unit) }}</td>
                    <td class="text-end ui-tabular-nums">{{ $fmt($item->requested_total_weight) }}</td>
                    <td class="text-end ui-tabular-nums">{{ $fmt($item->price_per_kg, 4) }}</td>
                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ $item->is_available ? $fmt($item->requested_amount) : '—' }}</td>
                    <td class="text-end tw-text-outline">—</td>
                    <td class="text-start tw-text-on-surface-variant">{{ $prItem?->remark ?: '—' }}</td>
                    <td class="text-center tw-text-outline">—</td>
                    @if($showMtc)<td rowspan="2" class="text-center">
                        @forelse($item->attachments as $attachment)
                            <x-ui.icon-button :href="route('attachments.show', $attachment)" icon="paperclip" :label="__('common.closure.attachment_open', ['name' => $attachment->file_name])" size="sm" target="_blank" />
                        @empty
                            <span class="tw-text-outline">—</span>
                        @endforelse
                    </td>@endif
                </tr>
                <tr class="is-offer-row">
                    <td class="text-center"><span class="ui-status-chip ui-status-chip--info">{{ __('procurement.quotation.row_offer') }}</span></td>
                    @if($item->is_available)
                        <td class="text-center fw-bold ui-tabular-nums">{{ $item->available_qty ?? '—' }}</td>
                        @foreach($scalarDimensions as $field)
                            <td class="text-end fw-semibold ui-tabular-nums">{{ in_array($field, $relevant, true) ? $fmt($item->{'available_'.$field}, 4) : '—' }}</td>
                        @endforeach
                        <td class="text-end fw-semibold ui-tabular-nums">{{ in_array('length', $relevant, true) ? $offerLength : '—' }}</td>
                        <td class="text-end fw-semibold ui-tabular-nums">
                            {{ $fmt($item->offered_weight_per_unit ?? $item->requested_weight_per_unit) }}
                            @if($item->is_estimated_weight)<span class="ui-status-chip ui-status-chip--warning ms-1">{{ __('procurement.quotation.est_weight') }}</span>@endif
                        </td>
                        <td class="text-end fw-semibold ui-tabular-nums">{{ $fmt($item->offered_total_weight) }}</td>
                        <td class="text-end fw-bold ui-tabular-nums">
                            {{ $fmt($item->price_per_kg, 4) }}
                            {{-- Mission 06B: Purchasing-only live reference (never passed on Supplier pages). --}}
                            @if(isset($referencePrices) && ($reference = $referencePrices[$item->id] ?? null))
                                <div class="tw-text-ui-xs tw-font-normal tw-text-on-surface-variant" data-reference-price>
                                    @if($reference->isMatched())
                                        {{ __('materials.reference_prices.reference_price') }} {{ $fmt($reference->convertedReferencePricePerKg, 4) }}
                                        @if($reference->diffAmountPerKg !== null)· {{ __('materials.reference_prices.reference_delta') }} {{ bccomp($reference->diffAmountPerKg, '0', 4) > 0 ? '+' : '' }}{{ $fmt($reference->diffAmountPerKg, 4) }}@if($reference->diffPercent !== null) ({{ bccomp($reference->diffPercent, '0', 4) > 0 ? '+' : '' }}{{ $fmt($reference->diffPercent, 2) }}%)@endif @endif
                                    @else
                                        {{ __('materials.reference_prices.reasons.'.$reference->reasonCode) }}
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-end fw-bold text-primary ui-tabular-nums" data-offer-amount="{{ \App\Support\NumberFormat::maxDecimals($amount) }}">{{ $fmt($amount) }}</td>
                        <td class="text-end fw-semibold ui-tabular-nums">
                            @if($amountIdr !== null)
                                Rp {{ $fmt($amountIdr) }}
                            @else
                                <span title="{{ __('procurement.quotation.rate_unavailable') }}">—</span><span class="visually-hidden">{{ __('procurement.quotation.rate_unavailable') }}</span>
                            @endif
                        </td>
                    @else
                        <td colspan="{{ count($dimensionOrder) + 6 }}" class="text-center">
                            <span class="ui-status-chip ui-status-chip--error">{{ __('procurement.quotation.not_available') }}</span>
                        </td>
                    @endif
                    <td class="text-start tw-text-on-surface-variant">{{ $item->notes ?: '—' }}</td>
                    <td class="text-center">
                        @if($item->is_available)
                            <div class="d-flex flex-column align-items-center tw-gap-1">
                                <span class="ui-status-chip {{ $qtyTone }}">{{ $qtyLabel }}</span>
                                <span class="ui-status-chip {{ $specTone($availability['specification']['code']) }}">{{ $availability['specification']['label'] }}</span>
                            </div>
                        @else
                            <span class="ui-status-chip ui-status-chip--error">{{ __('procurement.quotation.not_available') }}</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        @endforeach
        <tfoot class="table-light fw-bold">
            <tr>
                <th scope="row" colspan="3" class="ui-sticky-col text-end" style="--ui-sticky-col-left: 0;">{{ __('procurement.quotation.total_requested') }}</th>
                <td class="text-center ui-tabular-nums" data-total="requested-qty">{{ $totals['req_qty'] }}</td>
                <td colspan="{{ count($dimensionOrder) + 1 }}"></td>
                <td class="text-end ui-tabular-nums" data-total="requested-kg">{{ $fmt($totals['req_kg']) }}</td>
                <td></td>
                <td class="text-end ui-tabular-nums tw-text-on-surface-variant" data-total="requested-amount">{{ $fmt($totals['req_amount']) }}</td>
                <td colspan="{{ $showMtc ? 4 : 3 }}"></td>
            </tr>
            <tr>
                <th scope="row" colspan="3" class="ui-sticky-col text-end" style="--ui-sticky-col-left: 0;">{{ __('procurement.quotation.total_offered') }}</th>
                <td class="text-center ui-tabular-nums" data-total="offered-qty">{{ $totals['off_qty'] }}</td>
                <td colspan="{{ count($dimensionOrder) + 1 }}"></td>
                <td class="text-end ui-tabular-nums" data-total="offered-kg">{{ $fmt($totals['off_kg']) }}</td>
                <td></td>
                <td class="text-end text-primary ui-tabular-nums" data-total="offered-amount">{{ $fmt($totals['off_amount']) }} {{ $quotation->currency }}</td>
                <td class="text-end text-primary ui-tabular-nums" data-total="offered-idr">
                    @if($totalIdr !== null)
                        Rp {{ $fmt($totalIdr) }}
                    @else
                        <span aria-hidden="true">—</span> <span class="tw-text-ui-xs tw-font-normal">{{ __('procurement.quotation.rate_unavailable') }}</span>
                    @endif
                </td>
                <td colspan="{{ $showMtc ? 3 : 2 }}"></td>
            </tr>
        </tfoot>
    </table>
</x-ui.data-table>
