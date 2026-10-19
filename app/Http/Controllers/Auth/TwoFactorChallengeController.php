<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\CompleteLoginService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function show(): View
    {
        return view('auth.two-factor-challenge');
    }

    public function store(
        Request $request,
        TwoFactorService $twoFactor,
        CompleteLoginService $completeLogin,
    ): RedirectResponse {
        $twoFactor->rejectAuthenticatedLogin($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $pending = $twoFactor->pendingLogin($request);
        $user = User::query()->find($pending['user_id'] ?? null);

        if (! $user?->isAuthenticationEligible() || ! $user->hasTwoFactorAuthentication()) {
            $twoFactor->forgetPendingLogin($request);

            throw ValidationException::withMessages(['email' => trans('auth.failed')])
                ->redirectTo(route('login'));
        }

        try {
            $method = $twoFactor->verifyLoginCode($user, $validated['code']);
        } catch (ValidationException $exception) {
            if (array_key_exists('email', $exception->errors())) {
                $twoFactor->forgetPendingLoginFor($request, $user->getKey());
            }

            throw $exception;
        }

        if ($method === null) {
            event(new AuthSecurityEvent('mfa_challenge_failed', $user));

            throw ValidationException::withMessages(['code' => __('auth.feedback.used_code')]);
        }

        $remember = (bool) ($pending['remember'] ?? false);
        $intended = TwoFactorService::safeIntendedUrl(
            $request,
            (string) ($pending['intended'] ?? ''),
            route('dashboard', absolute: false)
        );
        if ($method === 'recovery') {
            // Consumption is a fact even if final admission or completion later fails.
            event(new AuthSecurityEvent('mfa_recovery_code_used', $user));
        }

        $completeLogin->complete($request, $user, $remember);

        if ($method !== 'recovery') {
            event(new AuthSecurityEvent('mfa_challenge_succeeded', $user));
        }

        return redirect()->to($intended);
    }
}
