<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class PaymentBatch extends Model
{
    use HasHashids;

    public const TYPE_SUPPLIER = 'SUPPLIER';
    public const TYPE_GA = 'GA';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_FINALIZED = 'FINALIZED';
    public const STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';
    public const STATUS_PAID = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_subtotal' => 'decimal:2',
            'total_bank_fee' => 'decimal:2',
            'total_net_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(PaymentGroup::class);
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(PaymentItem::class, PaymentGroup::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_PAID;
    }
}
