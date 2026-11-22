<?php

namespace App\Http\Middleware;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\Auth\RememberTokenService;
use App\Services\Auth\SessionReconnectService;
use App\Services\Auth\TwoFactorService;
use App\Support\Auth\SessionLifecycle;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthSessionSecurity
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly RememberTokenService $rememberTokens,
        private readonly SessionReconnectService $reconnect,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('session.reconnect')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isAuthenticationEligible()) {
            return $this->terminateSession($request, $user, 'account_deactivated', reason: 'account_deactivated');
        }

        if ($request->boolean('reauth') && $request->isMethod('GET') && ! $request->expectsJson()) {
            return $this->terminateSession(
                $request,
                $user,
                'session_timeout',
                ['reason' => 'idle_timeout'],
                'session_ended',
                preserveIntended: true,
            );
        }

        $viaRemember = Auth::guard('web')->viaRemember();
        $isPassive = SessionLifecycle::isPassiveRequest($request);

        // Enterprise ERP / OWASP policy: background polling, session status checks,
        // and JSON/XHR calls must never silently resurrect an expired session via a
        // Remember-Me cookie. Top-level HTML visits keep normal Remember-Me recall.
        if ($viaRemember && ($isPassive || $request->routeIs('session.status') || $request->expectsJson())) {
            $this->rememberTokens->revokeRecalledToken($request, $user);

            return $this->terminateSession(
                $request,
                $user,
                'session_timeout',
                ['reason' => 'idle_timeout'],
                'idle_timeout',
                keepReconnectGrant: true,
            );
        }

        if ($viaRemember && $user->hasTwoFactorAuthentication()) {
            // Revoke the single-use recaller token while staging the MFA code
            // challenge; CompleteLoginService issues a fresh token on success.
            $this->rememberTokens->revokeRecalledToken($request, $user);
            SessionLifecycle::markSessionMutated($request);

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

        if ($viaRemember) {
            $this->rememberTokens->bindToCurrentSession($request, $user);
            SessionLifecycle::markSessionMutated($request);
        }

        $now = now()->timestamp;
        $sessionVersion = $request->session()->get('auth_session_version');

        if ($sessionVersion === null) {
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
            SessionLifecycle::markSessionMutated($request);
        } elseif ((int) $sessionVersion !== (int) $user->auth_session_version) {
            return $this->terminateSession($request, $user, 'session_revoked', reason: 'session_revoked');
        }

        $startedAt = $request->session()->get(SessionLifecycle::ABSOLUTE_STARTED_KEY);
        $initializedGrant = false;

        if ($startedAt === null) {
            $startedAt = $now;
            $request->session()->put(SessionLifecycle::ABSOLUTE_STARTED_KEY, $startedAt);
            SessionLifecycle::markSessionMutated($request);
            $initializedGrant = true;
        } else {
            $expiresAt = (int) $startedAt + SessionLifecycle::absoluteTimeoutSeconds();

            if ($expiresAt <= $now) {
                return $this->terminateSession(
                    $request,
                    $user,
                    'session_timeout',
                    ['reason' => 'absolute_timeout'],
                    'absolute_timeout',
                );
            }
        }

        $lastActivity = $request->session()->get(SessionLifecycle::LAST_ACTIVITY_KEY);

        if ($lastActivity === null) {
            $request->session()->put(SessionLifecycle::LAST_ACTIVITY_KEY, $now);
            SessionLifecycle::markSessionMutated($request);
            $initializedGrant = true;
        } else {
            $idleExpiresAt = (int) $lastActivity + SessionLifecycle::idleTimeoutSeconds();

            if ($idleExpiresAt <= $now) {
                // Issue/refresh the encrypted reconnect grant bound to the original
                // absolute_started_at before ending the idle session so 1-click
                // server-side Reconnect can restore the session within the 8h cap.
                $this->reconnect->issueGrantCookie(
                    $request,
                    $user,
                    (int) $startedAt,
                    $request->session()->getId(),
                );

                return $this->terminateSession(
                    $request,
                    $user,
                    'session_timeout',
                    ['reason' => 'idle_timeout'],
                    'idle_timeout',
                    keepReconnectGrant: true,
                    preserveIntended: true,
                );
            }

            if (! $isPassive && (int) $lastActivity !== $now) {
                $request->session()->put(SessionLifecycle::LAST_ACTIVITY_KEY, $now);
            }
        }

        if ($initializedGrant && ! $request->cookies->has(SessionReconnectService::COOKIE_NAME)) {
            $this->reconnect->issueGrantCookie($request, $user, (int) $startedAt, $request->session()->getId());
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function terminateSession(
        Request $request,
        User $user,
        string $event,
        array $metadata = [],
        ?string $reason = null,
        bool $keepReconnectGrant = false,
        bool $preserveIntended = false,
    ): JsonResponse|RedirectResponse {
        $sessionId = $request->session()->getId();

        if ($sessionId !== '') {
            $this->rememberTokens->revokeForSessions($user, [$sessionId]);
        }

        if (! $keepReconnectGrant) {
            $this->reconnect->markSessionRevoked($sessionId);
            $this->reconnect->forgetGrantCookie();
        }

        $resolvedReason = $reason ?? $event;
        $reconnectable = $keepReconnectGrant && SessionLifecycle::isReconnectableReason($resolvedReason);

        SessionLifecycle::markSessionMutated($request);
        event(new AuthSecurityEvent($event, $user, metadata: $metadata));
        $request->attributes->set('auth_security.suppress_logout_audit', true);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || SessionLifecycle::isPassiveRequest($request) || $request->routeIs('session.status')) {
            return response()->json([
                'authenticated' => false,
                'reconnectable' => $reconnectable,
                'message' => __('auth.feedback.unauthenticated'),
                'reason' => $resolvedReason,
            ], 401)->header('Cache-Control', 'no-store, private');
        }

        if ($preserveIntended && $request->isMethod('GET')) {
            $cleanUrl = $request->fullUrlWithoutQuery(['reauth']);
            $request->session()->put('url.intended', TwoFactorService::safeIntendedUrl($request, $cleanUrl));
        }

        return redirect()->route('login')->with('status', __('auth.feedback.session_ended'));
    }
}
