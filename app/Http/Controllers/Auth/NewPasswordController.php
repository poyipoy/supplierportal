<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $blockedForInactiveAccount = false;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, &$blockedForInactiveAccount) {
                // A deactivated account must not be able to set a new password
                // even with an otherwise-valid token: it still can't log in
                // (LoginRequest gates on is_active), but leaving this open
                // would let a stale/inactive credential be "revived" without
                // an admin's involvement. The token is still consumed below
                // by the broker either way, so it can't be retried.
                if (! $user->is_active) {
                    $blockedForInactiveAccount = true;

                    return;
                }

                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    'auth_session_version' => ((int) $user->auth_session_version) + 1,
                ])->save();

                event(new PasswordReset($user));
                event(new AuthSecurityEvent('password_changed', $user, metadata: ['reason' => 'password_reset']));
            }
        );

        if ($blockedForInactiveAccount) {
            event(new AuthSecurityEvent('password_reset_blocked_inactive_account', email: $request->string('email')->toString()));

            // Report the same status as an invalid/expired token so this case
            // is indistinguishable from any other failed reset.
            $status = Password::INVALID_TOKEN;
        }

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        $message = match ($status) {
            Password::PASSWORD_RESET => 'Password has been reset successfully.',
            default => 'This password reset link is invalid or has expired.',
        };

        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', $message)
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => $message]);
    }
}
