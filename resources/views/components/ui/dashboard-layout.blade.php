@props(['audience'])

@php
    $dashboardUser = request()->user();
    $dashboardService = app(\App\Services\Dashboard\DashboardWidgetService::class);
    $dashboardSaved = $dashboardUser ? app(\App\Services\UserPreferenceService::class)->for($dashboardUser) : [];
    $dashboardLayout = $dashboardUser
        ? $dashboardService->layoutFor($dashboardUser, $dashboardSaved['dashboard_preferences'] ?? [], $audience)
        : [];
    // Only the user's own audience is editable: an admin viewing Finance, or Finance viewing Accounting, reads it as is.
    $ownsAudience = $dashboardUser !== null
        && \Illuminate\Support\Facades\Route::has('profile.dashboard-layout.update')
        && $dashboardService->ownsAudience($dashboardUser, $audience);
    // Literal strings so the Tailwind scanner emits them. Keep identical to SPAN_CLASSES in resources/js/dashboard-layout-core.js.
    $dashboardSpanClasses = [
        3 => 'lg:tw-col-span-3',
        4 => 'lg:tw-col-span-4',
        5 => 'lg:tw-col-span-5',
        6 => 'lg:tw-col-span-6',
        7 => 'lg:tw-col-span-7',
        8 => 'lg:tw-col-span-8',
        9 => 'lg:tw-col-span-9',
        12 => 'lg:tw-col-span-12',
    ];
    $editorConfig = $ownsAudience ? [
        'saveUrl' => route('profile.dashboard-layout.update'),
        'defaultOrder' => array_column($dashboardService->layoutFor($dashboardUser, [], $audience), 'key'),
        'widgets' => array_map(fn (array $widget): array => [
            'key' => $widget['key'],
            'label' => $widget['label'],
            'span' => $widget['span'],
            'required' => (bool) $widget['required'],
            'visible' => $widget['visible'],
            'kind' => $widget['kind'],
        ], $dashboardLayout),
        'copy' => [
            'unsaved' => __('customization.unsaved_changes', ['count' => ':count']),
            'noChanges' => __('customization.no_unsaved_changes'),
        ],
    ] : null;
@endphp

<div {{ $attributes->class(['ui-dashboard-layout tw-grid tw-grid-cols-1 lg:tw-grid-cols-12']) }} data-dashboard-audience="{{ $audience }}">
    @if($ownsAudience)
        <div class="tw-col-span-1 lg:tw-col-span-12 tw-flex tw-justify-end -tw-mb-1" data-editor-toolbar>
            {{-- The button only works with JavaScript, so it stays hidden until the editor boots; without JavaScript the link below opens the Customization page. --}}
            <button type="button"
                    class="tw-inline-flex tw-items-center tw-gap-1.5 tw-border-0 tw-bg-transparent tw-text-ui-xs tw-text-on-surface-variant hover:tw-text-primary tw-font-medium ui-focus-ring tw-rounded-ui-xs tw-px-2 tw-py-1 tw-transition-colors"
                    data-dashboard-customize-link data-editor-enter hidden>
                <x-ui.icon name="sliders-horizontal" size="xs" />
                <span>{{ __('customization.dashboard_customize') }}</span>
            </button>
            @if(\Illuminate\Support\Facades\Route::has('profile.customization'))
                <noscript>
                    <a href="{{ route('profile.customization') }}#dashboard-layout-title"
                       class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-ui-xs tw-text-on-surface-variant hover:tw-text-primary tw-font-medium ui-focus-ring tw-rounded-ui-xs tw-px-2 tw-py-1 tw-transition-colors"
                       data-dashboard-customize-link>
                        <x-ui.icon name="sliders-horizontal" size="xs" />
                        <span>{{ __('common.dashboard.customize') }}</span>
                    </a>
                </noscript>
            @endif
        </div>
    @endif
    @foreach($dashboardLayout as $dashboardWidget)
        @php
            $widgetContent = \Illuminate\Support\Arr::get(get_defined_vars(), $dashboardWidget['slot']);
            $widgetHasContent = $widgetContent instanceof \Illuminate\View\ComponentSlot && ! $widgetContent->isEmpty();
            $widgetSpanClass = $dashboardSpanClasses[$dashboardWidget['effective_span']] ?? $dashboardSpanClasses[12];
        @endphp
        @if($dashboardWidget['visible'] && $widgetHasContent)
            <div {{ $widgetContent->attributes->class(['tw-min-w-0', $widgetSpanClass]) }} data-dashboard-widget="{{ $dashboardWidget['key'] }}" @if($ownsAudience) data-widget-has-content="true" @endif>
                @if($ownsAudience)
                    <x-ui.dashboard-editor-strip :label="$dashboardWidget['label']" :required="$dashboardWidget['required']" :visible="true" />
                @endif
                {{ $widgetContent }}
                @if($ownsAudience)
                    <x-ui.dashboard-editor-ghost :kind="$dashboardWidget['kind']" />
                @endif
            </div>
        @elseif($ownsAudience && ! $dashboardWidget['visible'])
            {{-- A hidden panel renders no data. The owner gets this empty placeholder, shown only in edit mode so the panel can be shown again. --}}
            <div class="tw-min-w-0 {{ $widgetSpanClass }}" data-dashboard-placeholder="{{ $dashboardWidget['key'] }}" hidden>
                <x-ui.dashboard-editor-strip :label="$dashboardWidget['label']" :required="$dashboardWidget['required']" :visible="false" />
                <x-ui.dashboard-editor-ghost :kind="$dashboardWidget['kind']" />
            </div>
        @endif
    @endforeach
    @if($ownsAudience)
        <p class="tw-sr-only" role="status" aria-live="polite" aria-atomic="true" data-editor-announce></p>
        <x-ui.action-bar class="tw-col-span-1 tw-rounded-ui-sm tw-border tw-border-outline lg:tw-col-span-12" role="region" aria-label="{{ __('customization.dashboard_editor_label') }}" data-editor-actionbar hidden>
            <x-slot:left>
                <x-ui.button type="button" variant="ghost" size="sm" data-editor-reset>{{ __('customization.dashboard_editor_reset') }}</x-ui.button>
                <span class="tw-text-ui-xs tw-text-on-surface-variant" role="status" aria-live="polite" data-editor-status></span>
            </x-slot:left>
            <x-slot:right>
                <x-ui.button type="button" variant="outline" data-editor-cancel>{{ __('customization.dashboard_editor_cancel') }}</x-ui.button>
                <x-ui.button type="button" :disabled="true" data-editor-save>
                    <span class="ui-spinner" aria-hidden="true" data-editor-saving hidden></span>
                    {{ __('customization.dashboard_editor_save') }}
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
        <script type="application/json" data-editor-config>{!! json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
</div>
