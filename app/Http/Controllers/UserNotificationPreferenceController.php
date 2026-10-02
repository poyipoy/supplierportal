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

        $deliveryPreferences = [];
        foreach (array_keys($events) as $key) {
            $deliveryPreferences[$key] = $notifications->deliveryFor($user, $key) === 'silent' ? 'silent' : 'normal';
        }

        return view('profile.notifications', [
            'events' => $events,
            'effectivePreferences' => $notifications->effectivePreferences($user),
            'deliveryPreferences' => $deliveryPreferences,
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
        $delivery = (array) $request->validated('notification_delivery', []);
        $preferences->saveNotificationPreferences($user, $normalized, $delivery);

        return redirect()->route('profile.notifications')->with('success', 'Notification preferences saved.');
    }
}
