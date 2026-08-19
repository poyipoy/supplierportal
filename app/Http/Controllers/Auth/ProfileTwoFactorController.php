<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileTwoFactorController extends Controller
{
    public function start(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        if ($request->user()->hasTwoFactorAuthentication()) {
            return redirect()->route('profile.edit')->with('status', 'two-factor-already-enabled');
        }

        $twoFactor->startSetup($request);

        return redirect()->route('profile.two-factor.setup');
    }

    public function show(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $secret = $twoFactor->pendingSetupSecret($request);

        if ($secret === null || $request->user()->hasTwoFactorAuthentication()) {
            return redirect()->route('profile.edit');
        }

        return view('profile.two-factor-setup', [
            'secret' => $secret,
            'qrCode' => $twoFactor->qrCodeDataUri($request->user(), $secret),
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor): View
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $codes = $twoFactor->confirmSetup($request, $request->user(), $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        event(new AuthSecurityEvent('mfa_enabled', $request->user()));

        return view('profile.two-factor-recovery-codes', ['codes' => $codes]);
    }

    public function recoveryCodes(Request $request, TwoFactorService $twoFactor): View
    {
        abort_unless($request->user()->hasTwoFactorAuthentication(), 404);
        $codes = $twoFactor->regenerateRecoveryCodes($request, $request->user());
        event(new AuthSecurityEvent('mfa_recovery_codes_regenerated', $request->user()));

        return view('profile.two-factor-recovery-codes', ['codes' => $codes]);
    }

    public function destroy(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);

        if ($twoFactor->verifyUserCode($request->user(), $validated['code']) === null) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid or has already been used.']);
        }

        $twoFactor->disable($request, $request->user());
        event(new AuthSecurityEvent('mfa_disabled', $request->user()));

        return redirect()->route('profile.edit')->with('status', 'two-factor-disabled');
    }
}
