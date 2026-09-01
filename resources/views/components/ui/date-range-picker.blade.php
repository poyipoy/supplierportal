@props([
    'id' => null,
    'granularity' => 'day',
    'startName',
    'startId' => null,
    'startLabel' => 'Start Date',
    'endName',
    'endId' => null,
    'endLabel' => 'End Date',
    'startValue' => null,
    'endValue' => null,
    'startMin' => null,
    'startMax' => null,
    'endMin' => null,
    'endMax' => null,
    'required' => false,
    'presets' => true,
    'compact' => false,
    'helper' => null,
    'error' => null,
    'errorId' => null,
])

@php
    $inputType = $granularity === 'month' ? 'month' : 'date';
    $resolvedStartId = $startId ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $startName);
    $resolvedEndId = $endId ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $endName);
    $resolvedId = $id ?: $resolvedStartId . '-range-picker';
    $startKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $startName), '.');
    $endKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $endName), '.');
    $startResolvedValue = old($startKey, $startValue);
    $endResolvedValue = old($endKey, $endValue);
    $message = $error ?: (isset($errors) && $errors->has($startKey) ? $errors->first($startKey) : (isset($errors) && $errors->has($endKey) ? $errors->first($endKey) : null));
    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $errorId ?: $resolvedId . '-error';
    $labelId = $resolvedId . '-label';
    $panelId = $resolvedId . '-calendar-panel';
    $describedBy = collect([$helperId, $errorId])->filter()->implode(' ');
    $formatRangeDisplay = function ($value, $granularity) {
        if (! $value) {
            return 'Any time';
        }
        if ($granularity === 'month' && preg_match('/^\d{4}-\d{2}$/', (string) $value)) {
            [$year, $month] = explode('-', (string) $value);
            return date('M Y', mktime(0, 0, 0, (int) $month, 1, (int) $year));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            [$year, $month, $day] = explode('-', (string) $value);
            return date('d M Y', mktime(0, 0, 0, (int) $month, (int) $day, (int) $year));
        }
        return 'Any time';
    };

    $startDisplay = $formatRangeDisplay($startResolvedValue, $granularity);
    $endDisplay = $formatRangeDisplay($endResolvedValue, $granularity);
@endphp

<div
    id="{{ $resolvedId }}"
    {{ $attributes->only('class')->class(['ui-calendar ui-date-range-picker tw-grid tw-gap-1.5', 'ui-date-range-picker--compact' => $compact]) }}
    data-adasi-date-range
    data-calendar-granularity="{{ $granularity }}"
    data-calendar-required="{{ $required ? 'true' : 'false' }}"
>
    <div data-calendar-native class="{{ $compact ? 'ui-date-range-picker__native-compact' : 'tw-grid tw-gap-3 sm:tw-grid-cols-2' }}">
        <div class="tw-grid tw-gap-1.5">
            <label for="{{ $resolvedStartId }}" class="{{ $compact ? 'tw-sr-only' : 'tw-text-ui-sm tw-font-medium tw-text-on-surface' }}">{{ $startLabel }}</label>
            <input id="{{ $resolvedStartId }}" name="{{ $startName }}" type="{{ $inputType }}" value="{{ $startResolvedValue }}" @if($startMin) min="{{ $startMin }}" @endif @if($startMax) max="{{ $startMax }}" @endif @required($required) data-calendar-native-input data-calendar-boundary-input="start" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif class="ui-calendar__native-input ui-motion tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-strong tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface tw-shadow-none focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary">
        </div>
        <div class="tw-grid tw-gap-1.5">
            <label for="{{ $resolvedEndId }}" class="{{ $compact ? 'tw-sr-only' : 'tw-text-ui-sm tw-font-medium tw-text-on-surface' }}">{{ $endLabel }}</label>
            <input id="{{ $resolvedEndId }}" name="{{ $endName }}" type="{{ $inputType }}" value="{{ $endResolvedValue }}" @if($endMin) min="{{ $endMin }}" @endif @if($endMax) max="{{ $endMax }}" @endif @required($required) data-calendar-native-input data-calendar-boundary-input="end" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif class="ui-calendar__native-input ui-motion tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-strong tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface tw-shadow-none focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary">
        </div>
    </div>

    <div data-calendar-enhanced hidden>
        <span id="{{ $labelId }}" class="{{ $compact ? 'tw-sr-only' : 'tw-text-ui-sm tw-font-medium tw-text-on-surface' }}">{{ $startLabel }} and {{ $endLabel }}</span>
        <div class="ui-date-range-trigger" role="group" aria-labelledby="{{ $labelId }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif>
            <button type="button" class="ui-date-range-trigger__field" data-calendar-boundary="start" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $panelId }}">
                <span class="ui-date-range-trigger__label">{{ $startLabel }}</span>
                <span class="ui-date-range-trigger__value" data-calendar-display="start">{{ $startDisplay }}</span>
            </button>
            <span class="ui-date-range-trigger__divider" aria-hidden="true">–</span>
            <button type="button" class="ui-date-range-trigger__field" data-calendar-boundary="end" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $panelId }}">
                <span class="ui-date-range-trigger__label">{{ $endLabel }}</span>
                <span class="ui-date-range-trigger__value" data-calendar-display="end">{{ $endDisplay }}</span>
            </button>
            <x-ui.icon name="calendar-days" size="sm" class="ui-date-range-trigger__icon" aria-hidden="true" />
        </div>
    </div>

    @if($helper)<p id="{{ $helperId }}" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $helper }}</p>@endif
    <p id="{{ $errorId }}" data-calendar-error class="tw-m-0 tw-flex tw-items-start tw-gap-1.5 tw-text-ui-xs tw-font-medium tw-text-error {{ $message ? '' : 'tw-hidden' }}" role="alert"><x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5" /><span data-calendar-error-message>{{ $message }}</span></p>

    <div id="{{ $panelId }}" class="ui-calendar-panel ui-calendar-panel--range" data-calendar-panel hidden role="dialog" aria-modal="false" aria-label="Choose date range">
        <div class="ui-calendar-panel__topline">
            <div><span class="ui-calendar-panel__title">Select {{ $granularity === 'month' ? 'months' : 'dates' }}</span><span class="ui-calendar-panel__context" data-calendar-context>Select {{ $startLabel }}</span></div>
            <button type="button" class="ui-calendar-panel__close" data-calendar-close aria-label="Close calendar"><x-ui.icon name="x" size="sm" /></button>
        </div>

        @if($presets)
            <div class="ui-calendar-presets" data-calendar-presets aria-label="Quick ranges"></div>
        @endif

        @if($granularity === 'month')
            <div class="ui-month-grid__header">
                <button type="button" class="ui-calendar-icon-action" data-calendar-year-previous aria-label="Previous year"><x-ui.icon name="chevron-left" size="sm" /></button>
                <label class="tw-sr-only" for="{{ $resolvedId }}-year">Calendar year</label>
                <select id="{{ $resolvedId }}-year" class="ui-month-grid__year" data-calendar-year aria-label="Calendar year"></select>
                <button type="button" class="ui-calendar-icon-action" data-calendar-year-next aria-label="Next year"><x-ui.icon name="chevron-right" size="sm" /></button>
            </div>
            <div class="ui-month-grid" data-calendar-month-grid role="grid" aria-label="Months"></div>
        @else
            <calendar-range data-calendar-day-grid months="2" first-day-of-week="1" page-by="single" locale="en-GB">
                <x-ui.icon slot="previous" name="chevron-left" size="sm" aria-hidden="true" />
                <calendar-select-year slot="heading" max-years="81"></calendar-select-year>
                <x-ui.icon slot="next" name="chevron-right" size="sm" aria-hidden="true" />
                <calendar-month></calendar-month>
                <calendar-month offset="1"></calendar-month>
            </calendar-range>
        @endif

        <div class="ui-calendar-panel__footer">
            <button type="button" class="ui-calendar-text-action" data-calendar-clear>Clear</button>
            <div class="ui-calendar-panel__footer-actions"><button type="button" class="ui-calendar-text-action" data-calendar-cancel>Cancel</button><button type="button" class="ui-calendar-apply-action" data-calendar-apply>Apply</button></div>
        </div>
        <span class="tw-sr-only" data-calendar-live aria-live="polite" aria-atomic="true"></span>
    </div>
</div>
