@props(['audience'])

@php
    $dashboardUser = request()->user();
    $dashboardSaved = $dashboardUser ? app(\App\Services\UserPreferenceService::class)->for($dashboardUser) : [];
    $dashboardLayout = $dashboardUser
        ? app(\App\Services\Dashboard\DashboardWidgetService::class)->layoutFor($dashboardUser, $dashboardSaved['dashboard_preferences'] ?? [], $audience)
        : [];
@endphp

<div {{ $attributes->class(['tw-grid tw-grid-cols-1 tw-gap-4 lg:tw-grid-cols-12']) }} data-dashboard-audience="{{ $audience }}">
    @if($dashboardUser && \Illuminate\Support\Facades\Route::has('profile.customization'))
        <div class="tw-col-span-12 tw-flex tw-justify-end -tw-mb-1">
            <a href="{{ route('profile.customization') }}#dashboard-layout-title"
               class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-ui-xs tw-text-on-surface-variant hover:tw-text-primary tw-font-medium ui-focus-ring tw-rounded-ui-xs tw-px-2 tw-py-1 tw-transition-colors"
               data-dashboard-customize-link>
                <x-ui.icon name="sliders-horizontal" size="xs" />
                <span>Customize Layout</span>
            </a>
        </div>
    @endif
    @foreach($dashboardLayout as $dashboardWidget)
        @php($widgetContent = \Illuminate\Support\Arr::get(get_defined_vars(), $dashboardWidget['slot']))
        @if($dashboardWidget['visible'] && $widgetContent instanceof \Illuminate\View\ComponentSlot && ! $widgetContent->isEmpty())
            <div {{ $widgetContent->attributes->class(['tw-min-w-0', 'lg:tw-col-span-12' => ! $widgetContent->attributes->has('class')]) }} data-dashboard-widget="{{ $dashboardWidget['key'] }}">
                {{ $widgetContent }}
            </div>
        @endif
    @endforeach
</div>
