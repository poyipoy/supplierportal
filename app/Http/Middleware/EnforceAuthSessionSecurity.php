<?php

namespace App\Http\Middleware;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthSessionSecurity
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->terminateSession($request, $user, 'account_deactivated');
        }

        if (Auth::guard('web')->viaRemember() && $user->hasTwoFactorAuthentication()) {
            if ($request->expectsJson()) {
                return $this->terminateSession($request, $user, 'remember_mfa_required');
            }

            $this->twoFactor->beginLoginChallenge(
                $request,
                $user,
                true,
                $request->fullUrl(),
                true,
            );
            event(new AuthSecurityEvent('remember_mfa_required', $user));

            return redirect()->route('two-factor.challenge');
        }

        $sessionVersion = $request->session()->get('auth_session_version');

        if ($sessionVersion === null) {
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        } elseif ((int) $sessionVersion !== (int) $user->auth_session_version) {
            return $this->terminateSession($request, $user, 'session_revoked');
        }

        $startedAt = $request->session()->get('auth_absolute_started_at');

        if ($startedAt === null) {
            $request->session()->put('auth_absolute_started_at', now()->timestamp);
        } else {
            $expiresAt = (int) $startedAt + ((int) config('auth_security.session.absolute_timeout_minutes', 480) * 60);

            if ($expiresAt <= now()->timestamp) {
                return $this->terminateSession($request, $user, 'session_timeout');
            }
        }

        return $next($request);
    }

    private function terminateSession(Request $request, User $user, string $event): JsonResponse|RedirectResponse
    {
        event(new AuthSecurityEvent($event, $user));
        $request->attributes->set('auth_security.suppress_logout_audit', true);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login')->with('status', 'Your session has ended. Please sign in again.');
    }
}
