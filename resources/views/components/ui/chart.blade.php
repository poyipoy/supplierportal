{{--
    Accessible Chart.js frame (docs/audits/CHART-A11Y-BASELINE-20261102.md).
    The canvas stays role="img" with a short name; the figcaption carries a text summary
    (written here, or computed by chart-theme.js from the chart data when `summary` is empty)
    plus the keyboard hint. Pass a `table` slot for the data-table alternative.
    `plot-class` must be a literal Tailwind height (e.g. "tw:h-64") that includes the x-axis band.
--}}
@props([
    'id',
    'label',
    'fallback' => null,
    'plotClass' => 'tw:h-64',
    'summary' => null,
    'autoSummary' => true,
    'summarySrOnly' => false,
    'hint' => true,
    'describedby' => null,
    'tableLabel' => null,
    'scrollable' => false,
])

@php
    $summaryMode = filled($summary) ? 'server' : ($autoSummary ? 'auto' : null);
    $describedBy = trim(implode(' ', array_filter([
        $describedby,
        $summaryMode ? $id.'-summary' : null,
        $hint ? $id.'-hint' : null,
    ])));
@endphp

<figure {{ $attributes->class(['ui-chart tw:m-0 tw:min-w-0']) }} data-adasi-chart>
    @if($scrollable)<div class="tw:w-full tw:overflow-x-auto tw:pb-1" data-adasi-chart-scroll>@endif
    <div class="tw:relative tw:w-full {{ $plotClass }}" data-adasi-chart-plot>
        <canvas id="{{ $id }}" role="img" tabindex="0" aria-label="{{ $label }}"@if($describedBy !== '') aria-describedby="{{ $describedBy }}"@endif data-adasi-chart-focusable>{{ $fallback ?? $label }}</canvas>
        {{ $overlay ?? '' }}
        <span class="tw:sr-only" aria-live="polite" data-adasi-chart-live></span>
    </div>
    @if($scrollable)</div>@endif
    @if($summaryMode || $hint)
        <figcaption class="tw:mt-2 tw:grid tw:gap-0.5 tw:text-ui-xs tw:text-on-surface-variant">
            @if($summaryMode)
                <span id="{{ $id }}-summary" class="{{ $summarySrOnly ? 'tw:sr-only' : 'tw:text-on-surface' }}" data-adasi-chart-summary="{{ $summaryMode }}">{{ $summary }}</span>
            @endif
            @if($hint)
                <span id="{{ $id }}-hint">{{ __('charts.keyboard_hint') }}</span>
            @endif
        </figcaption>
    @endif
    @isset($table)
        <details class="tw:mt-2">
            <summary class="ui-button ui-motion ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:cursor-pointer tw:list-none tw:items-center tw:justify-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-transparent tw:px-2.5 tw:py-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:hover:bg-surface-container tw:active:bg-surface-high">
                <x-ui.icon name="table" size="xs" aria-hidden="true" />
                <span>{{ $tableLabel ?? __('charts.view_table') }}</span>
            </summary>
            <div class="tw:mt-2 tw:overflow-x-auto">{{ $table }}</div>
        </details>
    @endisset
    @once('adasi-chart-i18n')
        <script type="application/json" data-adasi-chart-i18n>@json(__('charts.js'))</script>
    @endonce
</figure>
