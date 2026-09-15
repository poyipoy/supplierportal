<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentItem extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_REMOVED = 'REMOVED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'removed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PaymentGroup::class, 'payment_group_id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getItemReferenceAttribute(): string
    {
        if ($this->payable_type === LocalInvoice::class) {
            return $this->payable?->invoice_number ?? "#{$this->payable_id}";
        }
        if ($this->payable_type === GaClaim::class) {
            return $this->payable?->claim_number ?? "#{$this->payable_id}";
        }

        return "#{$this->payable_id}";
    }

    public function getSubtotalAmountAttribute(): float
    {
        if ($this->payable_type === LocalInvoice::class && $this->payable) {
            return (float) ($this->payable->invoice_amount ?? $this->amount);
        }

        return (float) ($this->amount ?? 0);
    }

    public function getTaxAmountAttribute(): float
    {
        if ($this->payable_type === LocalInvoice::class && $this->payable) {
            return (float) ($this->payable->tax_amount ?? 0);
        }

        return 0.0;
    }

    public function getTotalAmountAttribute(): float
    {
        return (float) ($this->amount ?? 0);
    }
}
