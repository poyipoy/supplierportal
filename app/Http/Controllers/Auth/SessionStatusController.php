<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionReconnectService;
use App\Services\Auth\TwoFactorService;
use App\Support\Auth\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the session-ended dialog and proactive expiry checks: is this session
 * still alive?
 *
 * It never authenticates anyone itself. Passive checks and JSON status queries
 * never resurrect an expired session via Remember-Me (enforced upstream in
 * EnforceAuthSessionSecurity). Only same-origin scripts can read the CSRF token
 * because no CORS headers are sent and Cache-Control is no-store.
 */
class SessionStatusController extends Controller
{
    public function __invoke(
        Request $request,
        TwoFactorService $twoFactor,
        SessionReconnectService $reconnect,
    ): JsonResponse {
        $user = $request->user();

        if ($user instanceof User && $user->isAuthenticationEligible()) {
            return response()->json([
                'authenticated' => true,
                'user_id' => (int) $user->getKey(),
                'csrf_token' => $request->session()->token(),
                ...SessionLifecycle::remainingTimings($request),
            ]);
        }

        $pending = $twoFactor->pendingLogin($request);
        $lifetime = (int) config('auth_security.two_factor.pending_lifetime_seconds', 600);

        if ($user === null && isset($pending['user_id'], $pending['started_at'])
            && ((int) $pending['started_at'] + $lifetime) >= now()->timestamp) {
            if (SessionLifecycle::isPassiveStatusCheck($request)) {
                return response()->json([
                    'authenticated' => false,
                    'reason' => 'session_ended',
                ], 401);
            }

            // A pending MFA code challenge is already in flight; return to the page
            // the dialog was opened on, never to an external origin.
            $return = $request->query('return');
            $pending['intended'] = TwoFactorService::safeIntendedUrl(
                $request,
                is_string($return) ? $return : (string) ($pending['intended'] ?? ''),
            );
            $request->session()->put(TwoFactorService::PENDING_LOGIN_KEY, $pending);
            SessionLifecycle::markSessionMutated($request);

            return response()->json([
                'authenticated' => false,
                'two_factor_url' => route('two-factor.challenge'),
            ], 401);
        }

        if ($request->cookies->has(SessionReconnectService::COOKIE_NAME)) {
            $inspection = $reconnect->inspectGrant($request);

            return response()->json([
                'authenticated' => false,
                'reconnectable' => $inspection['valid'],
                'reason' => $inspection['reason'],
            ], 401);
        }

        return response()->json([
            'authenticated' => false,
            'reason' => 'session_ended',
        ], 401);
    }
}
