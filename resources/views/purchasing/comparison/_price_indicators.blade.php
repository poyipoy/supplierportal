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
        @php
            $benchmarkYearSelectOptions = collect([
                ['value' => '', 'label' => __('common.filters.all')],
            ])->merge(collect($yearOptions)->map(fn ($yr) => ['value' => (string) $yr, 'label' => (string) $yr]))->values()->all();
            $benchmarkMonthSelectOptions = collect([
                ['value' => '', 'label' => __('common.filters.all')],
            ])->merge(collect(range(1, 12))->map(fn ($mn) => ['value' => (string) $mn, 'label' => \Carbon\Carbon::create(2000, $mn, 1)->translatedFormat('F')]))->values()->all();
        @endphp
        <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" data-benchmark-period-filter>
            <div class="tw:min-w-32">
                <x-ui.searchable-select
                    id="benchmarkYearSelect"
                    :label="__('purchasing.copy.year')"
                    label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold"
                    size="sm"
                    :placeholder="__('common.filters.all')"
                    :show-sublabel-on-trigger="false"
                    :value="$selectedYear ? (string) $selectedYear : ''"
                    :options="$benchmarkYearSelectOptions"
                    class="tw:gap-0.5"
                />
            </div>
            <div class="tw:min-w-40">
                <x-ui.searchable-select
                    id="benchmarkMonthSelect"
                    :label="__('purchasing.copy.month')"
                    label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold"
                    size="sm"
                    :placeholder="__('common.filters.all')"
                    :show-sublabel-on-trigger="false"
                    :value="$selectedMonth ? (string) $selectedMonth : ''"
                    :options="$benchmarkMonthSelectOptions"
                    class="tw:gap-0.5"
                />
            </div>
            @if($selectedYear || $selectedMonth)
                <x-ui.button type="button" variant="ghost" size="sm" id="benchmarkPeriodResetBtn">
                    <x-ui.icon name="rotate-ccw" size="sm" />
                    <span>{{ __('common.actions.reset') }}</span>
                </x-ui.button>
            @endif
        </div>
    </x-slot:actions>
    @php
        $selectedMonthName = $selectedMonth ? \Carbon\Carbon::create(2000, $selectedMonth, 1)->translatedFormat('M') : null;
        $referencePeriodLabel = match (true) {
            $selectedYear !== null && $selectedMonthName !== null => $selectedMonthName.' '.$selectedYear,
            $selectedYear !== null => (string) $selectedYear,
            $selectedMonthName !== null => $selectedMonthName.' · '.__('purchasing.copy.all_years'),
            default => __('purchasing.copy.all_years'),
        };
    @endphp
    <table class="ui-table ui-table--sm tw:align-middle tw:mb-0 tw:text-ui-xs" data-price-indicators>
        <caption class="tw:sr-only">{{ __($pc.'.indicators_title', ['currency' => $currency]) }}</caption>
        <thead>
            <tr>
                <th scope="col" class="ui-sticky-col">{{ __('purchasing.copy.material') }}</th>
                <th scope="col">{{ __('purchasing.copy.dimensions') }}</th>
                <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                <th scope="col" class="tw:text-end">{{ __($pc.'.best_current') }}</th>
                <th scope="col" class="tw:text-end">{{ __($pc.'.historical_best') }}</th>
                <th scope="col" class="tw:text-end">{{ __($pc.'.historical_average') }}</th>
                <th scope="col" class="tw:text-end">{{ __($pc.'.best_price_reference') }}</th>
                <th scope="col" class="tw:text-end">{{ __($pc.'.price_reference_average') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($matrix as $row)
                @php($ind = $row['indicators'])
                <tr data-indicator-row="{{ $row['item']->id }}">
                    <th scope="row" class="ui-sticky-col ui-col-data-name tw:font-semibold">{{ $row['item']->material_name }}</th>
                    <td class="tw:text-on-surface tw:whitespace-nowrap ui-tabular-nums" data-indicator="dimensions">{{ $row['dimension_display'] ?? ($row['item']->dimension_label ?: '-') }}</td>
                    <td class="tw:text-on-surface ui-col-data-name" data-indicator="supplier">{{ $ind['best_current']['supplier'] ?? '-' }}</td>
                    <td class="tw:text-end ui-tabular-nums" data-indicator="best-current">
                        @if($ind['best_current'])
                            <div class="tw:font-semibold">{{ $money($ind['best_current']['converted']) }}</div>
                            <div class="tw:text-on-surface-variant">{{ $money($ind['best_current']['original'], $ind['best_current']['original_currency']) }}</div>
                        @else
                            -
                        @endif
                    </td>
                    <td class="tw:text-end ui-tabular-nums" data-indicator="historical-best">{{ $money($ind['history']['best']) }}</td>
                    <td class="tw:text-end ui-tabular-nums" data-indicator="historical-average">
                        @if($ind['history']['weighted_average'] !== null)
                            <div class="tw:font-semibold">{{ $money($ind['history']['weighted_average']) }}</div>
                            <div class="tw:text-on-surface-variant">{{ __($pc.'.history_basis', ['pos' => $ind['history']['po_count'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['history']['total_kg'])]) }}</div>
                        @else
                            <span class="tw:text-on-surface-variant">{{ __($pc.'.no_history') }}</span>
                        @endif
                        @if($ind['history']['excluded']['lines'] > 0)
                            <div class="tw:text-warning" data-indicator-excluded>{{ __($pc.'.excluded_note', ['lines' => $ind['history']['excluded']['lines'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['history']['excluded']['kg']), 'currencies' => implode(', ', $ind['history']['excluded']['currencies'])]) }}</div>
                        @endif
                    </td>
                    <td class="tw:text-end ui-tabular-nums" data-indicator="best-price-reference">
                        @if($ind['reference'] && $ind['reference']['matched'] && ($ind['reference']['best_converted'] ?? null) !== null)
                            <div class="tw:font-semibold tw:text-success">{{ $money($ind['reference']['best_converted']) }}</div>
                            @if(!empty($ind['reference']['category_key']))
                                <div class="tw:text-on-surface-variant">{{ $ind['reference']['category_name'] ?? $ind['reference']['category_key'] }} · {{ $referencePeriodLabel }}</div>
                            @endif
                        @else
                            <span class="tw:text-on-surface-variant">-</span>
                        @endif
                    </td>
                    <td class="tw:text-end ui-tabular-nums" data-indicator="reference">
                        @if($ind['reference'] && $ind['reference']['matched'])
                            <div class="tw:font-semibold">{{ $money($ind['reference']['average_converted'] ?? $ind['reference']['converted']) }}</div>
                            @if(!empty($ind['reference']['category_key']))
                                <div class="tw:text-on-surface-variant">{{ $ind['reference']['category_name'] ?? $ind['reference']['category_key'] }} · {{ $referencePeriodLabel }} · {{ __($pc.'.history_basis', ['pos' => $ind['reference']['po_count'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['reference']['total_kg'])]) }}</div>
                            @elseif($ind['reference']['original'] !== null)
                                <div class="tw:text-on-surface-variant">{{ $money($ind['reference']['original'], $ind['reference']['original_currency']) }}</div>
                            @endif
                            @if(($ind['reference']['excluded']['lines'] ?? 0) > 0)
                                <div class="tw:text-warning" data-indicator-reference-excluded>{{ __('materials.reference_prices.historical.excluded_notice', ['lines' => $ind['reference']['excluded']['lines'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['reference']['excluded']['kg'])]) }}</div>
                            @endif
                        @elseif($ind['reference'])
                            <span class="tw:text-on-surface-variant">{{ __('materials.reference_prices.reasons.'.$ind['reference']['reason']) }}</span>
                            @if(!empty($ind['reference']['category_key']))
                                <div class="tw:text-on-surface-variant">{{ $ind['reference']['category_name'] ?? $ind['reference']['category_key'] }} · {{ $referencePeriodLabel }}</div>
                            @endif
                            @if(($ind['reference']['excluded']['lines'] ?? 0) > 0)
                                <div class="tw:text-warning" data-indicator-reference-excluded>{{ __('materials.reference_prices.historical.excluded_notice', ['lines' => $ind['reference']['excluded']['lines'], 'kg' => \App\Support\NumberFormat::maxDecimals($ind['reference']['excluded']['kg'])]) }}</div>
                            @endif
                        @else
                            -
                        @endif
                    </td>
                </tr>
                @if($ind['unavailable_currencies'] !== [])
                    <tr>
                        <td colspan="8" class="tw:text-ui-xs">
                            <span class="ui-status-chip ui-status-chip--warning">{{ __($pc.'.rate_unavailable') }}</span>
                            {{ __($pc.'.unavailable_note', ['currencies' => implode(', ', $ind['unavailable_currencies'])]) }}
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</x-ui.data-table>
