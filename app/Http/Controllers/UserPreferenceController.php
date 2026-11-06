<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardLayoutRequest;
use App\Http\Requests\UpdateUserPreferenceRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardWidgetService;
use App\Services\QuickAccessService;
use App\Services\UserPreferenceService;
use App\Support\PortalContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserPreferenceController extends Controller
{
    public function edit(Request $request, UserPreferenceService $preferences, QuickAccessService $quickAccess, DashboardWidgetService $dashboardWidgets): View
    {
        /** @var User $user */
        $user = $request->user();
        $effective = $preferences->for($user);
        $context = $quickAccess->contextFor($user);
        $dashboardDefaultRows = $dashboardWidgets->layoutFor($user, []);
        $oldDensity = $request->old('density');

        return view('profile.customization', [
            'preferences' => $effective,
            'densityPreview' => is_string($oldDensity) && in_array($oldDensity, config('user_preferences.densities'), true)
                ? $oldDensity : null,
            'defaults' => config('user_preferences.defaults'),
            'accentChoices' => collect(config('user_preferences.accents'))->map(fn ($label, $key) => __('customization.accents.'.$key))->all(),
            'regionalChoices' => array_intersect_key(config('regional_display'), array_flip(['timezones', 'date_formats', 'time_formats', 'number_formats'])),
            'dashboardAudience' => $dashboardWidgets->audienceFor($user),
            'dashboardWidgets' => $dashboardWidgets->layoutFor($user, $effective['dashboard_preferences']),
            'dashboardDefaultOrder' => array_column($dashboardDefaultRows, 'key'),
            'quickAccessChoices' => $quickAccess->availableFor($user),
            'selectedQuickAccess' => array_column($quickAccess->selectedFor($user, $effective['quick_access'], $context), 'key'),
            'otherContextQuickAccessCount' => count($quickAccess->availableInOtherSupplierContext($user, $effective['quick_access'], $context)),
            'supplierContext' => $context,
            'importTimezone' => $context === PortalContext::SCOPE_IMPORT ? $this->importTimezoneChoices() : null,
            'quickAccessLimit' => (int) config('user_preferences.quick_access_limit'),
            'regionalFormatRegistry' => [
                'date_formats' => array_keys(config('regional_display.date_formats')),
                'number_profiles' => config('regional_display.number_profiles'),
                'months' => config('regional_display.months'),
            ],
            'regionalSample' => [
                'iso' => '2026-10-08T07:30:00Z',
                'number' => '1,250,000.50',
            ],
        ]);
    }

    /** Country and IANA zone options for the Supplier Import timezone preference. */
    private function importTimezoneChoices(): array
    {
        $countryDefaults = config('country_timezones');
        $locale = app()->getLocale();
        $countries = [];
        foreach (array_keys($countryDefaults) as $code) {
            // Without ext-intl the ISO code itself is the label.
            $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, $locale) : '';
            $countries[$code] = is_string($name) && $name !== '' && $name !== $code ? $name : $code;
        }
        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

        $now = new DateTimeImmutable('now'); // biz-time:ignore offset sample for zone option sublabels
        $zones = [];
        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $zones[$zone] = 'UTC'.$now->setTimezone(new DateTimeZone($zone))->format('P');
        }

        return ['countries' => $countries, 'zones' => $zones, 'countryDefaults' => $countryDefaults];
    }

    public function update(
        UpdateUserPreferenceRequest $request,
        UserPreferenceService $preferences,
    ): RedirectResponse {
        $saved = $preferences->save($request->user(), $request->safe()->only([
            'theme', 'density', 'sidebar_state', 'page_size', 'quick_access', 'accent', 'dashboard', 'timezone', 'date_format', 'time_format', 'number_format', 'locale',
            'country_code', 'import_timezone',
        ]));

        return redirect()->route('profile.customization')->with('success', __('customization.saved', [], $saved['locale']));
    }

    public function updateDashboardLayout(
        UpdateDashboardLayoutRequest $request,
        UserPreferenceService $preferences,
        DashboardWidgetService $dashboardWidgets,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        $saved = $preferences->saveDashboardLayout($user, $request->validated('dashboard'));
        $message = __('customization.dashboard_saved', [], $saved['locale']);
        // The audience's own dashboard, never a client-supplied Referer, is the redirect target.
        $dashboard = route(config('dashboard_widgets.audiences')[$dashboardWidgets->audienceFor($user)]['route']);

        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['redirect' => $dashboard]);
        }

        return redirect()->back(fallback: $dashboard)->with('success', $message);
    }

    public function reset(
        Request $request,
        UserPreferenceService $preferences,
        DashboardWidgetService $dashboardWidgets,
    ): RedirectResponse {
        $user = $request->user();

        if ($request->input('scope') === 'dashboard') {
            $audience = $dashboardWidgets->audienceFor($user);
            if ($audience !== null) {
                $stored = $user->preference()->first();
                $preferences->save($user, [
                    'theme' => $stored?->theme ?? config('user_preferences.defaults.theme'),
                    'density' => $stored?->density ?? config('user_preferences.defaults.density'),
                    'sidebar_state' => $stored?->sidebar_state ?? config('user_preferences.defaults.sidebar_state'),
                    'page_size' => $stored?->page_size ?? config('user_preferences.defaults.page_size'),
                    'quick_access' => is_array($stored?->quick_access) ? $stored->quick_access : [],
                    'accent' => $stored?->accent ?? config('user_preferences.defaults.accent'),
                    'dashboard' => ['hidden' => [], 'order' => []],
                    'timezone' => $stored?->timezone ?? config('user_preferences.defaults.timezone'),
                    'date_format' => $stored?->date_format ?? config('user_preferences.defaults.date_format'),
                    'time_format' => $stored?->time_format ?? config('user_preferences.defaults.time_format'),
                    'number_format' => $stored?->number_format ?? config('user_preferences.defaults.number_format'),
                ]);
            }

            return redirect()->route('profile.customization')->with('success', __('customization.dashboard_reset'));
        }

        $preferences->reset($user);

        return redirect()->route('profile.customization')->with('success', __('customization.reset', [], 'en'));
    }
}
