<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        Auth::logoutOtherDevices($validated['current_password']);

        DB::transaction(function () use ($request, $validated): void {
            $user = $request->user();
            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
                'auth_session_version' => ((int) $user->auth_session_version) + 1,
            ])->save();
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        });

        event(new AuthSecurityEvent('password_changed', $request->user()));

        return back()->with('status', 'password-updated');
    }
}
