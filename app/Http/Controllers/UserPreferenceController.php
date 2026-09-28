<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserPreferenceRequest;
use App\Models\User;
use App\Services\QuickAccessService;
use App\Services\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserPreferenceController extends Controller
{
    public function edit(Request $request, UserPreferenceService $preferences, QuickAccessService $quickAccess): View
    {
        /** @var User $user */
        $user = $request->user();
        $effective = $preferences->for($user);
        $context = $quickAccess->contextFor($user);

        return view('profile.customization', [
            'preferences' => $effective,
            'quickAccessChoices' => $quickAccess->availableFor($user),
            'selectedQuickAccess' => array_column($quickAccess->selectedFor($user, $effective['quick_access'], $context), 'key'),
            'supplierContext' => $context,
            'quickAccessLimit' => (int) config('user_preferences.quick_access_limit'),
        ]);
    }

    public function update(
        UpdateUserPreferenceRequest $request,
        UserPreferenceService $preferences,
    ): RedirectResponse {
        $preferences->save($request->user(), $request->safe()->only([
            'theme', 'density', 'sidebar_state', 'page_size', 'quick_access',
        ]));

        return redirect()->route('profile.customization')->with('success', 'Customization saved.');
    }

    public function reset(Request $request, UserPreferenceService $preferences): RedirectResponse
    {
        $preferences->reset($request->user());

        return redirect()->route('profile.customization')->with('success', 'Customization reset to defaults.');
    }
}
