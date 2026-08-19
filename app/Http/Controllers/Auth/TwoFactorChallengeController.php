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
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $pending = $twoFactor->pendingLogin($request);
        $user = User::query()->find($pending['user_id'] ?? null);

        if (! $user?->is_active || ! $user->hasTwoFactorAuthentication()) {
            $twoFactor->forgetPendingLogin($request);

            throw ValidationException::withMessages(['code' => trans('auth.failed')]);
        }

        $method = $twoFactor->verifyUserCode($user, $validated['code']);

        if ($method === null) {
            event(new AuthSecurityEvent('mfa_challenge_failed', $user));

            throw ValidationException::withMessages(['code' => 'The authentication code is invalid or has already been used.']);
        }

        $remember = (bool) ($pending['remember'] ?? false);
        $intended = (string) ($pending['intended'] ?? route('dashboard', absolute: false));
        $twoFactor->forgetPendingLogin($request);
        event(new AuthSecurityEvent($method === 'recovery' ? 'mfa_recovery_code_used' : 'mfa_challenge_succeeded', $user));
        $completeLogin->complete($request, $user, $remember);

        return redirect()->to($intended);
    }
}
