{{--
    Mission 06B (I-19): reference price captured at PO finalization. Reads the
    immutable snapshot rows only and never calls the live resolver, so a
    reference price created later is never shown as if it applied to this PO.
    Purchasing only; Supplier pages never include this partial.
--}}
@php
    $rp = 'materials.reference_prices';
    $snapshots = $po->referencePriceSnapshots;
    $num = fn ($value) => $value === null ? '—' : \App\Support\NumberFormat::maxDecimals($value, 4);
@endphp
<x-ui.card :title="__($rp.'.snapshot.title')" id="sec-reference-price" class="tw-min-w-0 tw-scroll-mt-24" data-reference-snapshot-panel>
    @if($snapshots->isEmpty())
        <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant" data-reference-not-captured>{{ __($rp.'.snapshot.not_captured') }}</p>
    @else
        <p class="tw-mb-2 tw-text-ui-xs tw-text-on-surface-variant">{{ __($rp.'.snapshot.evaluation_date') }} {{ $snapshots->first()->evaluation_date->format('Y-m-d') }}</p>
        <div class="table-responsive" role="region" tabindex="0" aria-label="{{ __($rp.'.snapshot.title') }}">
            <table class="table table-sm align-middle mb-0 tw-text-ui-xs">
                <caption class="visually-hidden">{{ __($rp.'.snapshot.title') }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __($rp.'.snapshot.material') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.snapshot.offer') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.snapshot.reference') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.snapshot.converted') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.snapshot.difference') }}</th>
                        <th scope="col" class="text-end">{{ __($rp.'.snapshot.difference_percent') }}</th>
                        <th scope="col">{{ __($rp.'.snapshot.result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($snapshots as $snapshot)
                        @php($matched = $snapshot->status === \App\Models\AwardReferencePriceSnapshot::STATUS_MATCHED)
                        <tr>
                            <td>{{ $snapshot->award?->prItem?->material_name ?? '-' }}</td>
                            <td class="text-end ui-tabular-nums">{{ $snapshot->po_currency }} {{ $num($snapshot->offer_price_per_kg) }}</td>
                            <td class="text-end ui-tabular-nums">{{ $matched ? $snapshot->reference_currency.' '.$num($snapshot->reference_price_per_kg) : '—' }}</td>
                            <td class="text-end ui-tabular-nums">{{ $matched ? $snapshot->po_currency.' '.$num($snapshot->converted_reference_price_per_kg) : '—' }}</td>
                            <td class="text-end ui-tabular-nums">{{ $num($snapshot->diff_amount_per_kg) }}</td>
                            <td class="text-end ui-tabular-nums">{{ $snapshot->diff_percent === null ? '—' : $num($snapshot->diff_percent).'%' }}</td>
                            <td>
                                @if($matched)
                                    <x-ui.status-chip tone="success" size="sm">{{ __($rp.'.snapshot.matched') }}</x-ui.status-chip>
                                @else
                                    <span class="tw-text-on-surface-variant">{{ __($rp.'.reasons.'.$snapshot->reason_code) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
