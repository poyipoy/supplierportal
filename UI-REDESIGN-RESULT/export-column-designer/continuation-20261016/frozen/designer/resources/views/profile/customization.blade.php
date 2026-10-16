@extends('layouts.app')

@section('title', __('customization.title'))
@section('page-title', __('navigation.customization'))

@section('content')
@php
    $sectionNav = [
        'appearance' => __('customization.sections.appearance'),
        'language-region' => __('customization.sections.region'),
        'dashboard-layout' => __('customization.dashboard_layout'),
        'quick-access' => __('customization.sections.quick_access'),
    ];

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

    // Baseline for client-side dirty tracking: always the persisted layout, never the
    // old()-reassembled $dashboardRows above (which only reflects a just-rejected submission).
    $dashboardSavedOrder = array_column($dashboardWidgets, 'key');
    $dashboardSavedHidden = array_column(array_filter($dashboardWidgets, fn ($widget) => ! $widget['visible']), 'key');

    $alpineConfig = [
        'saved' => [
            'theme' => $preferences['theme'], 'accent' => $preferences['accent'], 'density' => $preferences['density'],
            'sidebar_state' => $preferences['sidebar_state'], 'page_size' => (string) $preferences['page_size'],
            'locale' => $preferences['locale'], 'timezone' => $preferences['timezone'], 'date_format' => $preferences['date_format'],
            'time_format' => $preferences['time_format'], 'number_format' => $preferences['number_format'],
            'dashboardOrder' => $dashboardSavedOrder, 'dashboardHidden' => $dashboardSavedHidden,
            'quickAccess' => $selectedQuickAccess,
        ],
        'defaults' => [
            'theme' => $defaults['theme'], 'accent' => $defaults['accent'], 'density' => $defaults['density'],
            'sidebar_state' => $defaults['sidebar_state'], 'page_size' => (string) $defaults['page_size'],
            'timezone' => $defaults['timezone'], 'date_format' => $defaults['date_format'],
            'time_format' => $defaults['time_format'], 'number_format' => $defaults['number_format'],
            'dashboardOrder' => $dashboardDefaultOrder,
        ],
        'otherContextCount' => $otherContextQuickAccessCount,
        'quickAccessLimit' => $quickAccessLimit,
        'hasServerErrors' => $errors->any(),
        'sampleIso' => $regionalSample['iso'],
        'sampleNumber' => $regionalSample['number'],
        'registry' => $regionalFormatRegistry,
        'locale' => $preferences['locale'],
        'copy' => [
            'unsaved' => __('customization.unsaved_changes', ['count' => ':count']),
            'noChanges' => __('customization.no_unsaved_changes'),
            'sectionReset' => __('customization.reset_section_announcement'),
            'discarded' => __('customization.discard_announcement'),
            'followsCurrentDisplay' => __('customization.live_sample_system'),
            'quickAccessCount' => __('customization.quick_access_count', ['count' => ':count', 'limit' => ':limit']),
            'quickAccessLimitReached' => __('customization.quick_access_limit_reached'),
        ],
    ];
@endphp

<x-account.shell active="customization">
    <x-slot:sections>
        @foreach($sectionNav as $sectionId => $sectionLabel)
            <li>
                <a href="#{{ $sectionId }}" data-section-nav-link class="tw-flex tw-min-h-9 tw-items-center tw-px-4 tw-py-2 tw-text-ui-xs tw-text-on-surface-variant tw-no-underline tw-transition-colors hover:tw-text-on-surface">
                    {{ $sectionLabel }}
                </a>
            </li>
        @endforeach
    </x-slot:sections>
    <x-slot:mobileSections>
        @foreach($sectionNav as $sectionId => $sectionLabel)
            <a href="#{{ $sectionId }}" data-section-nav-link class="tw-flex tw-shrink-0 tw-items-center tw-border-b-2 tw-border-transparent tw-px-3 tw-py-2 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant tw-no-underline tw-transition-colors">
                {{ $sectionLabel }}
            </a>
        @endforeach
    </x-slot:mobileSections>

    <div class="tw-grid tw-gap-5">
        <x-ui.page-header
            :title="__('navigation.customization')"
            :description="__('customization.description')"
        />

        @if($errors->any())
            <x-ui.alert tone="error" :title="__('customization.review')">
                <ul class="tw-m-0 tw-list-disc tw-pl-5">
                    @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            </x-ui.alert>
        @endif

        <form
            method="POST"
            action="{{ route('profile.customization.update') }}"
            id="customizationForm"
            class="tw-grid tw-gap-5"
            x-data="customizationForm(@js($alpineConfig))"
        >
            @csrf
            @method('PATCH')
            <input type="hidden" name="supplier_context" value="{{ $supplierContext ?? '' }}">

            {{-- Appearance & display ----------------------------------------------------- --}}
            <section id="appearance" data-settings-section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="appearance-title">
                <header class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                    <div>
                        <h2 id="appearance-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.sections.appearance') }}</h2>
                        <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.sections.appearance_help') }}</p>
                    </div>
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="resetSection('appearance')">{{ __('customization.reset_section') }}</x-ui.button>
                </header>
                <div class="tw-grid tw-gap-5 tw-p-5">
                    <div class="tw-grid tw-grid-cols-1 tw-gap-5 shell:tw-grid-cols-2">
                        <fieldset class="tw-grid tw-content-start tw-gap-2" aria-describedby="theme-help @error('theme') theme-error @enderror">
                            <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.theme') }}</legend>
                            <p id="theme-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.theme_help') }}</p>
                            <div class="ui-segmented" role="radiogroup" aria-label="{{ __('customization.theme') }}">
                                @foreach(['light' => __('customization.choices.light'), 'system' => __('customization.choices.system'), 'dark' => __('customization.choices.dark')] as $value => $label)
                                    <label class="ui-segmented__option">
                                        <input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preferences['theme']) === $value) aria-invalid="{{ $errors->has('theme') ? 'true' : 'false' }}" x-on:change="previewTheme('{{ $value }}')">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('theme')<p id="theme-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                        </fieldset>

                        <fieldset class="tw-grid tw-content-start tw-gap-2" aria-describedby="density-help @error('density') density-error @enderror">
                            <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.density') }}</legend>
                            <p id="density-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.density_help') }}</p>
                            <div class="ui-segmented" role="radiogroup" aria-label="{{ __('customization.density') }}">
                                @foreach(['comfortable' => __('customization.choices.comfortable'), 'compact' => __('customization.choices.compact')] as $value => $label)
                                    <label class="ui-segmented__option">
                                        <input type="radio" name="density" value="{{ $value }}" @checked(old('density', $preferences['density']) === $value) aria-invalid="{{ $errors->has('density') ? 'true' : 'false' }}" x-on:change="previewDensity('{{ $value }}')">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('density')<p id="density-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                        </fieldset>

                        <fieldset class="tw-grid tw-content-start tw-gap-2" aria-describedby="sidebar-state-help @error('sidebar_state') sidebar-state-error @enderror">
                            <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.sidebar_default') }}</legend>
                            <p id="sidebar-state-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.sidebar_default_help') }}</p>
                            <div class="ui-segmented" role="radiogroup" aria-label="{{ __('customization.sidebar_default') }}">
                                @foreach(['expanded' => __('customization.choices.expanded'), 'collapsed' => __('customization.choices.collapsed')] as $value => $label)
                                    <label class="ui-segmented__option">
                                        <input type="radio" name="sidebar_state" value="{{ $value }}" @checked(old('sidebar_state', $preferences['sidebar_state']) === $value) aria-invalid="{{ $errors->has('sidebar_state') ? 'true' : 'false' }}">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('sidebar_state')<p id="sidebar-state-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                        </fieldset>

                        <div class="tw-grid tw-content-start tw-gap-2">
                            <label for="page_size" class="tw-text-ui-sm tw-font-semibold">{{ __('customization.rows') }}</label>
                            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.data_help') }}</p>
                            <select id="page_size" name="page_size" class="ui-input tw-min-h-10 tw-w-full tw-max-w-xs" aria-invalid="{{ $errors->has('page_size') ? 'true' : 'false' }}" @error('page_size') aria-describedby="page-size-error" @enderror>
                                @foreach(config('user_preferences.page_sizes') as $size)
                                    <option value="{{ $size }}" @selected((int) old('page_size', $preferences['page_size']) === $size)>{{ $size }}</option>
                                @endforeach
                            </select>
                            @error('page_size')<p id="page-size-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <fieldset class="tw-grid tw-gap-2" aria-describedby="accent-help @error('accent') accent-error @enderror">
                        <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.accent') }}</legend>
                        <p id="accent-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.accent_help') }}</p>
                        <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3" role="radiogroup" aria-label="{{ __('customization.accent') }}">
                            @foreach($accentChoices as $value => $label)
                                <label class="ui-swatch-option">
                                    <input type="radio" name="accent" value="{{ $value }}" @checked(old('accent', $preferences['accent']) === $value) aria-invalid="{{ $errors->has('accent') ? 'true' : 'false' }}" aria-label="{{ $label }}" x-on:change="previewAccent('{{ $value }}')">
                                    <span class="ui-swatch" data-accent-swatch="{{ $value }}" aria-hidden="true"></span>
                                    <span class="tw-text-ui-xs">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('accent')<p id="accent-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                    </fieldset>

                    <div>
                        <p class="tw-m-0 tw-mb-2 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('customization.preview.title') }}</p>
                        <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-border tw-border-outline tw-bg-surface-container tw-p-4">
                            <x-ui.button size="sm">{{ __('customization.preview.primary_action') }}</x-ui.button>
                            <x-ui.button variant="outline" size="sm">{{ __('customization.preview.secondary_action') }}</x-ui.button>
                            <x-ui.status-chip tone="success">{{ __('customization.preview.status_a') }}</x-ui.status-chip>
                            <x-ui.status-chip tone="warning">{{ __('customization.preview.status_b') }}</x-ui.status-chip>
                        </div>
                        <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.preview.help') }}</p>
                    </div>
                </div>
            </section>

            {{-- Language & region ----------------------------------------------------------- --}}
            <section id="language-region" data-settings-section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="language-region-title">
                <header class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                    <div>
                        <h2 id="language-region-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.sections.region') }}</h2>
                        <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.sections.region_help') }}</p>
                    </div>
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="resetSection('region')">{{ __('customization.reset_section') }}</x-ui.button>
                </header>
                <div class="tw-grid tw-gap-5 tw-p-5">
                    <fieldset class="tw-grid tw-gap-2" aria-describedby="language-help @error('locale') language-error @enderror">
                        <legend class="tw-text-ui-sm tw-font-semibold">{{ __('customization.language.label') }}</legend>
                        <p id="language-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.language.help') }} {{ __('customization.language_applies_after_save') }}</p>
                        <div class="ui-segmented tw-max-w-sm" role="radiogroup" aria-label="{{ __('customization.language.label') }}">
                            @foreach(config('user_preferences.locales') as $value => $label)
                                <label class="ui-segmented__option">
                                    <input type="radio" name="locale" value="{{ $value }}" @checked(old('locale', $preferences['locale']) === $value) aria-invalid="{{ $errors->has('locale') ? 'true' : 'false' }}">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('locale')<p id="language-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                    </fieldset>

                    <div class="tw-grid tw-grid-cols-1 tw-gap-5 shell:tw-grid-cols-2">
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
                                <select id="regional-{{ $field }}" name="{{ $field }}" class="ui-input tw-min-h-10 tw-w-full" aria-describedby="regional-{{ $field }}-help{{ $errors->has($field) ? ' regional-'.$field.'-error' : '' }}" aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}" x-on:change="updateRegionalSample()">
                                    @foreach($regionalChoices[$control['choices']] as $value => $choice)
                                        <option value="{{ $value }}" @selected(old($field, $preferences[$field]) === $value)>{{ __($choice['label']) }}</option>
                                    @endforeach
                                </select>
                                <p id="regional-{{ $field }}-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $control['help'] }}</p>
                                @error($field)<p id="regional-{{ $field }}-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <p class="tw-m-0 tw-mb-1 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('customization.live_sample_label') }}</p>
                        <p class="tw-m-0 tw-border tw-border-outline tw-bg-surface-container tw-px-4 tw-py-3 tw-text-ui-sm" style="font-variant-numeric: tabular-nums;" x-text="regionalSample"></p>
                    </div>
                </div>
            </section>

            {{-- Dashboard layout --------------------------------------------------------------- --}}
            <section id="dashboard-layout" data-settings-section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="dashboard-layout-title" data-dashboard-section>
                <header class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                    <div>
                        <h2 id="dashboard-layout-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.dashboard_layout') }}</h2>
                        <p id="dashboard-layout-help" class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.dashboard_help') }}</p>
                    </div>
                    @if(count($dashboardRows))
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="resetSection('dashboard')">{{ __('customization.reset_section') }}</x-ui.button>
                    @endif
                </header>
                <div class="tw-grid tw-gap-3 tw-p-5">
                    @if(count($dashboardRows))
                        <fieldset aria-describedby="dashboard-layout-help dashboard-layout-error">
                            <legend class="tw-sr-only">{{ __('customization.panels') }}</legend>
                            <ol class="tw-m-0 tw-grid tw-list-none tw-gap-2 tw-p-0" data-dashboard-controls>
                                @foreach($dashboardRows as $widget)
                                    <li class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline tw-p-3 tw-transition-colors" data-dashboard-choice data-widget-key="{{ $widget['key'] }}" data-widget-label="{{ $widget['label'] }}" draggable="true">
                                        <input type="hidden" name="dashboard[order][]" value="{{ $widget['key'] }}" data-dashboard-order-input>
                                        <div class="tw-flex tw-items-center tw-gap-3">
                                            <span class="tw-hidden shell:tw-inline-flex tw-cursor-grab tw-items-center tw-text-on-surface-variant active:tw-cursor-grabbing" data-dashboard-handle title="{{ __('customization.drag', ['label' => $widget['label']]) }}" aria-hidden="true">
                                                <x-ui.icon name="grip-vertical" size="sm" />
                                            </span>
                                            <div class="tw-grid tw-gap-1">
                                                <span class="tw-text-ui-sm tw-font-semibold">{{ $widget['label'] }}</span>
                                                @if($widget['required'])
                                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-text-on-surface-variant">
                                                        <x-ui.icon name="lock" size="xs" />{{ __('customization.always_shown') }}
                                                    </span>
                                                @else
                                                    <label class="tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-sm" data-dashboard-hide-fallback>
                                                        <input class="tw-h-4 tw-w-4 tw-accent-primary" type="checkbox" name="dashboard[hidden][]" value="{{ $widget['key'] }}" @checked(in_array($widget['key'], $hiddenDashboardKeys, true)) aria-label="{{ __('customization.hide', ['label' => $widget['label']]) }}" aria-describedby="dashboard-layout-help">
                                                        <span>{{ __('customization.hide_panel') }}</span>
                                                    </label>
                                                    <label class="tw-hidden tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-sm" data-dashboard-visibility-toggle>
                                                        <x-ui.switch :checked="! in_array($widget['key'], $hiddenDashboardKeys, true)" aria-label="{{ __('customization.show', ['label' => $widget['label']]) }}" />
                                                        <span>{{ __('customization.dashboard_visible') }}</span>
                                                    </label>
                                                @endif
                                            </div>
                                        </div>
                                        @unless($widget['required'])
                                            <div class="tw-flex tw-flex-wrap tw-gap-1.5">
                                                <button type="button" class="ui-button ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface hover:tw-bg-surface-container" data-dashboard-move="up" aria-label="{{ __('customization.move_up_label', ['label' => $widget['label']]) }}"><x-ui.icon name="arrow-up" size="sm" /></button>
                                                <button type="button" class="ui-button ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface hover:tw-bg-surface-container" data-dashboard-move="down" aria-label="{{ __('customization.move_down_label', ['label' => $widget['label']]) }}"><x-ui.icon name="arrow-down" size="sm" /></button>
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

            {{-- Shortcuts ------------------------------------------------------------------------ --}}
            <section id="quick-access" data-settings-section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="quick-access-title">
                <header class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                    <div>
                        <h2 id="quick-access-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('customization.sections.quick_access') }}</h2>
                        <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant" x-text="copy.quickAccessCount.replace(':count', quickAccessSelectedCount).replace(':limit', quickAccessLimit)"></p>
                        @if(auth()->user()->isSupplier() && $supplierContext === null)
                            <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.choose_portal') }}</p>
                        @endif
                    </div>
                    @if(count($quickAccessChoices))
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="resetSection('quick_access')">{{ __('customization.reset_section') }}</x-ui.button>
                    @endif
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
                        <p class="tw-m-0 tw-text-ui-xs tw-text-error" x-show="quickAccessSelectedCount >= quickAccessLimit" x-cloak>{{ __('customization.quick_access_limit_reached') }}</p>
                    @else
                        <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">{{ __('customization.no_shortcuts') }}</p>
                    @endif
                    <p id="quick-access-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('customization.shortcut_help') }}</p>
                    @error('quick_access')<p id="quick-access-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </div>
            </section>

            <p class="tw-sr-only" role="status" aria-live="polite" aria-atomic="true" data-settings-status></p>

            <x-ui.action-bar>
                <x-slot:left>
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="resetAll()">{{ __('customization.reset_all') }}</x-ui.button>
                    <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-ui-xs" role="status" aria-live="polite">
                        <template x-if="dirtyCount > 0">
                            <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-font-medium tw-text-warning">
                                <span class="tw-h-2 tw-w-2 tw-rounded-full tw-bg-warning" aria-hidden="true"></span>
                                <span x-text="copy.unsaved.replace(':count', dirtyCount)"></span>
                            </span>
                        </template>
                        <template x-if="dirtyCount === 0">
                            <span class="tw-text-on-surface-variant" x-text="copy.noChanges"></span>
                        </template>
                    </span>
                </x-slot:left>
                <x-slot:right>
                    <x-ui.button type="button" variant="ghost" x-on:click="discard()" x-bind:disabled="dirtyCount === 0" disabled>
                        {{ __('customization.discard') }}
                    </x-ui.button>
                    <x-ui.button type="submit" x-bind:disabled="saveDisabled">
                        {{ __('common.actions.save') }}
                    </x-ui.button>
                </x-slot:right>
            </x-ui.action-bar>
        </form>
    </div>
</x-account.shell>
@endsection
