<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\CompleteLoginService;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\TurnstileVerifier;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    ): RedirectResponse {
        $user = $request->authenticate($limiter, $turnstile);

        if ($user->hasTwoFactorAuthentication()) {
            $twoFactor->beginLoginChallenge($request, $user, $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        $completeLogin->complete($request, $user, $request->boolean('remember'));

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Determine redirect path based on user role.
     */
    protected function redirectByRole(string $role): string
    {
        return match ($role) {
            'admin' => route('admin.dashboard', absolute: false),
            'purchasing' => route('purchasing.dashboard', absolute: false),
            'supplier' => route('supplier.dashboard', absolute: false),
            'qc' => route('qc.dashboard', absolute: false),
            default => '/',
        };
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
