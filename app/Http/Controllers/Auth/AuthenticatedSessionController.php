<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\CompleteLoginService;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\RememberTokenService;
use App\Services\Auth\SessionRevocationService;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request, TurnstileVerifier $turnstile): View
    {
        return view('auth.login', [
            'turnstileRequired' => $turnstile->configured() && $request->session()->get('auth_turnstile_required', false),
            'turnstileSiteKey' => config('auth_security.turnstile.site_key'),
        ]);
    }

    public function store(
        LoginRequest $request,
        LoginRateLimiter $limiter,
        TurnstileVerifier $turnstile,
        TwoFactorService $twoFactor,
        CompleteLoginService $completeLogin,
    ): RedirectResponse|JsonResponse {
        try {
            $user = $request->authenticate($limiter, $turnstile);
        } catch (ValidationException $exception) {
            if ($request->hasHeader('X-Auth-Splash-Client')) {
                return response()->json([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                    'turnstile_required' => $turnstile->configured() && (bool) $request->session()->get('auth_turnstile_required', false),
                ], 422);
            }

            throw $exception;
        }

        if ($user->hasTwoFactorAuthentication()) {
            $twoFactor->beginLoginChallenge($request, $user, $request->boolean('remember'));

            if ($request->hasHeader('X-Auth-Splash-Client')) {
                return response()->json([
                    'ok' => true,
                    'two_factor' => true,
                    'redirect_url' => route('two-factor.challenge', absolute: false),
                    'splash' => false,
                ]);
            }

            return redirect()->route('two-factor.challenge');
        }

        $completeLogin->complete($request, $user, $request->boolean('remember'));

        $intended = $request->session()->pull('url.intended');
        $redirectUrl = $completeLogin->resolvePostLoginDestination($request, $user, is_string($intended) ? $intended : null);

        if ($request->hasHeader('X-Auth-Splash-Client')) {
            $request->attributes->set('ui.auth_splash_client_target', $redirectUrl);

            return response()->json([
                'ok' => true,
                'two_factor' => false,
                'redirect_url' => $redirectUrl,
                'splash' => (bool) config('ui.auth_splash.enabled', true),
            ]);
        }

        return redirect()->to($redirectUrl);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(
        Request $request,
        SessionRevocationService $revocation,
        RememberTokenService $rememberTokens,
    ): RedirectResponse {
        $user = $request->user();
        $sessionId = $request->session()->getId();

        if ($user !== null && config('portal_session.logout_all_devices', false) && $request->boolean('logout_all')) {
            $revocation->revokeOtherSessions($user, $request);
            event(new AuthSecurityEvent('other_device_logout', $user, metadata: [
                'guard' => 'web',
                'reason' => 'logout_checkbox',
            ]));
        }

        if ($user !== null && $sessionId !== '') {
            $rememberTokens->revokeForSessions($user, [$sessionId]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if (config('ui.auth_splash.enabled', false)) {
            $request->session()->put('ui.auth_splash', [
                'id' => (string) Str::uuid(),
                'reason' => 'post-logout',
                'issued_at' => now()->timestamp,
                'target' => null,
            ]);
        }

        return redirect()->route('login');
    }
}
