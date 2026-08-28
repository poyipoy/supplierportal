<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevokeSessionController extends Controller
{
    /**
     * Sign out one specific session belonging to the current user (e.g. from
     * the "Active Sessions" list), without touching their other sessions.
     */
    public function __invoke(Request $request, string $sessionId): RedirectResponse
    {
        if (hash_equals((string) $request->session()->getId(), $sessionId)) {
            return back()->with('warning', 'Use the sign-out button to end your current session.');
        }

        $deleted = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $request->user()->getKey())
            ->delete();

        if ($deleted > 0) {
            event(new AuthSecurityEvent('session_revoked', $request->user(), metadata: ['reason' => 'manual_single_session']));
        }

        return back()->with('status', $deleted > 0 ? 'session-revoked' : 'session-not-found');
    }
}
