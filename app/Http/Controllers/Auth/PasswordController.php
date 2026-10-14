<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => __('validation.current_password')])->errorBag('updatePassword');
            }
            Auth::guard('web')->setUser($user);
            Auth::logoutOtherDevices($validated['current_password']);
            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
                'auth_session_version' => ((int) $user->auth_session_version) + 1,
                'must_change_password' => false,
            ])->save();
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        });

        event(new AuthSecurityEvent('password_changed', $request->user()));

        return back()->with('status', 'password-updated');
    }
}
