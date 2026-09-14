<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGroup extends Model
{
    use HasHashids;

    public const STATUS_UNPAID = 'UNPAID';
    public const STATUS_PAID = 'PAID';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'decimal:2',
            'bank_fee' => 'decimal:2',
            'net_payment_amount' => 'decimal:2',
            'transfer_date' => 'date',
            'voucher_date' => 'date',
            'paid_at' => 'datetime',
        ];
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

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isBca(): bool
    {
        return strtoupper(trim($this->bank_name)) === 'BCA';
    }
}
