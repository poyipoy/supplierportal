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
            return back()->with('error', __('admin.copy.use_your_profile_security_page_to_disable_your_own_two_factor_authentication'));
        }

        if (! $user->hasTwoFactorAuthentication()) {
            return back()->with('status', __('admin.copy.two_factor_authentication_is_already_disabled_for_this_user'));
        }

        $twoFactor->resetByAdmin($user);
        event(new AuthSecurityEvent('mfa_admin_reset', $user, metadata: [
            'actor_user_id' => $request->user()->getKey(),
            'target_user_id' => $user->getKey(),
        ]));

        return back()->with('success', __('admin.copy.two_factor_authentication_has_been_reset_for_this_user'));
    }
}
