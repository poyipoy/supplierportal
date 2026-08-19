<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePendingTwoFactorChallenge
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pending = $this->twoFactor->pendingLogin($request);
        $lifetime = (int) config('auth_security.two_factor.pending_lifetime_seconds', 600);

        if ($pending === null || ! isset($pending['user_id'], $pending['started_at'])
            || ((int) $pending['started_at'] + $lifetime) < now()->timestamp) {
            $this->twoFactor->forgetPendingLogin($request);

            return redirect()->route('login')->withErrors(['email' => trans('auth.failed')]);
        }

        return $next($request);
    }
}
