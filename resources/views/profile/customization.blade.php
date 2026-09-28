@extends('layouts.app')

@section('title', 'Customization - ADASI Supplier Portal')
@section('page-title', 'Customization')

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
        title="Customization"
        description="Personalize how your Supplier Portal looks and behaves."
        eyebrow="Account"
    />

    @if($errors->any())
        <x-ui.alert tone="error" title="Review your customization">
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
                <h2 id="appearance-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Appearance</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface">Choose the theme, accent, density, and starting sidebar state for your account.</p>
            </header>
            <div class="tw-grid tw-gap-5 tw-p-5">
                <fieldset class="tw-grid tw-gap-2" aria-describedby="theme-help @error('theme') theme-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">Theme</legend>
                    <p id="theme-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">System follows your device appearance.</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-3">
                        @foreach(['light' => 'Light', 'system' => 'System', 'dark' => 'Dark'] as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preferences['theme']) === $value) aria-invalid="@error('theme') true @else false @enderror">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('theme')<p id="theme-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>


                <fieldset class="tw-grid tw-gap-2" aria-describedby="accent-help @error('accent') accent-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">Accent Color</legend>
                    <p id="accent-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Preview an accent for links and primary actions. Save Changes to keep it.</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach($accentChoices as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="accent" value="{{ $value }}" @checked(old('accent', $preferences['accent']) === $value) aria-invalid="@error('accent') true @else false @enderror">
                                <span class="tw-h-4 tw-w-4 tw-rounded-full tw-border tw-border-outline" data-accent-swatch="{{ $value }}" aria-hidden="true"></span>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('accent')<p id="accent-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="tw-grid tw-gap-2" aria-describedby="density-help @error('density') density-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">Interface Density</legend>
                    <p id="density-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Compact spacing is for data-heavy pages. Action and security controls retain usable sizes.</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach(['comfortable' => 'Comfortable', 'compact' => 'Compact'] as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="density" value="{{ $value }}" @checked(old('density', $preferences['density']) === $value) aria-invalid="@error('density') true @else false @enderror">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('density')<p id="density-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="tw-grid tw-gap-2" aria-describedby="sidebar-state-help @error('sidebar_state') sidebar-state-error @enderror">
                    <legend class="tw-text-ui-sm tw-font-semibold">Sidebar Default</legend>
                    <p id="sidebar-state-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">This is the desktop starting state. The mobile navigation remains unchanged.</p>
                    <div class="tw-grid tw-grid-cols-1 tw-gap-2 shell:tw-grid-cols-2">
                        @foreach(['expanded' => 'Expanded', 'collapsed' => 'Collapsed'] as $value => $label)
                            <label class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="radio" name="sidebar_state" value="{{ $value }}" @checked(old('sidebar_state', $preferences['sidebar_state']) === $value) aria-invalid="@error('sidebar_state') true @else false @enderror">
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
                <h2 id="data-display-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Data Display</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Set the initial number of rows in supported tables.</p>
            </header>
            <div class="tw-grid tw-max-w-sm tw-gap-2 tw-p-5">
                <label for="page_size" class="tw-text-ui-sm tw-font-semibold">Rows per page</label>
                <select id="page_size" name="page_size" class="ui-input tw-min-h-10 tw-w-full" aria-invalid="@error('page_size') true @else false @enderror" @error('page_size') aria-describedby="page-size-error" @enderror>
                    @foreach(config('user_preferences.page_sizes') as $size)
                        <option value="{{ $size }}" @selected((int) old('page_size', $preferences['page_size']) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
                @error('page_size')<p id="page-size-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
            </div>
        </section>


        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="dashboard-layout-title" data-dashboard-section>
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="dashboard-layout-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Dashboard Layout</h2>
                <p id="dashboard-layout-help" class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface">Hide optional panels or move them up and down. Required workflow panels stay visible. Save Changes to keep this layout.</p>
            </header>
            <div class="tw-grid tw-gap-3 tw-p-5">
                @if(count($dashboardRows))
                    <fieldset aria-describedby="dashboard-layout-help dashboard-layout-error">
                        <legend class="tw-sr-only">Dashboard panels</legend>
                        <ol class="tw-m-0 tw-grid tw-list-none tw-gap-2 tw-p-0" data-dashboard-controls>
                            @foreach($dashboardRows as $widget)
                                <li class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline tw-p-3" data-dashboard-choice data-widget-key="{{ $widget['key'] }}" data-widget-label="{{ $widget['label'] }}">
                                    <input type="hidden" name="dashboard[order][]" value="{{ $widget['key'] }}">
                                    <div class="tw-grid tw-gap-1">
                                        <span class="tw-text-ui-sm tw-font-semibold">{{ $widget['label'] }}</span>
                                        @if($widget['required'])
                                            <span class="tw-text-ui-xs tw-text-on-surface-variant">Always shown</span>
                                        @else
                                            <label class="tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-sm">
                                                <input class="tw-h-4 tw-w-4 tw-accent-primary" type="checkbox" name="dashboard[hidden][]" value="{{ $widget['key'] }}" @checked(in_array($widget['key'], $hiddenDashboardKeys, true)) aria-label="Hide {{ $widget['label'] }}" aria-describedby="dashboard-layout-help">
                                                <span>Hide panel</span>
                                            </label>
                                        @endif
                                    </div>
                                    @unless($widget['required'])
                                        <div class="tw-flex tw-flex-wrap tw-gap-2">
                                            <button type="button" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-xs hover:tw-bg-surface-container" data-dashboard-move="up" aria-label="Move {{ $widget['label'] }} up"><x-ui.icon name="arrow-up" size="sm" />Move Up</button>
                                            <button type="button" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-xs hover:tw-bg-surface-container" data-dashboard-move="down" aria-label="Move {{ $widget['label'] }} down"><x-ui.icon name="arrow-down" size="sm" />Move Down</button>
                                        </div>
                                    @endunless
                                </li>
                            @endforeach
                        </ol>
                    </fieldset>
                    <noscript><p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Panel visibility can be saved here. Enable JavaScript to use the reorder buttons.</p></noscript>
                @else
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">Choose a supplier portal to customize its dashboard. No operational panels are available in this context.</p>
                @endif
                <p id="dashboard-layout-error" class="tw-m-0 tw-text-ui-xs tw-text-error">
                    @if($errors->has('dashboard') || $errors->has('dashboard.*'))Review the dashboard errors above and choose only the panels listed here.@endif
                </p>
                <p class="tw-sr-only" role="status" aria-live="polite" aria-atomic="true" data-dashboard-status></p>
            </div>
        </section>

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="quick-access-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="quick-access-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Quick Access</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                    Choose up to {{ $quickAccessLimit }} shortcuts across your account contexts. Shortcuts never change page access.
                    @if(auth()->user()->isSupplier() && $supplierContext === null)
                        Choose a supplier portal before selecting shortcuts. Appearance settings can still be saved.
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
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">No shortcuts are available for this account context.</p>
                @endif
                <p id="quick-access-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Your original navigation remains available. Saved shortcuts must still be available to your role and active supplier portal.</p>
                @error('quick_access')<p id="quick-access-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
            </div>
        </section>

        <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2">
            <button type="submit" form="resetCustomization" class="ui-button ui-focus-ring tw-inline-flex tw-min-h-10 tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-px-3.5 tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">Reset to Default</button>
            <x-ui.button type="submit">Save Changes</x-ui.button>
        </div>
    </form>

    <form method="POST" action="{{ route('profile.customization.reset') }}" id="resetCustomization" class="tw-sr-only" aria-hidden="true" tabindex="-1">
        @csrf
        @method('DELETE')
    </form>
</div>

@push('scripts')
<script>
    document.querySelector('[form="resetCustomization"]')?.addEventListener('click', (event) => {
        event.preventDefault();
        window.AdasiAlert.confirm({
            title: 'Reset customization?',
            text: 'Appearance, dashboard layout, sidebar, and shortcut preferences will return to their defaults.',
            confirmText: 'Reset preferences',
            cancelText: 'Keep preferences',
        }).then((result) => {
            if (result.isConfirmed) document.getElementById('resetCustomization')?.requestSubmit();
        });
    });
</script>
@endpush
@endsection
