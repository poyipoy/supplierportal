@props(['audience'])

@php
    $dashboardUser = request()->user();
    $dashboardSaved = $dashboardUser ? app(\App\Services\UserPreferenceService::class)->for($dashboardUser) : [];
    $dashboardLayout = $dashboardUser
        ? app(\App\Services\Dashboard\DashboardWidgetService::class)->layoutFor($dashboardUser, $dashboardSaved['dashboard_preferences'] ?? [], $audience)
        : [];
@endphp

<div {{ $attributes->class(['tw-grid tw-grid-cols-1 tw-gap-4 lg:tw-grid-cols-12']) }} data-dashboard-audience="{{ $audience }}">
    @foreach($dashboardLayout as $dashboardWidget)
        @php($widgetContent = \Illuminate\Support\Arr::get(get_defined_vars(), $dashboardWidget['slot']))
        @if($dashboardWidget['visible'] && $widgetContent instanceof \Illuminate\View\ComponentSlot && ! $widgetContent->isEmpty())
            <div {{ $widgetContent->attributes->class(['tw-min-w-0', 'lg:tw-col-span-12' => ! $widgetContent->attributes->has('class')]) }} data-dashboard-widget="{{ $dashboardWidget['key'] }}">
                {{ $widgetContent }}
            </div>
        @endif
    @endforeach
</div>
