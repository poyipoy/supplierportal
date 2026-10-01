<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Services\NotificationPreferenceService;
use App\Services\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserNotificationPreferenceController extends Controller
{
    public function index(Request $request, NotificationPreferenceService $notifications): View
    {
        $user = $request->user();

        return view('profile.notifications', [
            'events' => $notifications->eventsFor($user),
            'effectivePreferences' => $notifications->effectivePreferences($user),
        ]);
    }

    public function update(
        UpdateNotificationPreferenceRequest $request,
        NotificationPreferenceService $notifications,
        UserPreferenceService $preferences,
    ): RedirectResponse {
        $user = $request->user();
        $normalized = $notifications->normalize($user, $request->validated('notification_preferences'));
        $preferences->saveNotificationPreferences($user, $normalized);

        return redirect()->route('profile.notifications')->with('success', 'Notification preferences saved.');
    }
}
