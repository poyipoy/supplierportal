<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierRegistrationAccess extends Model
{
    protected $table = 'supplier_registration_access';

    protected $fillable = [
        'user_id',
        'registration_reference',
        'token_hash',
        'issued_at',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(SupplierRegistrationAttempt::class, 'user_id', 'user_id')
            ->ofMany('attempt_number', 'max');
    }

    // ─── Helpers ───

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasFinalResult(): bool
    {
        return $this->latestAttempt?->isApproved() || $this->latestAttempt?->isRejected();
    }

    /** A final decision revokes workflow access, but its result remains readable. */
    public function canViewStatus(): bool
    {
        return $this->user?->isSupplier()
            && $this->latestAttempt !== null
            && $this->expires_at?->isFuture()
            && (! $this->isRevoked() || $this->hasFinalResult());
    }
}
