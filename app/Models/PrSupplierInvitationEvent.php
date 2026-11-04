<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of supplier invitations added to or removed from a
 * submitted purchase requisition. Rows are inserted, never updated or deleted
 * through the application.
 */
class PrSupplierInvitationEvent extends Model
{
    public const UPDATED_AT = null;

    public const ADDED = 'added';

    public const REMOVED = 'removed';

    protected $fillable = ['pr_id', 'supplier_id', 'event', 'actor_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Supplier invitation events are append-only.'));
        static::deleting(fn () => throw new LogicException('Supplier invitation events are append-only.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
