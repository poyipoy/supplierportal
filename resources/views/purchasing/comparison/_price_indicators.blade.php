{{--
    Mission 07 indicator strip (decisions 19, 20, 29; Q4, Q9): per PR item, in
    the analysis currency at the evaluation date. Original currency values stay
    visible; a missing benchmark rate excludes the value and is reported.

    @param array  $matrix      rows with ['item' => PrItem, 'indicators' => [...]]
    @param string $currency
--}}
@php
    $pc = 'purchasing.comparison_analytics';
    $money = fn ($value, $code = null) => $value === null ? '—' : ($code ?? $currency).' '.$regionalFormatter->groupedDecimal($value, ($code ?? $currency) === 'IDR' ? 2 : 4);
    $currentYear = (int) \App\Support\BusinessTime::now()->year;
    $yearOptions = range($currentYear, $currentYear - 5);
    $selectedYear = isset($benchmarkYear) && $benchmarkYear ? (int) $benchmarkYear : null;
    $selectedMonth = isset($benchmarkMonth) && $benchmarkMonth ? (int) $benchmarkMonth : null;
@endphp
<x-ui.data-table :title="__($pc.'.indicators_title', ['currency' => $currency])" :description="__($pc.'.indicators_description')" sticky :scroll-label="__($pc.'.indicators_title', ['currency' => $currency])">
    <x-slot:actions>
        <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" data-benchmark-period-filter>
            <div>
                <label for="benchmarkYearSelect" class="form-label tw:mb-0.5 tw:text-ui-xs tw:font-semibold">{{ __('purchasing.copy.year') }}</label>
                <select id="benchmarkYearSelect" class="form-select form-select-sm tw:min-w-28" aria-label="{{ __('purchasing.copy.year') }}">
                    <option value="">{{ __('common.filters.all') }}</option>
                    @foreach($yearOptions as $yr)
                        <option value="{{ $yr }}" @selected($selectedYear === $yr)>{{ $yr }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="benchmarkMonthSelect" class="form-label tw:mb-0.5 tw:text-ui-xs tw:font-semibold">{{ __('purchasing.copy.month') }}</label>
                <select id="benchmarkMonthSelect" class="form-select form-select-sm tw:min-w-32" aria-label="{{ __('purchasing.copy.month') }}">
                    <option value="">{{ __('common.filters.all') }}</option>
                    @for($mn = 1; $mn <= 12; $mn++)
                        <option value="{{ $mn }}" @selected($selectedMonth === $mn)>{{ \Carbon\Carbon::create(2000, $mn, 1)->translatedFormat('F') }}</option>
                    @endfor
                </select>
            </div>
            @if($selectedYear || $selectedMonth)
                <x-ui.button type="button" variant="ghost" size="sm" id="benchmarkPeriodResetBtn">
                    {{ __('common.actions.reset') }}
                </x-ui.button>
            @endif
        </div>
    </x-slot:actions>
    <table class="table table-sm align-middle mb-0 tw:text-ui-xs" data-price-indicators>
        <caption class="visually-hidden">{{ __($pc.'.indicators_title', ['currency' => $currency]) }}</caption>
        <thead class="table-light">
            <tr>
                <th scope="col" class="ui-sticky-col">{{ __('purchasing.copy.material') }}</th>
                <th scope="col">{{ __('purchasing.copy.dimensions') }}</th>
                <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
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
                    <td class="tw:text-on-surface tw:whitespace-nowrap" data-indicator="dimensions">{{ $row['dimension_display'] ?? ($row['item']->dimension_label ?: '—') }}</td>
                    <td class="tw:text-on-surface" data-indicator="supplier">{{ $ind['best_current']['supplier'] ?? '—' }}</td>
                    <td class="text-end ui-tabular-nums" data-indicator="best-current">
                        @if($ind['best_current'])
                            <div class="fw-semibold">{{ $money($ind['best_current']['converted']) }}</div>
                            <div class="tw:text-on-surface-variant">{{ $money($ind['best_current']['original'], $ind['best_current']['original_currency']) }}</div>
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
                            @if(!empty($ind['reference']['category_key']))
                                <div class="tw:text-on-surface-variant">{{ $ind['reference']['category_name'] ?? $ind['reference']['category_key'] }} · {{ $ind['reference']['benchmark_year'] }} · {{ __($pc.'.history_basis', ['pos' => $ind['reference']['po_count'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['reference']['total_kg'])]) }}</div>
                            @elseif($ind['reference']['original'] !== null)
                                <div class="tw:text-on-surface-variant">{{ $money($ind['reference']['original'], $ind['reference']['original_currency']) }}</div>
                            @endif
                            @if(($ind['reference']['excluded']['lines'] ?? 0) > 0)
                                <div class="tw:text-warning" data-indicator-reference-excluded>{{ __('materials.reference_prices.historical.excluded_notice', ['lines' => $ind['reference']['excluded']['lines'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['reference']['excluded']['kg'])]) }}</div>
                            @endif
                        @elseif($ind['reference'])
                            <span class="tw:text-on-surface-variant">{{ __('materials.reference_prices.reasons.'.$ind['reference']['reason']) }}</span>
                            @if(!empty($ind['reference']['category_key']) && !empty($ind['reference']['benchmark_year']))
                                <div class="tw:text-on-surface-variant">{{ $ind['reference']['category_name'] ?? $ind['reference']['category_key'] }} · {{ $ind['reference']['benchmark_year'] }}</div>
                            @endif
                            @if(($ind['reference']['excluded']['lines'] ?? 0) > 0)
                                <div class="tw:text-warning" data-indicator-reference-excluded>{{ __('materials.reference_prices.historical.excluded_notice', ['lines' => $ind['reference']['excluded']['lines'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['reference']['excluded']['kg'])]) }}</div>
                            @endif
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
