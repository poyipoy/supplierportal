<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the session-ended dialog: can this page keep working without signing in again?
 *
 * It never authenticates anyone itself. A live session (another tab signed in again, or only
 * the page's CSRF token went stale) and a valid remember-me cookie are resolved by the normal
 * web middleware before this runs; revocation, absolute timeout, deactivation and forced
 * password change still end or redirect the request there. Only same-origin script can read
 * the token, because no CORS headers are sent.
 */
class SessionStatusController extends Controller
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->isAuthenticationEligible()) {
            return response()->json([
                'authenticated' => true,
                'user_id' => (int) $user->getKey(),
                'csrf_token' => $request->session()->token(),
            ]);
        }

        $pending = $twoFactor->pendingLogin($request);
        $lifetime = (int) config('auth_security.two_factor.pending_lifetime_seconds', 600);

        if ($user === null && isset($pending['user_id'], $pending['started_at'])
            && ((int) $pending['started_at'] + $lifetime) >= now()->timestamp) {
            // The remember-me cookie already proved the account; only the code is missing.
            // Return to the page the dialog was opened on, never to the request that staged it.
            $return = $request->query('return');
            $pending['intended'] = TwoFactorService::safeIntendedUrl(
                $request,
                is_string($return) ? $return : (string) ($pending['intended'] ?? ''),
            );
            $request->session()->put(TwoFactorService::PENDING_LOGIN_KEY, $pending);

            return response()->json([
                'authenticated' => false,
                'two_factor_url' => route('two-factor.challenge'),
            ], 401);
        }

        return response()->json(['authenticated' => false], 401);
    }
}
