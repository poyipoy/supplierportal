<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\SessionInventoryService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class RevokeSessionController extends Controller
{
    /**
     * Sign out one specific session belonging to the current user (e.g. from
     * the "Active Sessions" list), without touching their other sessions.
     */
    public function __invoke(
        Request $request,
        SessionInventoryService $sessions,
    ): RedirectResponse {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $sessionId = Crypt::decryptString($validated['session_token']);
        } catch (DecryptException) {
            return back()->with('status', 'session-not-found');
        }

        if (hash_equals((string) $request->session()->getId(), $sessionId)) {
            return back()->with('warning', __('auth.feedback.current_session'));
        }

        $deleted = $sessions->revoke($request->user(), $sessionId);

        if ($deleted) {
            event(new AuthSecurityEvent('session_revoked', $request->user(), metadata: ['reason' => 'manual_single_session']));
        }

        return back()->with('status', $deleted ? 'session-revoked' : 'session-not-found');
    }
}
