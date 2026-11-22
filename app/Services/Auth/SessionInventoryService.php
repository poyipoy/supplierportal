<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\SessionLifecycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SessionInventoryService
{
    private int $lastEvictionRevokedPerDeviceCount = 0;

    public function activeSessionsFor(User $user, ?string $currentSessionId): Collection
    {
        return $this->query()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $this->activeCutoff())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): object => (object) [
                'revocation_token' => $currentSessionId !== null
                    && hash_equals($currentSessionId, (string) $session->id)
                        ? null
                        : Crypt::encryptString((string) $session->id),
                'is_current' => $currentSessionId !== null
                    && hash_equals($currentSessionId, (string) $session->id),
                'ip_address' => $session->ip_address,
                'user_agent' => (string) $session->user_agent,
                'device_label' => KnownDeviceService::deviceLabel($session->user_agent),
                'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity),
            ]);
    }

    public function revoke(User $user, string $sessionId): bool
    {
        return $this->revokeMany($user, [$sessionId]) > 0;
    }

    /**
     * @param  list<string>  $sessionIds
     */
    public function revokeMany(User $user, array $sessionIds): int
    {
        $ids = array_values(array_filter($sessionIds, fn ($id): bool => is_string($id) && $id !== ''));

        if ($ids === []) {
            return 0;
        }

        return $this->query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $ids)
            ->delete();
    }

    public function enforceConcurrentLimit(User $user, ?string $currentSessionId = null): int
    {
        $evictedIds = $this->enforceConcurrentLimitWithEvictedIds($user, $currentSessionId);
        if ($evictedIds !== []) {
            app(SessionReconnectService::class)->markSessionsRevoked($evictedIds);
        }
        $this->lastEvictionRevokedPerDeviceCount = $evictedIds === []
            ? 0
            : app(RememberTokenService::class)->revokeForSessions($user, $evictedIds);

        return count($evictedIds);
    }

    public function lastEvictionRevokedPerDeviceCount(): int
    {
        return $this->lastEvictionRevokedPerDeviceCount;
    }

    /**
     * @return list<string>
     */
    public function enforceConcurrentLimitWithEvictedIds(User $user, ?string $currentSessionId = null): array
    {
        $limit = (int) config('auth_security.session.max_concurrent_sessions', 3);

        if ($limit < 1) {
            return [];
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
            ->values()
            ->all();

        if ($toEvict === []) {
            return [];
        }

        $deleted = $this->query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $toEvict)
            ->delete();

        return $deleted > 0 ? $toEvict : [];
    }

    private function query(): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'));
    }

    private function activeCutoff(): int
    {
        return now()->subSeconds(SessionLifecycle::idleTimeoutSeconds())->timestamp;
    }
}
