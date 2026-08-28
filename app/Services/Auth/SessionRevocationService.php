<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SessionRevocationService
{
    public function revokeOtherSessions(User $user, ?Request $request = null, bool $rotateRememberToken = true): int
    {
        $user->forceFill([
            'auth_session_version' => ((int) $user->auth_session_version) + 1,
            'remember_token' => $rotateRememberToken ? Str::random(60) : $user->getRememberToken(),
        ])->saveQuietly();

        if ($request?->user()?->is($user)) {
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        }

        return (int) $user->auth_session_version;
    }
}
