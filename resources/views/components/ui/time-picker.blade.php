@props([
    'name',
    'id' => null,
    'label' => null,
    'value' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    'step' => 5,
])

@php
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    $resolvedValue = old($validationKey, $value);
    $step = max(1, min(30, (int) $step));
    $panelId = $resolvedId.'-time-panel';
    $hintId = $resolvedId.'-hint';
    $helperId = $helper ? $resolvedId.'-help' : null;
    $errorId = $resolvedId.'-error';
    $describedBy = collect([$hintId, $helperId, $errorId])->filter()->implode(' ');
    $choose = __('common.time.choose', ['label' => $label ?: __('common.time.hour')]);
@endphp

{{-- Text combobox + hour/minute listboxes. Without JavaScript it stays a plain HH:MM text field. --}}
<div
    {{ $attributes->only('class')->class(['ui-time-picker tw:grid tw:gap-1.5']) }}
    data-adasi-time-picker
    data-time-step="{{ $step }}"
    data-time-zone="{{ \App\Support\BusinessTime::tz() }}"
    data-time-invalid="{{ __('common.time.invalid') }}"
    data-time-selected="{{ __('common.time.selected', ['time' => ':time']) }}"
>
    @if($label)
        <label for="{{ $resolvedId }}" class="tw:text-ui-sm tw:font-medium tw:text-on-surface">
            {{ $label }}
            @if($required)<span class="tw:text-error" aria-hidden="true">*</span><span class="tw:sr-only"> {{ __('common.required') }}</span>@endif
        </label>
    @endif

    <div class="ui-time-picker__field">
        <input
            id="{{ $resolvedId }}"
            name="{{ $name }}"
            type="text"
            value="{{ $resolvedValue }}"
            inputmode="numeric"
            autocomplete="off"
            maxlength="5"
            placeholder="{{ __('common.time.placeholder') }}"
            pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
            role="combobox"
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-controls="{{ $panelId }}"
            aria-autocomplete="none"
            aria-describedby="{{ $describedBy }}"
            @if($message) aria-invalid="true" @endif
            @required($required)
            @disabled($disabled)
            data-time-input
            {{ $attributes->except('class')->class([
                'ui-time-picker__input ui-motion tw:min-h-[var(--ui-control-height-md)] tw:w-full tw:rounded-ui-sm tw:border tw:bg-surface tw:py-2 tw:ps-3 tw:pe-10 tw:text-ui-sm tw:text-on-surface tw:shadow-none tw:placeholder:text-on-surface-variant tw:focus:border-primary tw:focus:ring-2 tw:focus:ring-primary tw:disabled:cursor-not-allowed tw:disabled:bg-surface-container tw:disabled:opacity-50 ui-tabular-nums',
                'tw:border-error' => $message,
                'tw:border-outline-strong' => ! $message,
            ]) }}
        >
        <span class="ui-time-picker__icon" aria-hidden="true"><x-ui.icon name="clock" size="sm" /></span>
    </div>

    <p id="{{ $hintId }}" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('common.time.format_hint', ['zone' => \App\Support\BusinessTime::label()]) }}</p>
    @if($helper)
        <p id="{{ $helperId }}" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ $helper }}</p>
    @endif
    <p id="{{ $errorId }}" data-time-error class="tw:m-0 tw:flex tw:items-start tw:gap-1.5 tw:text-ui-xs tw:font-medium tw:text-error {{ $message ? '' : 'tw:hidden' }}" role="alert">
        <x-ui.icon name="circle-alert" size="sm" class="tw:mt-0.5" />
        <span data-time-error-message>{{ $message }}</span>
    </p>

    <div id="{{ $panelId }}" class="ui-time-panel" data-time-panel role="dialog" aria-modal="false" aria-label="{{ $choose }}" hidden>
        <div class="ui-time-panel__columns">
            <div class="ui-time-panel__column">
                <span id="{{ $resolvedId }}-hours" class="ui-time-panel__heading">{{ __('common.time.hour') }}</span>
                <ul role="listbox" aria-labelledby="{{ $resolvedId }}-hours" class="ui-time-panel__list" data-time-list="hour">
                    @for($hour = 0; $hour < 24; $hour++)
                        <li role="option" id="{{ $resolvedId }}-hour-{{ $hour }}" class="ui-time-panel__option" data-value="{{ $hour }}" aria-selected="false" tabindex="-1">{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}</li>
                    @endfor
                </ul>
            </div>
            <div class="ui-time-panel__column">
                <span id="{{ $resolvedId }}-minutes" class="ui-time-panel__heading">{{ __('common.time.minute') }}</span>
                <ul role="listbox" aria-labelledby="{{ $resolvedId }}-minutes" class="ui-time-panel__list" data-time-list="minute">
                    @for($minute = 0; $minute < 60; $minute += $step)
                        <li role="option" id="{{ $resolvedId }}-minute-{{ $minute }}" class="ui-time-panel__option" data-value="{{ $minute }}" aria-selected="false" tabindex="-1">{{ str_pad((string) $minute, 2, '0', STR_PAD_LEFT) }}</li>
                    @endfor
                </ul>
            </div>
        </div>
        <div class="ui-time-panel__footer">
            <button type="button" class="ui-calendar-footer-btn" data-time-now>{{ __('common.time.now') }}</button>
            @unless($required)
                <button type="button" class="ui-calendar-footer-btn" data-time-clear>{{ __('common.time.clear') }}</button>
            @endunless
            <button type="button" class="ui-calendar-footer-btn ui-calendar-footer-btn--primary" data-time-done>{{ __('common.time.done') }}</button>
        </div>
        <span class="tw:sr-only" aria-live="polite" aria-atomic="true" data-time-live></span>
    </div>
</div>
