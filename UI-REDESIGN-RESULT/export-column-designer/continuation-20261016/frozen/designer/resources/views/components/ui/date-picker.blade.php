@props([
    'name',
    'id' => null,
    'label' => null,
    'value' => null,
    'min' => null,
    'max' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'allowedDaysOfWeek' => null,
    'allowedDaysMessage' => null,
])

@php
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    $resolvedValue = old($validationKey, $value);
    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $resolvedId . '-error';
    $labelId = $resolvedId . '-label';
    $panelId = $resolvedId . '-calendar-panel';
    $describedBy = collect([$helperId, $errorId])->filter()->implode(' ');
    $dateDisplay = __('common.calendar.choose_date');
    if ($resolvedValue && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $resolvedValue)) {
        [$year, $month, $day] = explode('-', (string) $resolvedValue);
        $dateDisplay = date('d M Y', mktime(0, 0, 0, (int) $month, (int) $day, (int) $year));
    }
    $allowedDaysAttr = null;
    if ($allowedDaysOfWeek !== null) {
        $allowedDaysAttr = is_array($allowedDaysOfWeek) ? implode(',', $allowedDaysOfWeek) : (string) $allowedDaysOfWeek;
    }
@endphp

<div
    {{ $attributes->only('class')->class(['ui-calendar ui-date-picker tw-grid tw-gap-1.5']) }}
    data-adasi-date-picker
    data-calendar-required="{{ $required ? 'true' : 'false' }}"
    @if($allowedDaysAttr !== null) data-calendar-allowed-days="{{ $allowedDaysAttr }}" @endif
    @if($allowedDaysMessage) data-calendar-allowed-days-message="{{ $allowedDaysMessage }}" @endif
>
    <div data-calendar-native>
        @if($label)
            <label id="{{ $labelId }}" for="{{ $resolvedId }}" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                {{ $label }}
                @if($required)<span class="tw-text-error" aria-hidden="true">*</span><span class="tw-sr-only"> {{ __('common.required') }}</span>@endif
            </label>
        @endif

        <input
            id="{{ $resolvedId }}"
            name="{{ $name }}"
            type="date"
            value="{{ $resolvedValue }}"
            @if($min) min="{{ $min }}" @endif
            @if($max) max="{{ $max }}" @endif
            @required($required)
            @disabled($disabled)
            @readonly($readonly)
            data-calendar-native-input
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($message) aria-invalid="true" @endif
            {{ $attributes->except('class')->class([
                'ui-calendar__native-input ui-motion tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface tw-shadow-none placeholder:tw-text-on-surface-variant focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary disabled:tw-cursor-not-allowed disabled:tw-bg-surface-container disabled:tw-opacity-50',
                'tw-border-error' => $message,
                'tw-border-outline-strong' => !$message,
            ]) }}
        >
    </div>

    <div data-calendar-enhanced hidden>
        @if($label)
            <span id="{{ $labelId }}-enhanced" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                {{ $label }}
                @if($required)<span class="tw-text-error" aria-hidden="true">*</span><span class="tw-sr-only"> {{ __('common.required') }}</span>@endif
            </span>
        @endif
        <button
            type="button"
            class="ui-calendar-trigger"
            data-calendar-trigger
            data-calendar-label="{{ $label ?: __('common.calendar.choose_date') }}"
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-controls="{{ $panelId }}"
            aria-label="{{ $label ?: __('common.calendar.choose_date') }}"
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @disabled($disabled || $readonly)
        >
            <span data-calendar-display>{{ $dateDisplay }}</span>
            <x-ui.icon name="calendar-days" size="sm" aria-hidden="true" />
        </button>
    </div>

    @if($helper)
        <p id="{{ $helperId }}" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $helper }}</p>
    @endif
    <p id="{{ $errorId }}" data-calendar-error class="tw-m-0 tw-flex tw-items-start tw-gap-1.5 tw-text-ui-xs tw-font-medium tw-text-error {{ $message ? '' : 'tw-hidden' }}" role="alert">
        <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5" />
        <span data-calendar-error-message>{{ $message }}</span>
    </p>

    <div id="{{ $panelId }}" class="ui-calendar-panel ui-calendar-panel--single" data-calendar-panel hidden role="dialog" aria-modal="false" aria-label="{{ __('common.calendar.choose_date') }}">
        <div class="ui-calendar-panel__topline">
            <span class="ui-calendar-panel__title">{{ __('common.calendar.choose_date') }}</span>
            <button type="button" class="ui-calendar-panel__close" data-calendar-close aria-label="{{ __('common.calendar.close') }}"><x-ui.icon name="x" size="sm" /></button>
        </div>

        <div class="ui-calendar-nav">
            <button type="button" class="ui-calendar-nav-btn" data-calendar-prev aria-label="{{ __('common.calendar.previous_month') }}">
                <x-ui.icon name="chevron-left" size="sm" aria-hidden="true" />
            </button>

            <button type="button" class="ui-calendar-month-year" data-calendar-year-toggle aria-label="{{ __('common.calendar.toggle_year') }}" aria-expanded="false">
                <span class="ui-calendar-month-label" data-calendar-month-label>{{ __('js.calendar.month_8') }}</span>
                <span class="ui-calendar-year-label" data-calendar-year-label>2026</span>
            </button>

            <button type="button" class="ui-calendar-nav-btn" data-calendar-next aria-label="{{ __('common.calendar.next_month') }}">
                <x-ui.icon name="chevron-right" size="sm" aria-hidden="true" />
            </button>
        </div>

        <div class="ui-calendar-year-panel" data-calendar-year-panel hidden></div>

        <div class="ui-calendar-body" data-calendar-body>
            <div class="ui-calendar-weekdays" aria-hidden="true">
                @foreach(__('common.calendar.weekdays') as $weekday)<span>{{ $weekday }}</span>@endforeach
            </div>
            <div class="ui-calendar-days" data-calendar-days-grid role="grid" aria-label="{{ __('common.calendar.days') }}"></div>
        </div>

        <div class="ui-calendar-panel__footer ui-calendar-panel__footer--single">
            <button type="button" class="ui-calendar-footer-btn" data-calendar-today>{{ __('common.calendar.today') }}</button>
            @if(!$required)<button type="button" class="ui-calendar-footer-btn ui-calendar-footer-btn--primary" data-calendar-clear>{{ __('common.actions.clear') }}</button>@endif
            <span class="tw-sr-only" data-calendar-live aria-live="polite" aria-atomic="true"></span>
        </div>
    </div>
</div>
