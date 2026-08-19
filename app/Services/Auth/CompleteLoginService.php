<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompleteLoginService
{
    public function complete(Request $request, User $user, bool $remember): void
    {
        $request->attributes->set('auth_security.login_completed', true);
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put([
            'auth_session_version' => (int) $user->auth_session_version,
            'auth_absolute_started_at' => now()->timestamp,
        ]);
    }
}
