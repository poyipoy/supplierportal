<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\SessionInventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RevokeSessionController extends Controller
{
    /**
     * Sign out one specific session belonging to the current user (e.g. from
     * the "Active Sessions" list), without touching their other sessions.
     */
    public function __invoke(
        Request $request,
        string $sessionId,
        SessionInventoryService $sessions,
    ): RedirectResponse {
        if (hash_equals((string) $request->session()->getId(), $sessionId)) {
            return back()->with('warning', 'Use the sign-out button to end your current session.');
        }

        $deleted = $sessions->revoke($request->user(), $sessionId);

        if ($deleted) {
            event(new AuthSecurityEvent('session_revoked', $request->user(), metadata: ['reason' => 'manual_single_session']));
        }

        return back()->with('status', $deleted ? 'session-revoked' : 'session-not-found');
    }
}
