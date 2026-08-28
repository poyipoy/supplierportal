<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Cap the number of concurrent active sessions for a user at $limit,
     * evicting the oldest sessions (by last_activity) beyond that count.
     *
     * Unlike revokeOtherSessions() above (which bumps auth_session_version
     * and kills ALL other sessions at once), this evicts selectively: it
     * deletes only the excess rows directly from the sessions table, so
     * sessions within the limit are left untouched. auth_session_version
     * can't be reused here because it has no per-session granularity.
     *
     * @return int Number of sessions evicted.
     */
    public function enforceConcurrentLimit(User $user, string $currentSessionId, int $limit): int
    {
        if ($limit < 1) {
            return 0;
        }

        $otherSessionIds = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->orderByDesc('last_activity')
            ->pluck('id');

        // The current session already counts toward the limit, so at most
        // ($limit - 1) of the other sessions can be kept.
        $toEvict = $otherSessionIds->slice(max(0, $limit - 1))->all();

        if ($toEvict === []) {
            return 0;
        }

        DB::table('sessions')->whereIn('id', $toEvict)->delete();

        return count($toEvict);
    }
}
