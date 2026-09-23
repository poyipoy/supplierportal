<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

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

    public const ACTIVE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_FINALIZED,
        self::STATUS_PARTIALLY_PAID,
    ];

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

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getTotalAmountAttribute(): float
    {
        return (float) ($this->total_subtotal ?? 0);
    }

    public function getBatchDateAttribute(): ?Carbon
    {
        return $this->created_at;
    }

    public function hasUnvoucheredSupplierItems(): bool
    {
        return $this->unvoucheredSupplierItemsCount() > 0;
    }

    public function unvoucheredSupplierItemsCount(): int
    {
        if ($this->batch_type !== self::TYPE_SUPPLIER) {
            return 0;
        }

        if ($this->relationLoaded('groups')) {
            $count = 0;
            foreach ($this->groups as $group) {
                $items = $group->relationLoaded('items') ? $group->items : $group->items()->get();
                foreach ($items as $item) {
                    if ($item->status === PaymentItem::STATUS_ACTIVE && $item->payable_type === LocalInvoice::class) {
                        $voucher = $item->relationLoaded('localInvoiceVoucher')
                            ? $item->localInvoiceVoucher
                            : $item->localInvoiceVoucher()->first();

                        if (! $voucher || $voucher->status !== LocalInvoiceVoucher::STATUS_FINAL) {
                            $count++;
                        }
                    }
                }
            }

            return $count;
        }

        return PaymentItem::whereHas('group', function ($q) {
            $q->where('payment_batch_id', $this->id);
        })
            ->where('status', PaymentItem::STATUS_ACTIVE)
            ->where('payable_type', LocalInvoice::class)
            ->where(function ($q) {
                $q->whereDoesntHave('localInvoiceVoucher')
                    ->orWhereHas('localInvoiceVoucher', function ($v) {
                        $v->where('status', '!=', LocalInvoiceVoucher::STATUS_FINAL);
                    });
            })
            ->count();
    }

    public function getActualPaidAmountAttribute(): float
    {
        if ($this->isPaid()) {
            return (float) $this->total_net_amount;
        }

        if ($this->batch_type === self::TYPE_SUPPLIER) {
            $paid = 0.0;
            if ($this->relationLoaded('groups')) {
                foreach ($this->groups as $group) {
                    $items = $group->relationLoaded('items') ? $group->items : $group->items()->get();
                    foreach ($items as $item) {
                        if ($item->status === PaymentItem::STATUS_ACTIVE) {
                            $payment = $item->relationLoaded('localInvoicePayment')
                                ? $item->localInvoicePayment
                                : $item->localInvoicePayment()->first();

                            if ($payment) {
                                $paid += (float) $payment->actual_paid_total;
                            }
                        }
                    }
                }

                return $paid;
            }

            return (float) LocalInvoicePayment::whereHas('item.group', function ($q) {
                $q->where('payment_batch_id', $this->id);
            })
                ->whereHas('item', function ($q) {
                    $q->where('status', PaymentItem::STATUS_ACTIVE);
                })
                ->sum('actual_paid_total');
        }

        if ($this->relationLoaded('groups')) {
            return (float) $this->groups->where('status', PaymentGroup::STATUS_PAID)->sum('net_payment_amount');
        }

        return (float) $this->groups()->where('status', PaymentGroup::STATUS_PAID)->sum('net_payment_amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        if ($this->isPaid()) {
            return 0.0;
        }

        $totalNet = (float) $this->total_net_amount;
        if ($totalNet <= 0 && $this->relationLoaded('groups')) {
            $totalNet = (float) $this->groups->sum('net_payment_amount');
        } elseif ($totalNet <= 0) {
            $totalNet = (float) $this->groups()->sum('net_payment_amount');
        }

        $remaining = $totalNet - $this->actual_paid_amount;

        return max(0.0, $remaining);
    }

    public function getOverpayments()
    {
        if ($this->relationLoaded('groups')) {
            $overpayments = collect();
            foreach ($this->groups as $group) {
                $items = $group->relationLoaded('items') ? $group->items : $group->items()->get();
                foreach ($items as $item) {
                    if ($item->status === PaymentItem::STATUS_ACTIVE) {
                        $payment = $item->relationLoaded('localInvoicePayment')
                            ? $item->localInvoicePayment
                            : $item->localInvoicePayment()->first();

                        if ($payment) {
                            $overpayment = $payment->relationLoaded('overpayment')
                                ? $payment->overpayment
                                : $payment->overpayment()->first();

                            if ($overpayment) {
                                $overpayments->push($overpayment);
                            }
                        }
                    }
                }
            }

            return $overpayments;
        }

        return SupplierOverpaymentRefund::whereHas('payment.item.group', function ($q) {
            $q->where('payment_batch_id', $this->id);
        })->get();
    }

    public function hasOverpayment(): bool
    {
        return $this->getOverpayments()->isNotEmpty();
    }

    public function hasOpenOverpayment(): bool
    {
        return $this->getOverpayments()->contains(function ($op) {
            return $op->status === SupplierOverpaymentRefund::STATUS_OPEN;
        });
    }

    public function getTotalOverpaymentAmountAttribute(): float
    {
        return (float) $this->getOverpayments()->sum('overpayment_amount');
    }

    public function getOverpaymentStatusAttribute(): ?string
    {
        $overpayments = $this->getOverpayments();
        if ($overpayments->isEmpty()) {
            return null;
        }

        if ($overpayments->contains(fn ($op) => $op->status === SupplierOverpaymentRefund::STATUS_OPEN)) {
            return SupplierOverpaymentRefund::STATUS_OPEN;
        }

        return SupplierOverpaymentRefund::STATUS_SETTLED;
    }

    public function getActualTransferredAmountAttribute(): float
    {
        return $this->actual_paid_amount + $this->total_overpayment_amount;
    }
}
