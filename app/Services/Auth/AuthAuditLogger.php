<?php

namespace App\Services\Auth;

use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuthAuditLogger
{
    private const ALLOWED_METADATA = [
        'guard',
        'remember',
        'reason',
        'provider_status',
        'target_user_id',
        'actor_user_id',
        'old_role',
        'new_role',
        'route',
        'status',
        'method',
    ];

    public function write(
        string $event,
        ?User $user = null,
        ?string $email = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        try {
            $request ??= app()->bound('request') ? request() : null;

            AuthAuditLog::query()->create([
                'user_id' => $user?->getKey(),
                'email_attempted' => $this->normalizeEmail($email ?? $user?->email),
                'event' => Str::limit($event, 80, ''),
                'ip_address' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), (int) config('auth_security.audit.user_agent_max_length', 512), ''),
                'metadata' => $this->sanitizeMetadata($metadata),
            ]);
        } catch (Throwable $exception) {
            Log::error('Auth audit write failed.', [
                'event' => $event,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function normalizeEmail(?string $email): ?string
    {
        $normalized = Str::lower(trim((string) $email));

        return $normalized === '' ? null : Str::limit($normalized, 255, '');
    }

    private function sanitizeMetadata(array $metadata): ?array
    {
        $clean = [];

        foreach (array_intersect_key($metadata, array_flip(self::ALLOWED_METADATA)) as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_null($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit($value, 255, '');
            }
        }

        return $clean === [] ? null : $clean;
    }
}
