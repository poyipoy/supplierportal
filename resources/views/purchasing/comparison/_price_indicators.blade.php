{{--
    Mission 07 indicator strip (decisions 19, 20, 29; Q4, Q9): per PR item, in
    the analysis currency at the evaluation date. Original currency values stay
    visible; a missing benchmark rate excludes the value and is reported.

    @param array  $matrix      rows with ['item' => PrItem, 'indicators' => [...]]
    @param string $currency
--}}
@php
    $pc = 'purchasing.comparison_analytics';
    $money = fn ($value, $code = null) => $value === null ? '—' : ($code ?? $currency).' '.\App\Support\NumberFormat::maxDecimals($value, ($code ?? $currency) === 'IDR' ? 2 : 4);
@endphp
<x-ui.data-table :title="__($pc.'.indicators_title', ['currency' => $currency])" :description="__($pc.'.indicators_description')" sticky :scroll-label="__($pc.'.indicators_title', ['currency' => $currency])">
    <table class="table table-sm align-middle mb-0 tw:text-ui-xs" data-price-indicators>
        <caption class="visually-hidden">{{ __($pc.'.indicators_title', ['currency' => $currency]) }}</caption>
        <thead class="table-light">
            <tr>
                <th scope="col" class="ui-sticky-col">{{ __('purchasing.copy.material') }}</th>
                <th scope="col" class="text-end">{{ __($pc.'.best_current') }}</th>
                <th scope="col" class="text-end">{{ __($pc.'.historical_best') }}</th>
                <th scope="col" class="text-end">{{ __($pc.'.historical_average') }}</th>
                <th scope="col" class="text-end">{{ __($pc.'.reference') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($matrix as $row)
                @php($ind = $row['indicators'])
                <tr data-indicator-row="{{ $row['item']->id }}">
                    <th scope="row" class="ui-sticky-col tw:font-semibold">{{ $row['item']->material_name }}</th>
                    <td class="text-end ui-tabular-nums" data-indicator="best-current">
                        @if($ind['best_current'])
                            <div class="fw-semibold">{{ $money($ind['best_current']['converted']) }}</div>
                            <div class="tw:text-on-surface-variant">{{ $money($ind['best_current']['original'], $ind['best_current']['original_currency']) }} · {{ $ind['best_current']['supplier'] ?? '-' }}</div>
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-end ui-tabular-nums" data-indicator="historical-best">{{ $money($ind['history']['best']) }}</td>
                    <td class="text-end ui-tabular-nums" data-indicator="historical-average">
                        @if($ind['history']['weighted_average'] !== null)
                            <div class="fw-semibold">{{ $money($ind['history']['weighted_average']) }}</div>
                            <div class="tw:text-on-surface-variant">{{ __($pc.'.history_basis', ['pos' => $ind['history']['po_count'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['history']['total_kg'])]) }}</div>
                        @else
                            <span class="tw:text-on-surface-variant">{{ __($pc.'.no_history') }}</span>
                        @endif
                        @if($ind['history']['excluded']['lines'] > 0)
                            <div class="tw:text-warning" data-indicator-excluded>{{ __($pc.'.excluded_note', ['lines' => $ind['history']['excluded']['lines'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['history']['excluded']['kg']), 'currencies' => implode(', ', $ind['history']['excluded']['currencies'])]) }}</div>
                        @endif
                    </td>
                    <td class="text-end ui-tabular-nums" data-indicator="reference">
                        @if($ind['reference'] && $ind['reference']['matched'])
                            <div class="fw-semibold">{{ $money($ind['reference']['converted']) }}</div>
                            <div class="tw:text-on-surface-variant">{{ $money($ind['reference']['original'], $ind['reference']['original_currency']) }}</div>
                        @elseif($ind['reference'])
                            <span class="tw:text-on-surface-variant">{{ __('materials.reference_prices.reasons.'.$ind['reference']['reason']) }}</span>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                @if($ind['unavailable_currencies'] !== [])
                    <tr>
                        <td colspan="5" class="tw:text-ui-xs">
                            <span class="ui-status-chip ui-status-chip--warning">{{ __($pc.'.rate_unavailable') }}</span>
                            {{ __($pc.'.unavailable_note', ['currencies' => implode(', ', $ind['unavailable_currencies'])]) }}
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</x-ui.data-table>
