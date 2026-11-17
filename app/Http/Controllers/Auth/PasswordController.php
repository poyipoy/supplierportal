<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PasswordHistoryService;
use App\Services\Auth\SessionRevocationService;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(
        Request $request,
        PasswordHistoryService $passwordHistory,
        SessionRevocationService $revocation,
    ): RedirectResponse {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ]);

        DB::transaction(function () use ($request, $validated, $passwordHistory, $revocation): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => __('validation.current_password')])->errorBag('updatePassword');
            }

            if ($passwordHistory->matchesRecent($user, $validated['password'])) {
                throw ValidationException::withMessages([
                    'password' => __('security.password_reused', ['count' => $passwordHistory->historyCount()]),
                ])->errorBag('updatePassword');
            }

            $passwordHistory->seedIfEmpty($user);
            $newHash = Hash::make($validated['password']);
            $passwordHistory->record($user, $newHash);

            $user->forceFill([
                'password' => $newHash,
                'must_change_password' => false,
            ])->save();

            Auth::guard('web')->setUser($user);
            $request->session()->put('password_hash_web', $newHash);
            $revocation->revokeOtherSessions($user, $request);
            event(new OtherDeviceLogout('web', $user));
        });

        event(new AuthSecurityEvent('password_changed', $request->user()));

        return back()->with('status', 'password-updated');
    }
}
