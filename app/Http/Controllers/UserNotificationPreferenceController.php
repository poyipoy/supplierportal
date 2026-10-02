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
        $events = $notifications->eventsFor($user);
        $categoryOrder = array_flip([
            'Purchase requisitions', 'Quotations', 'Conversations', 'Purchase orders', 'Documents',
            'Shipments and QC', 'Material claims', 'Local invoices', 'Supplier registration', 'Exports', 'Security',
        ]);
        uasort($events, fn (array $left, array $right): int => ($categoryOrder[$left['category']] ?? PHP_INT_MAX) <=> ($categoryOrder[$right['category']] ?? PHP_INT_MAX));

        return view('profile.notifications', [
            'events' => $events,
            'effectivePreferences' => $notifications->effectivePreferences($user),
        ]);
    }

    public function reset(Request $request, UserPreferenceService $preferences): RedirectResponse
    {
        $preferences->resetNotificationPreferences($request->user());

        return redirect()->route('profile.notifications')->with('success', 'Notification preferences reset to defaults.');
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
