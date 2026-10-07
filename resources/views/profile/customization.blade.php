@extends('layouts.app')

@section('title', __('customization.title'))
@section('page-title', __('navigation.customization'))

@section('content')
@php
    $submittedQuickAccess = old('quick_access');
    $quickAccessSelection = is_array($submittedQuickAccess) ? $submittedQuickAccess : $selectedQuickAccess;

    $submittedDashboard = old('dashboard');
    $dashboardRows = $dashboardWidgets;
    if (is_array($submittedDashboard) && is_array($submittedDashboard['order'] ?? null)) {
        $trustedRows = array_column($dashboardWidgets, null, 'key');
        $orderedRows = [];
        foreach ($submittedDashboard['order'] as $submittedKey) {
            if (is_string($submittedKey) && isset($trustedRows[$submittedKey])) {
                $orderedRows[$submittedKey] = $trustedRows[$submittedKey];
            }
        }
        foreach ($trustedRows as $key => $row) {
            $orderedRows[$key] ??= $row;
        }
        $dashboardRows = array_values($orderedRows);
    }
    $hiddenDashboardKeys = is_array($submittedDashboard)
        ? (is_array($submittedDashboard['hidden'] ?? null) ? $submittedDashboard['hidden'] : [])
        : array_column(array_filter($dashboardWidgets, fn ($widget) => ! $widget['visible']), 'key');
@endphp

<div class="tw-grid tw-gap-5">
    <x-ui.page-header
        :title="__('navigation.customization')"
        :description="__('customization.description')"
        :eyebrow="__('common.fields.account')"
    />

    @if($errors->any())
        <x-ui.alert tone="error" :title="__('customization.review')">
            <ul class="tw-m-0 tw-list-disc tw-pl-5">
                @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('profile.customization.update') }}" id="customizationForm" class="tw-grid tw-gap-5">
        @csrf
        @method('PATCH')
        <input type="hidden" name="supplier_context" value="{{ $supplierContext ?? '' }}">

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="appearance-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="appearance-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.appearance') }}</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface">{{ __('customization.appearance_help') }}</p>
            </header>
            <div class="tw-grid tw-gap-5 tw-p-5">
                <fieldset class="tw-grid tw-gap-2" aria-describedby="theme-help @error('theme') theme-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.theme') }}</legend>
                    <p id="theme-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.theme_help') }}</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-3">
                        @foreach(['light' => __('customization.choices.light'), 'system' => __('customization.choices.system'), 'dark' => __('customization.choices.dark')] as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preferences['theme']) === $value) aria-invalid="{{ $errors->has('theme') ? 'true' : 'false' }}">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('theme')<p id="theme-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>


                <fieldset class="tw-grid tw-gap-2" aria-describedby="accent-help @error('accent') accent-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.accent') }}</legend>
                    <p id="accent-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.accent_help') }}</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach($accentChoices as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="accent" value="{{ $value }}" @checked(old('accent', $preferences['accent']) === $value) aria-invalid="{{ $errors->has('accent') ? 'true' : 'false' }}">
                                <span class="tw-h-4 tw-w-4 tw-rounded-full tw-border tw-border-outline" data-accent-swatch="{{ $value }}" aria-hidden="true"></span>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('accent')<p id="accent-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="tw-grid tw-gap-2" aria-describedby="density-help @error('density') density-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.density') }}</legend>
                    <p id="density-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.density_help') }}</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach(['comfortable' => __('customization.choices.comfortable'), 'compact' => __('customization.choices.compact')] as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="density" value="{{ $value }}" @checked(old('density', $preferences['density']) === $value) aria-invalid="{{ $errors->has('density') ? 'true' : 'false' }}">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('density')<p id="density-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="tw-grid tw-gap-2" aria-describedby="sidebar-state-help @error('sidebar_state') sidebar-state-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.sidebar_default') }}</legend>
                    <p id="sidebar-state-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.sidebar_default_help') }}</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach(['expanded' => __('customization.choices.expanded'), 'collapsed' => __('customization.choices.collapsed')] as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="sidebar_state" value="{{ $value }}" @checked(old('sidebar_state', $preferences['sidebar_state']) === $value) aria-invalid="{{ $errors->has('sidebar_state') ? 'true' : 'false' }}">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('sidebar_state')<p id="sidebar-state-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>
            </div>
        </section>

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="data-display-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="data-display-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.data_display') }}</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.data_help') }}</p>
            </header>
            <div class="tw-grid tw-max-w-sm tw-gap-2 tw-p-5">
                <label for="page_size" class="tw-text-ui-sm tw-font-semibold">{{ __('customization.rows') }}</label>
                <select id="page_size" name="page_size" class="ui-input tw-min-h-10 tw-w-full" aria-invalid="{{ $errors->has('page_size') ? 'true' : 'false' }}" @error('page_size') aria-describedby="page-size-error" @enderror>
                    @foreach(config('user_preferences.page_sizes') as $size)
                        <option value="{{ $size }}" @selected((int) old('page_size', $preferences['page_size']) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
                @error('page_size')<p id="page-size-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
            </div>
        </section>


        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="language-title">
            <fieldset class="tw-grid tw-gap-3 tw-p-5" aria-describedby="language-help @error('locale') language-error @enderror">
                <legend id="language-title" class="tw-text-ui-sm tw-font-semibold">{{ __('customization.language.label') }}</legend>
                <p id="language-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.language.help') }}</p>
                <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                    @foreach(config('user_preferences.locales') as $value => $label)
                        <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm">
                            <input type="radio" name="locale" value="{{ $value }}" @checked(old('locale', $preferences['locale']) === $value) aria-invalid="{{ $errors->has('locale') ? 'true' : 'false' }}" class="tw-h-4 tw-w-4 tw-accent-primary">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('locale')<p id="language-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
            </fieldset>
        </section>

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="regional-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="regional-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.regional') }}</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.regional_help') }}</p>
            </header>
            <fieldset class="tw-grid tw-grid-cols-1 tw-gap-5 tw-p-5 shell:tw-grid-cols-2">
                <legend class="tw-sr-only">{{ __('customization.regional_settings') }}</legend>
                @php
                    $regionalFields = [
                        'timezone' => ['label' => __('customization.timezone'), 'choices' => 'timezones', 'help' => __('customization.timezone_help')],
                        'date_format' => ['label' => __('customization.date_format'), 'choices' => 'date_formats', 'help' => __('customization.date_help')],
                        'time_format' => ['label' => __('customization.time_format'), 'choices' => 'time_formats', 'help' => __('customization.time_help')],
                        'number_format' => ['label' => __('customization.number_format'), 'choices' => 'number_formats', 'help' => __('customization.number_help')],
                    ];
                @endphp
                @foreach($regionalFields as $field => $control)
                    <div class="tw-grid tw-content-start tw-gap-2">
                        <label for="regional-{{ $field }}" class="tw-text-ui-sm tw-font-semibold">{{ $control['label'] }}</label>
                        <select id="regional-{{ $field }}" name="{{ $field }}" class="ui-input ui-preference-option tw-min-h-11 tw-w-full" aria-describedby="regional-{{ $field }}-help{{ $errors->has($field) ? ' regional-'.$field.'-error' : '' }}" aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}">
                            @foreach($regionalChoices[$control['choices']] as $value => $choice)
                                <option value="{{ $value }}" @selected(old($field, $preferences[$field]) === $value)>{{ __($choice['label']) }}</option>
                            @endforeach
                        </select>
                        <div id="regional-{{ $field }}-help" class="tw-grid tw-gap-1 tw-text-ui-xs tw-text-on-surface-variant">
                            <p class="tw-m-0">{{ $control['help'] }}</p>
                            @foreach($regionalChoices[$control['choices']] as $value => $choice)
                                <p class="tw-m-0"><span class="tw-font-semibold">{{ __($choice['label']) }}:</span> {{ __($choice['example']) }}</p>
                            @endforeach
                        </div>
                        @error($field)<p id="regional-{{ $field }}-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </fieldset>
        </section>

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="dashboard-layout-title" data-dashboard-section>
            <header class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <div>
                    <h2 id="dashboard-layout-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.dashboard_layout') }}</h2>
                    <p id="dashboard-layout-help" class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface">{{ __('customization.dashboard_help') }}</p>
                </div>
                @if(count($dashboardRows))
                    <button type="submit" form="resetDashboardLayout" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-9 tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container-high tw-transition-colors">
                        <x-ui.icon name="rotate-ccw" size="xs" />
                        <span>{{ __('customization.reset_layout') }}</span>
                    </button>
                @endif
            </header>
            <div class="tw-grid tw-gap-3 tw-p-5">
                @if(count($dashboardRows))
                    <fieldset aria-describedby="dashboard-layout-help dashboard-layout-error">
                        <legend class="tw-sr-only">{{ __('customization.panels') }}</legend>
                        <ol class="tw-m-0 tw-grid tw-list-none tw-gap-2 tw-p-0" data-dashboard-controls>
                            @foreach($dashboardRows as $widget)
                                <li class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline tw-p-3 tw-transition-colors" data-dashboard-choice data-widget-key="{{ $widget['key'] }}" data-widget-label="{{ $widget['label'] }}" draggable="true">
                                    <input type="hidden" name="dashboard[order][]" value="{{ $widget['key'] }}">
                                    <div class="tw-flex tw-items-center tw-gap-3">
                                        <span class="tw-hidden md:tw-inline-flex tw-cursor-grab tw-items-center tw-text-on-surface-variant active:tw-cursor-grabbing" data-dashboard-handle title="{{ __('customization.drag', ['label' => $widget['label']]) }}" aria-hidden="true">
                                            <x-ui.icon name="grip-vertical" size="sm" />
                                        </span>
                                        <div class="tw-grid tw-gap-1">
                                            <span class="tw-text-ui-sm tw-font-semibold">{{ $widget['label'] }}</span>
                                            @if($widget['required'])
                                                <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.always_shown') }}</span>
                                            @else
                                                <label class="tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-sm">
                                                    <input class="tw-h-4 tw-w-4 tw-accent-primary" type="checkbox" name="dashboard[hidden][]" value="{{ $widget['key'] }}" @checked(in_array($widget['key'], $hiddenDashboardKeys, true)) aria-label="{{ __('customization.hide', ['label' => $widget['label']]) }}" aria-describedby="dashboard-layout-help">
                                                    <span>{{ __('customization.hide_panel') }}</span>
                                                </label>
                                            @endif
                                        </div>
                                    </div>
                                    @unless($widget['required'])
                                        <div class="tw-flex tw-flex-wrap tw-gap-2">
                                            <button type="button" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-xs hover:tw-bg-surface-container" data-dashboard-move="up" aria-label="{{ __('customization.move_up_label', ['label' => $widget['label']]) }}"><x-ui.icon name="arrow-up" size="sm" />{{ __('customization.move_up') }}</button>
                                            <button type="button" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-xs hover:tw-bg-surface-container" data-dashboard-move="down" aria-label="{{ __('customization.move_down_label', ['label' => $widget['label']]) }}"><x-ui.icon name="arrow-down" size="sm" />{{ __('customization.move_down') }}</button>
                                        </div>
                                    @endunless
                                </li>
                            @endforeach
                        </ol>
                    </fieldset>
                    <noscript><p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.no_js') }}</p></noscript>
                @else
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">{{ __('customization.no_panels') }}</p>
                @endif
                <p id="dashboard-layout-error" class="tw-m-0 tw-text-ui-xs tw-text-error">
                    @if($errors->has('dashboard') || $errors->has('dashboard.*')){{ __('customization.dashboard_errors_help') }}@endif
                </p>
                <p class="tw-sr-only" role="status" aria-live="polite" aria-atomic="true" data-dashboard-status></p>
            </div>
        </section>

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="quick-access-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="quick-access-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('navigation.quick_access') }}</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                    {{ __('customization.shortcut_limit', ['count' => $quickAccessLimit]) }}
                    @if(auth()->user()->isSupplier() && $supplierContext === null)
                        {{ __('customization.choose_portal') }}
                    @endif
                </p>
            </header>
            <div class="tw-grid tw-gap-2 tw-p-5">
                @if(count($quickAccessChoices))
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach($quickAccessChoices as $key => $choice)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-shrink-0 tw-accent-primary" type="checkbox" name="quick_access[]" value="{{ $key }}" @checked(in_array($key, $quickAccessSelection, true)) aria-describedby="quick-access-help @error('quick_access') quick-access-error @enderror" @error('quick_access') aria-invalid="true" @enderror>
                                <x-ui.icon :name="$choice['icon']" size="sm" class="tw-text-on-surface-variant" />
                                <span>{{ $choice['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">{{ __('customization.no_shortcuts') }}</p>
                @endif
                <p id="quick-access-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.shortcut_help') }}</p>
                @error('quick_access')<p id="quick-access-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
            </div>
        </section>

        <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2">
            <button type="submit" form="resetCustomization" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-10 tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-px-3.5 tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">{{ __('customization.reset_default') }}</button>
            <x-ui.button type="submit">{{ __('common.actions.save') }}</x-ui.button>
        </div>
    </form>

    <form method="POST" action="{{ route('profile.customization.reset') }}" id="resetCustomization" class="tw-sr-only" aria-hidden="true" tabindex="-1">
        @csrf
        @method('DELETE')
    </form>

    <form method="POST" action="{{ route('profile.customization.reset') }}" id="resetDashboardLayout" class="tw-sr-only" aria-hidden="true" tabindex="-1">
        @csrf
        @method('DELETE')
        <input type="hidden" name="scope" value="dashboard">
    </form>
</div>

@push('scripts')
<script>
    document.querySelector('[form="resetCustomization"]')?.addEventListener('click', (event) => {
        event.preventDefault();
        window.AdasiAlert.confirm({
            title: @js(__('customization.reset_confirm')),
            text: @js(__('customization.reset_help')),
            confirmText: @js(__('customization.reset_action')),
            cancelText: @js(__('customization.keep')),
        }).then((result) => {
            if (result.isConfirmed) document.getElementById('resetCustomization')?.requestSubmit();
        });
    });

    document.querySelector('[form="resetDashboardLayout"]')?.addEventListener('click', (event) => {
        event.preventDefault();
        window.AdasiAlert.confirm({
            title: @js(__('customization.reset_dashboard_confirm')),
            text: @js(__('customization.reset_dashboard_help')),
            confirmText: @js(__('customization.reset_dashboard_action')),
            cancelText: @js(__('customization.keep_layout')),
        }).then((result) => {
            if (result.isConfirmed) document.getElementById('resetDashboardLayout')?.requestSubmit();
        });
    });
</script>
@endpush
@endsection
