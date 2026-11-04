{{-- Gate 1 Historical comparison, PR mode (C1-C7, E1-E3). Values arrive pre-formatted from the controller. --}}
@php
    $qh = $quotationHistory;
    $notRecorded = __('purchasing.quotation_history.not_recorded');
    $portLabel = fn (?string $code) => $code === null ? $notRecorded : \App\Support\Procurement\LogisticsTerms::portLabel($code);
    $statusMessage = [
        'not_offered' => __('purchasing.quotation_history.not_offered_message'),
        'spec_incomplete' => __('purchasing.quotation_history.spec_incomplete_message'),
        'insufficient_history' => __('purchasing.quotation_history.insufficient_message'),
        'basis_differs' => __('purchasing.quotation_history.basis_differs_message'),
        'fx_unavailable' => __('purchasing.quotation_history.fx_unavailable_message'),
    ];
@endphp

@include('purchasing.comparison._evaluation_header', ['evaluation' => $evaluation, 'route' => 'purchasing.comparison.historical'])

<section class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:px-4 tw:py-3" aria-label="{{ __('purchasing.quotation_history.pr_context', ['pr' => $qh['pr']['number'] ?? '-']) }}" data-historical-pr-context>
    <div class="tw:min-w-0">
        <div class="tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('purchasing.quotation_history.pr_context', ['pr' => $qh['pr']['number'] ?? '-']) }}</div>
        <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.pr_terms', ['incoterm' => $qh['pr']['incoterm'] ?? $notRecorded, 'port' => $portLabel($qh['pr']['port_of_discharge'] ?? null)]) }}</div>
    </div>
    <div class="tw:flex tw:flex-wrap tw:gap-2">
        <x-ui.button :href="$interSupplierUrl" variant="outline" size="sm" data-server-tab data-tab-name="inter-supplier">
            <x-ui.icon name="arrow-left" size="sm" />
            <span>{{ __('purchasing.quotation_history.back_to_award') }}</span>
        </x-ui.button>
        <x-ui.button :href="$clearPrUrl" variant="ghost" size="sm" data-historical-clear-pr>
            <x-ui.icon name="x" size="sm" />
            <span>{{ __('purchasing.quotation_history.clear_pr') }}</span>
        </x-ui.button>
    </div>
</section>

@foreach($qh['notices'] as $notice)
    <div class="tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface-container tw:px-3 tw:py-2 tw:text-ui-sm tw:text-on-surface" role="status">{{ $notice }}</div>
@endforeach

@if($qh['chart_item'] !== null)
    <section class="tw:grid tw:gap-3 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-4" aria-labelledby="historicalPrChartTitle">
        <div class="tw:flex tw:flex-wrap tw:items-end tw:justify-between tw:gap-3">
            <div class="tw:grid tw:gap-1">
                <h2 id="historicalPrChartTitle" class="tw:m-0 tw:text-ui-base tw:font-semibold tw:text-on-surface">{{ __('purchasing.quotation_history.chart_title') }}</h2>
                <label for="historicalPrChartItem" class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('purchasing.quotation_history.chart_item') }}</label>
                <select id="historicalPrChartItem" class="form-select form-select-sm tw:min-w-64" data-historical-chart-item>
                    @foreach($historyItems as $option)
                        <option value="{{ $option['hash'] }}" @selected($option['hash'] === $qh['chart_item']['hash'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="tw:flex tw:flex-wrap tw:gap-1 tw:rounded-ui-sm tw:border tw:border-outline tw:p-1" role="group" aria-label="{{ __('purchasing.quotation_history.chart_modes') }}">
                @foreach(['current_previous', 'quote_trend', 'po_history'] as $mode)
                    <button type="button"
                            class="ui-focus-ring tw:min-h-8 tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:px-3 tw:text-ui-sm tw:font-semibold tw:text-on-surface-variant tw:hover:bg-surface-container tw:aria-pressed:bg-primary tw:aria-pressed:text-primary-foreground"
                            aria-pressed="{{ $qh['chart'] === $mode ? 'true' : 'false' }}"
                            data-historical-chart-mode="{{ $mode }}">{{ __('purchasing.quotation_history.mode_'.$mode) }}</button>
                @endforeach
            </div>
        </div>
        <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant" id="historicalPrChartBasis" aria-live="polite"></p>
        {{-- The script rewrites the aria-label with the plotted count and basis; the history table below lists every quote. --}}
        <x-ui.chart id="historicalPrChart" :label="__('purchasing.quotation_history.chart_title')" :auto-summary="false" plot-class="tw:h-72">
            {{-- Sits inside the plot box, centered, when nothing can be plotted; the script sets the message per chart mode. --}}
            <x-slot:overlay>
                <x-ui.empty-state id="historicalPrChartEmpty" role="status" icon="chart-line"
                                  :title="__('purchasing.quotation_history.chart_empty')"
                                  class="tw:absolute tw:inset-0 tw:rounded-ui-sm tw:border tw:border-dashed tw:border-outline-variant tw:bg-surface" hidden />
            </x-slot:overlay>
        </x-ui.chart>
        <div class="tw:text-ui-xs tw:text-on-surface-variant" id="historicalPrChartExcluded"></div>
        <div class="tw:text-ui-xs tw:text-on-surface-variant" id="historicalPrPoSummary" hidden></div>
    </section>
@endif

<x-ui.data-table sticky :title="__('purchasing.quotation_history.table_title')" :description="__('purchasing.quotation_history.table_description')">
    @if($historyGroups === [])
        <p class="tw:m-0 tw:p-4 tw:text-ui-sm tw:text-on-surface-variant" role="status">{{ __('purchasing.quotation_history.empty_no_quotations') }}</p>
    @else
        <table class="table align-middle mb-0 tw:text-ui-sm" id="historicalPrTable">
            <caption class="tw:caption-top tw:px-3 tw:py-2 tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.table_caption') }}</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col" class="ui-sticky-col">{{ __('purchasing.quotation_history.col_supplier') }}</th>
                    <th scope="col">{{ __('purchasing.quotation_history.col_current') }}</th>
                    <th scope="col">{{ __('purchasing.quotation_history.col_previous') }}</th>
                    <th scope="col" class="text-end">{{ __('purchasing.quotation_history.col_difference') }}</th>
                    <th scope="col">{{ __('purchasing.quotation_history.col_status') }}</th>
                    <th scope="col" class="text-center">{{ __('purchasing.quotation_history.col_history') }}</th>
                    <th scope="col" class="text-center tw:min-w-[170px]">{{ __('purchasing.quotation_history.col_actions') }}</th>
                </tr>
            </thead>
            @foreach($historyGroups as $group)
                <tbody data-history-group="{{ $group['item']['hash'] }}">
                    <tr class="tw:bg-surface-container">
                        <th scope="rowgroup" colspan="7" class="tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ $group['item']['label'] }}</th>
                    </tr>
                    @foreach($group['rows'] as $row)
                        @php
                            $current = $row['current'];
                            $previous = $row['previous'];
                        @endphp
                        <tr data-history-row data-status="{{ $row['status'] }}">
                            <th scope="row" class="ui-sticky-col tw:font-medium tw:text-on-surface">{{ $row['supplier']['name'] }}</th>
                            <td>
                                @if($row['status'] === 'ambiguous')
                                    <ul class="tw:m-0 tw:grid tw:list-none tw:gap-1 tw:p-0">
                                        @foreach($row['sources'] as $index => $source)
                                            <li>
                                                <a href="{{ $source['detail_url'] }}" class="ui-focus-ring tw:font-medium tw:text-primary" data-return-url>{{ __('purchasing.quotation_history.view_source', ['number' => $index + 1]) }}</a>
                                                <span class="ui-tabular-nums">{{ $source['price_display'] }}</span>
                                                <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ $source['date_display'] }} · {{ \App\Support\StatusHelper::quotationLabel($source['status']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @elseif($current)
                                    <div class="ui-tabular-nums tw:font-semibold">{{ $current['price_display'] }}<span class="tw:font-normal tw:text-on-surface-variant">/kg</span></div>
                                    <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('annual_rates.original', ['price' => $current['original_display']]) }} · {{ $current['rate_display'] }}</div>
                                    <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ $current['date_display'] }}</div>
                                    @if($current['status'] === 'rejected')
                                        <x-ui.status-chip tone="neutral" class="tw:mt-1">{{ $current['status_label'] }}</x-ui.status-chip>
                                    @endif
                                @else
                                    <span class="tw:text-on-surface-variant">-</span>
                                @endif
                            </td>
                            <td>
                                @if($previous)
                                    <div class="ui-tabular-nums tw:font-semibold">{{ $previous['price_display'] }}<span class="tw:font-normal tw:text-on-surface-variant">/kg</span></div>
                                    <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('annual_rates.original', ['price' => $previous['original_display']]) }} · {{ $previous['rate_display'] }}</div>
                                    <div class="ui-tabular-nums tw:text-ui-xs tw:text-on-surface-variant">{{ $previous['po_number'] ?? '-' }} · {{ $previous['date_display'] }}</div>
                                    <div class="ui-tabular-nums tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.from_pr', ['pr' => $previous['pr_number'] ?? '-']) }}</div>
                                    @if($previous['status'] === 'rejected')
                                        <x-ui.status-chip tone="neutral" class="tw:mt-1">{{ $previous['status_label'] }}</x-ui.status-chip>
                                    @endif
                                    @if($row['reference_only'])
                                        <div class="tw:mt-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('purchasing.quotation_history.reference_only') }}</div>
                                    @endif
                                    @foreach($row['spec_diff'] as $diff)
                                        <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.spec_diff_line', ['label' => $diff['label'], 'current' => $diff['current'], 'previous' => $diff['previous']]) }}</div>
                                    @endforeach
                                    @if($previous['as_requested'])
                                        <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ $previous['spec_display'] }}</div>
                                    @endif
                                @elseif($row['status'] === 'different_spec')
                                    <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ trans_choice('purchasing.quotation_history.different_spec_message', $row['history_count'], ['count' => $row['history_count'], 'spec' => $row['key_dimension_display'] ?? '-']) }}</span>
                                @elseif($statusMessage[$row['status']] ?? null)
                                    <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ $statusMessage[$row['status']] }}</span>
                                @else
                                    <span class="tw:text-on-surface-variant">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($row['diff_display'] !== null)
                                    <div @class(['ui-tabular-nums tw:font-semibold', 'tw:text-error' => $row['direction'] > 0, 'tw:text-success' => $row['direction'] < 0])>
                                        <x-ui.icon :name="$row['direction'] > 0 ? 'arrow-up-right' : ($row['direction'] < 0 ? 'arrow-down-right' : 'minus')" size="sm" />
                                        {{ $row['diff_pct_display'] }}
                                        <span class="tw:font-normal">{{ __('purchasing.quotation_history.'.($row['direction'] > 0 ? 'higher' : ($row['direction'] < 0 ? 'lower' : 'unchanged'))) }}</span>
                                    </div>
                                    <div class="ui-tabular-nums tw:text-ui-xs tw:text-on-surface-variant">{{ $row['diff_display'] }}/kg</div>
                                    @if($row['fx_note'])
                                        <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.incl_fx') }}</div>
                                    @endif
                                    @if($row['indicative'])
                                        <div class="tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('purchasing.quotation_history.indicative') }}</div>
                                    @endif
                                @else
                                    <span class="tw:text-on-surface-variant">-</span>
                                @endif
                            </td>
                            <td>
                                <x-ui.status-chip :tone="$row['status_tone']">{{ $row['status_label'] }}</x-ui.status-chip>
                                @if($row['status'] === 'ambiguous')
                                    <div class="tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.ambiguous_message', ['count' => count($row['sources'])]) }}</div>
                                @elseif($row['indicative'])
                                    @if($row['spec_diff_display'] !== '')
                                        <div class="tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.indicative_spec', ['fields' => $row['spec_diff_display']]) }}</div>
                                    @endif
                                    @if($row['unverified_display'] !== '')
                                        <div class="tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.quotation_history.indicative_basis', ['fields' => $row['unverified_display']]) }}</div>
                                    @endif
                                @endif
                            </td>
                            <td class="text-center ui-tabular-nums">
                                {{ $row['history_count'] === null ? '-' : __('purchasing.quotation_history.history_counts', ['comparable' => $row['comparable_count'], 'total' => $row['history_count']]) }}
                            </td>
                            <td class="text-center tw:min-w-[170px]">
                                @php
                                    $hasActions = $current || $previous || ($current && ($row['history_count'] ?? 0) > 0);
                                @endphp
                                @if($hasActions)
                                    <div class="tw:inline-flex tw:flex-wrap tw:items-center tw:justify-center tw:gap-1.5">
                                        @if($current)
                                            <a href="{{ $current['detail_url'] }}"
                                               class="ui-data-action ui-data-action--primary ui-motion ui-focus-ring tw:gap-1.5 tw:bg-primary-container/20 tw:active:scale-[0.98]"
                                               data-return-url
                                               title="{{ __('purchasing.quotation_history.view_detail_label', ['pr' => $current['pr_number'] ?? '-', 'supplier' => $row['supplier']['name']]) }}"
                                               aria-label="{{ __('purchasing.quotation_history.view_detail_label', ['pr' => $current['pr_number'] ?? '-', 'supplier' => $row['supplier']['name']]) }}">
                                                <x-ui.icon name="external-link" size="xs" />
                                                <span>{{ __('purchasing.quotation_history.view_detail') }}</span>
                                            </a>
                                        @endif
                                        @if($previous)
                                            <a href="{{ $previous['detail_url'] }}"
                                               class="ui-data-action ui-motion ui-focus-ring tw:gap-1.5 tw:border-outline tw:bg-surface-container-low tw:text-on-surface-variant tw:hover:border-outline-strong tw:hover:bg-surface-container tw:hover:text-on-surface tw:active:scale-[0.98]"
                                               data-return-url
                                               title="{{ __('purchasing.quotation_history.view_detail_label', ['pr' => $previous['pr_number'] ?? '-', 'supplier' => $row['supplier']['name']]) }}"
                                               aria-label="{{ __('purchasing.quotation_history.view_detail_label', ['pr' => $previous['pr_number'] ?? '-', 'supplier' => $row['supplier']['name']]) }}">
                                                <x-ui.icon name="file-text" size="xs" />
                                                <span class="ui-tabular-nums">{{ $previous['pr_number'] ?? __('purchasing.quotation_history.view_detail') }}</span>
                                            </a>
                                        @endif
                                        @if($current && ($row['history_count'] ?? 0) > 0)
                                            <button type="button"
                                                    class="ui-data-action ui-motion ui-focus-ring tw:gap-1.5 tw:border-primary/25 tw:bg-primary-container/60 tw:text-on-primary-container tw:hover:border-primary tw:hover:bg-primary tw:hover:text-primary-foreground tw:active:scale-[0.98]"
                                                    data-historical-drawer
                                                    aria-haspopup="dialog"
                                                    data-pr-item="{{ $row['pr_item_hash'] }}"
                                                    data-supplier="{{ $row['supplier']['hash'] }}"
                                                    data-title="{{ __('purchasing.quotation_history.drawer_subtitle', ['supplier' => $row['supplier']['name'], 'material' => $group['item']['label']]) }}"
                                                    title="{{ __('purchasing.quotation_history.history_action_label', ['supplier' => $row['supplier']['name'], 'material' => $group['item']['label']]) }}"
                                                    aria-label="{{ __('purchasing.quotation_history.history_action_label', ['supplier' => $row['supplier']['name'], 'material' => $group['item']['label']]) }}">
                                                <x-ui.icon name="history" size="xs" />
                                                <span>{{ __('purchasing.quotation_history.history_action') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                @else
                                    <span class="tw:text-on-surface-variant">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    @endif
</x-ui.data-table>

<x-ui.dialog name="historical-quotation-dialog" :title="__('purchasing.quotation_history.drawer_title')" max-width="4xl">
    <p class="tw:m-0 tw:mb-3 tw:text-ui-sm tw:font-semibold tw:text-on-surface tw:text-balance" id="historicalDrawerSubtitle"></p>
    <div id="historicalDrawerBody" aria-live="polite" aria-busy="false"></div>
    {{-- Static classes the drawer script assigns (literal so the Tailwind scanner sees them). --}}
    <template id="historicalDrawerClasses"
              data-toggle="ui-focus-ring tw:inline-flex tw:min-h-9 tw:items-center tw:gap-1 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface tw:px-3 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:hover:bg-surface-container tw:aria-pressed:border-primary tw:aria-pressed:bg-primary-container tw:aria-pressed:text-on-primary-container"
              data-row-selected="tw:bg-primary-container/40"
              data-up="tw:text-error"
              data-down="tw:text-success"></template>
</x-ui.dialog>

@php
    $qhKey = fn (string $key, array $replace = []) => __('purchasing.quotation_history.'.$key, $replace);
    $historicalPrConfig = [
        'dataUrl' => route('purchasing.comparison.historical'),
        'prId' => $qh['pr']['hash'],
        'currency' => $currency,
        'chart' => $qh['chart'],
        'chartItem' => $qh['chart_item']['hash'] ?? null,
        'chartItemLabel' => $qh['chart_item']['label'] ?? null,
        'charts' => $qh['charts'],
        'poHistory' => $poHistory,
        'timezone' => config('app.business_timezone', 'Asia/Jakarta'),
        'locale' => app()->getLocale(),
        'i18n' => [
            'current' => __('purchasing.quotation_history.series_current'),
            'previous' => __('purchasing.quotation_history.series_previous'),
            'indicative' => __('purchasing.quotation_history.indicative'),
            'indicativeNote' => __('purchasing.quotation_history.series_indicative_note'),
            'notPlotted' => __('purchasing.quotation_history.not_plotted'),
            'chartSummary' => __('purchasing.quotation_history.chart_summary', ['material' => ':material', 'count' => ':count', 'basis' => ':basis']),
            'poBasis' => __('purchasing.quotation_history.po_basis', ['currency' => $currency]),
            'poEmpty' => __('purchasing.quotation_history.po_empty'),
            'chartEmpty' => __('purchasing.quotation_history.chart_empty'),
            'loadError' => __('purchasing.quotation_history.load_error'),
            'drawerLoading' => __('purchasing.quotation_history.drawer_loading'),
            'drawerError' => __('purchasing.quotation_history.drawer_error'),
            'drawerEmpty' => __('purchasing.quotation_history.drawer_empty'),
            'drawerShowing' => __('purchasing.quotation_history.drawer_showing', ['shown' => ':shown', 'total' => ':total']),
            'drawerShowingPinned' => __('purchasing.quotation_history.drawer_showing_pinned', ['shown' => ':shown', 'total' => ':total']),
            'drawerSelected' => __('purchasing.quotation_history.drawer_selected'),
            'drawerOtherSpec' => __('purchasing.quotation_history.drawer_other_spec'),
            'retry' => __('purchasing.quotation_history.retry'),
            'openQuotation' => __('purchasing.quotation_history.open_quotation'),
            'weightedAverage' => __('purchasing.comparison_analytics.historical_average'),
            'historicalBest' => __('purchasing.comparison_analytics.historical_best'),
            'drawer' => [
                'current' => $qhKey('drawer_current', ['price' => ':price', 'date' => ':date']),
                'range' => $qhKey('drawer_range', ['lowest' => ':lowest', 'lowest_date' => ':lowest_date', 'highest' => ':highest', 'highest_date' => ':highest_date', 'count' => ':count']),
                'tabsLabel' => $qhKey('drawer_tabs_label'),
                'tabSame' => $qhKey('drawer_tab_same', ['key' => ':key', 'count' => ':count']),
                'tabOther' => $qhKey('drawer_tab_other', ['count' => ':count']),
                'caption' => $qhKey('drawer_caption'),
                'colDate' => $qhKey('field_date'),
                'colPo' => $qhKey('drawer_col_po'),
                'fromPr' => $qhKey('from_pr', ['pr' => ':pr']),
                'colPrice' => $qhKey('drawer_col_price'),
                'colSize' => $qhKey('drawer_col_size'),
                'colDiff' => $qhKey('drawer_col_diff'),
                'higher' => $qhKey('drawer_current_higher'),
                'lower' => $qhKey('drawer_current_lower'),
                'unchanged' => $qhKey('unchanged'),
                'inclFx' => $qhKey('incl_fx'),
                'otherTerms' => $qhKey('drawer_other_terms', ['terms' => ':terms']),
                'noDiff' => $qhKey('drawer_no_diff'),
                'otherNote' => $qhKey('drawer_other_note'),
                'noneSame' => $qhKey('drawer_none_same'),
                'noneOther' => $qhKey('drawer_none_other'),
            ],
        ],
    ];
@endphp
<script id="historicalPrConfig" type="application/json">@json($historicalPrConfig)</script>
