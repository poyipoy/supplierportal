<?php

namespace App\Http\Middleware;

use App\Services\Auth\PasswordConfirmationContinuationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class EnsurePasswordConfirmation
{
    public function __construct(
        private readonly PasswordConfirmationContinuationService $continuation,
    ) {}

    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, ?string $passwordTimeoutSeconds = null): mixed
    {
        $timeout = $passwordTimeoutSeconds !== null
            ? (int) $passwordTimeoutSeconds
            : (int) config('auth.password_timeout', 10800);
        $confirmedAt = Date::now()->unix() - (int) $request->session()->get('auth.password_confirmed_at', 0);

        if ($confirmedAt <= $timeout) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Password confirmation required.',
            ], 423);
        }

        $this->continuation->capture($request);

        return redirect()->route($redirectToRoute ?: 'password.confirm');
    }
}
