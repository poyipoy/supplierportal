{{--
    Mission 07 (Q4, Q9): evaluation date (business today, resolved once per
    request), the analysis currency and the benchmark rate effective date per
    currency. A missing rate is shown as "Exchange Rate Unavailable", never 1/0.

    @param array  $evaluation  PriceComparisonController::evaluationMeta()
    @param string $route       Route name of the current tab
--}}
@php
    $pc = 'purchasing.comparison_analytics';
    $preserved = collect(request()->query())->except(['currency', 'history_page', 'view', '_'])->filter(fn ($value) => is_scalar($value) && $value !== '');
    $selectId = 'analysisCurrency'.\Illuminate\Support\Str::studly(str_replace(['.', '-'], '_', $route));
@endphp
<section class="tw:flex tw:flex-wrap tw:items-end tw:justify-between tw:gap-3 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:px-4 tw:py-3" data-evaluation-header aria-label="{{ __($pc.'.evaluation_title') }}">
    <div class="tw:grid tw:gap-1">
        <div class="tw:text-ui-sm tw:font-semibold tw:text-on-surface" data-evaluation-date="{{ $evaluation['date'] }}">
            {{ __($pc.'.evaluation_date', ['date' => $evaluation['date_display'], 'timezone' => $evaluation['timezone']]) }}
        </div>
        <ul class="tw:m-0 tw:flex tw:flex-wrap tw:gap-x-3 tw:gap-y-1 tw:p-0 tw:text-ui-xs tw:text-on-surface-variant" aria-label="{{ __($pc.'.rate_dates') }}">
            @foreach($evaluation['rates'] as $code => $rate)
                @continue($code === 'IDR')
                <li class="tw:list-none">
                    @if($rate['available'])
                        {{ __($pc.'.rate_effective', ['currency' => $code, 'date' => $rate['valid_from_display']]) }}
                    @else
                        <span class="ui-status-chip ui-status-chip--warning" data-rate-unavailable="{{ $code }}">{{ $code }}: {{ __($pc.'.rate_unavailable') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
    <form method="GET" action="{{ route($route) }}" class="tw:flex tw:items-end tw:gap-2" data-server-tabs-form>
        @foreach($preserved as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <div class="tw:grid tw:gap-1">
            <label for="{{ $selectId }}" class="tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __($pc.'.currency') }}</label>
            <select name="currency" id="{{ $selectId }}" class="form-select form-select-sm" data-analysis-currency>
                @foreach($evaluation['currencies'] as $code)
                    <option value="{{ $code }}" @selected($evaluation['currency'] === $code)>{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-sm btn-outline-primary">{{ __($pc.'.apply') }}</button></noscript>
    </form>
</section>
