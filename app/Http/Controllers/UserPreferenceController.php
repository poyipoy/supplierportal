<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserPreferenceRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardWidgetService;
use App\Services\QuickAccessService;
use App\Services\UserPreferenceService;
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

        return view('profile.customization', [
            'preferences' => $effective,
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

    public function update(
        UpdateUserPreferenceRequest $request,
        UserPreferenceService $preferences,
    ): RedirectResponse {
        $saved = $preferences->save($request->user(), $request->safe()->only([
            'theme', 'density', 'sidebar_state', 'page_size', 'quick_access', 'accent', 'dashboard', 'timezone', 'date_format', 'time_format', 'number_format', 'locale',
        ]));

        return redirect()->route('profile.customization')->with('success', __('customization.saved', [], $saved['locale']));
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
