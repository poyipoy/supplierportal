<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthAuditLog extends Model
{
    use MassPrunable;

    public const EVENTS = [
        'login_success',
        'login_failed',
        'logout',
        'lockout',
        'other_device_logout',
        'captcha_failed',
        'captcha_provider_error',
        'mfa_challenge_failed',
        'mfa_challenge_succeeded',
        'mfa_recovery_code_used',
        'mfa_enabled',
        'mfa_disabled',
        'mfa_recovery_codes_regenerated',
        'mfa_admin_reset',
        'remember_mfa_required',
        'password_changed',
        'role_changed',
        'account_deactivated',
        'session_timeout',
        'session_revoked',
    ];

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email_attempted',
        'event',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(
            (int) config('auth_security.audit.retention_days', 180)
        ));
    }
}
