<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only award history (decision 02). Rows are inserted, never updated
 * or deleted through the application.
 */
class PrItemAwardEvent extends Model
{
    public const UPDATED_AT = null;

    public const SELECTION_CLEARED = 'selection_cleared';

    // Mission 04-W write path.
    public const ORDERED_QTY_SELECTED = 'ordered_qty_selected';

    public const SPEC_ACCEPTED = 'spec_accepted';

    public const SPEC_ACCEPTANCE_REVOKED = 'spec_acceptance_revoked';

    public const ORDERED_VALUES_FROZEN = 'ordered_values_frozen';

    protected $fillable = [
        'pr_item_award_id',
        'pr_id',
        'pr_item_id',
        'quotation_item_id',
        'purchase_order_id',
        'event',
        'payload',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Award events are append-only.'));
        static::deleting(fn () => throw new LogicException('Award events are append-only.'));
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(PrItemAward::class, 'pr_item_award_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
