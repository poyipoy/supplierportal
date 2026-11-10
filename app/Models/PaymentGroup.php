<?php

namespace App\Models;

use App\Casts\PaymentGroupStatusCast;
use App\Enums\PaymentGroupStatus;
use App\Support\BankTransferMapping;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGroup extends Model
{
    use HasHashids;

    public const STATUS_UNPAID = 'UNPAID';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_UNPAID,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentGroupStatusCast::class,
            'subtotal_amount' => 'decimal:2',
            'bank_fee' => 'decimal:2',
            'net_payment_amount' => 'decimal:2',
            'transfer_date' => 'date',
            'voucher_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function statusEnum(): PaymentGroupStatus
    {
        return PaymentGroupStatus::tryFromWithLegacy($this->status ?? self::STATUS_UNPAID);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(PaymentItem::class)->where('status', PaymentItem::STATUS_ACTIVE);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isUnpaid(): bool
    {
        return $this->statusEnum() === PaymentGroupStatus::UNPAID;
    }

    public function isPaid(): bool
    {
        return $this->statusEnum() === PaymentGroupStatus::PAID;
    }

    public function isCancelled(): bool
    {
        return $this->statusEnum() === PaymentGroupStatus::CANCELLED;
    }

    public function isBca(): bool
    {
        return BankTransferMapping::isBca((string) $this->bank_name);
    }

    public function getNetAmountAttribute(): float
    {
        return (float) ($this->net_payment_amount ?? 0);
    }
}
