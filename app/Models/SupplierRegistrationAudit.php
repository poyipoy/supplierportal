<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierRegistrationAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_id',
        'user_id',
        'actor_id',
        'actor_role',
        'event',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SupplierRegistrationAttempt::class, 'attempt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getNotesAttribute(): ?string
    {
        return $this->reason;
    }

    /**
     * Record an immutable audit log entry.
     */
    public static function record(
        int $attemptId,
        int $userId,
        string $event,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $notes = null,
        ?string $reason = null,
        array $metadata = [],
    ): self {
        return static::create([
            'attempt_id' => $attemptId,
            'user_id' => $userId,
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? 'system',
            'event' => $event,
            'reason' => $reason ?? $notes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
