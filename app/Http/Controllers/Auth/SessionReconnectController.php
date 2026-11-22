<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionReconnectService;
use App\Support\Auth\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles 1-click server-side in-place session reconnection from the ERP
 * Session Ended modal (`POST /session/reconnect`) without reloading the page.
 *
 * Protected by Same-Origin Custom-Header CSRF validation (`X-Adasi-Reconnect: 1`
 * + `X-Requested-With: XMLHttpRequest` + `Sec-Fetch-Site` / `Origin` check) so it
 * remains operable even when the page's previous CSRF token expired during idle.
 */
class SessionReconnectController extends Controller
{
    public function __invoke(Request $request, SessionReconnectService $reconnect): JsonResponse
    {
        if (! $this->passesSameOriginCustomHeaderGuard($request)) {
            return response()->json([
                'ok' => false,
                'authenticated' => false,
                'message' => __('auth.feedback.unauthenticated'),
            ], 403)->header('Cache-Control', 'no-store, private');
        }

        $result = $reconnect->attemptReconnect($request);
        $user = $result['user'];

        if ($result['ok'] && $user instanceof User) {
            return response()->json([
                'ok' => true,
                'authenticated' => true,
                'user_id' => (int) $user->getKey(),
                'csrf_token' => $request->session()->token(),
                ...SessionLifecycle::remainingTimings($request),
            ])->header('Cache-Control', 'no-store, private');
        }

        return response()->json([
            'ok' => false,
            'authenticated' => false,
            'reconnectable' => false,
            'reason' => $result['reason'],
            'login_url' => route('login'),
        ], 401)->header('Cache-Control', 'no-store, private');
    }

    private function passesSameOriginCustomHeaderGuard(Request $request): bool
    {
        if ($request->header(SessionLifecycle::RECONNECT_HEADER) !== '1' || ! $request->ajax()) {
            return false;
        }

        $secFetchSite = strtolower(trim((string) $request->header('Sec-Fetch-Site', '')));
        if ($secFetchSite !== '' && $secFetchSite !== 'same-origin' && $secFetchSite !== 'none') {
            return false;
        }

        $origin = trim((string) $request->header('Origin', ''));
        if ($origin !== '' && strcasecmp(rtrim($origin, '/'), rtrim($request->getSchemeAndHttpHost(), '/')) !== 0) {
            return false;
        }

        return true;
    }
}
