<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierRegistrationAttempt extends Model
{
    use HasHashids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_REVISION = 'REVISION';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'user_id',
        'attempt_number',
        'status',
        'submission_snapshot',
        'submission_checksum',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'revision_reason',
        'rejection_reason',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'submission_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(SupplierRegistrationAudit::class, 'attempt_id');
    }

    // ─── Helpers & Accessors ───

    public function getSnapshotAttribute(): array
    {
        return $this->submission_snapshot ?? [];
    }

    public function getReviewerNotesAttribute(): ?string
    {
        return $this->revision_reason ?: $this->rejection_reason;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRevision(): bool
    {
        return $this->status === self::STATUS_REVISION;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
