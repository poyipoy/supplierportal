<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionInventoryService
{
    public function activeSessionsFor(User $user, ?string $currentSessionId): Collection
    {
        return $this->query()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $this->activeCutoff())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get()
            ->map(fn (object $session): object => (object) [
                'id' => $session->id,
                'is_current' => $currentSessionId !== null
                    && hash_equals($currentSessionId, (string) $session->id),
                'ip_address' => $session->ip_address,
                'user_agent' => (string) $session->user_agent,
                'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity),
            ]);
    }

    public function revoke(User $user, string $sessionId): bool
    {
        return $this->query()
            ->where('user_id', $user->getKey())
            ->where('id', $sessionId)
            ->delete() > 0;
    }

    public function enforceConcurrentLimit(User $user, ?string $currentSessionId = null): int
    {
        $limit = (int) config('auth_security.session.max_concurrent_sessions', 3);

        if ($limit < 1) {
            return 0;
        }

        $query = $this->query()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $this->activeCutoff());

        if ($currentSessionId !== null) {
            $query->where('id', '!=', $currentSessionId);
        }

        $toEvict = $query
            ->orderByDesc('last_activity')
            ->pluck('id')
            ->slice(max(0, $limit - 1))
            ->all();

        if ($toEvict === []) {
            return 0;
        }

        return $this->query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $toEvict)
            ->delete();
    }

    private function query(): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'));
    }

    private function activeCutoff(): int
    {
        return now()->subMinutes((int) config('session.lifetime', 120))->timestamp;
    }
}
