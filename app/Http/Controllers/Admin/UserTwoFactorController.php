<?php

namespace App\Http\Controllers\Admin;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserTwoFactorController extends Controller
{
    public function destroy(Request $request, User $user, TwoFactorService $twoFactor): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'Use your Profile & Security page to disable your own two-factor authentication.');
        }

        if (! $user->hasTwoFactorAuthentication()) {
            return back()->with('status', 'Two-factor authentication is already disabled for this user.');
        }

        $twoFactor->resetByAdmin($user);
        event(new AuthSecurityEvent('mfa_admin_reset', $user, metadata: [
            'actor_user_id' => $request->user()->getKey(),
            'target_user_id' => $user->getKey(),
        ]));

        return back()->with('success', 'Two-factor authentication has been reset for this user.');
    }
}
