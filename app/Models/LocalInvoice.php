<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class LocalInvoice extends Model
{
    use HasHashids;

    public const STATUS_WAITING_PHYSICAL_DOCUMENT = 'WAITING_PHYSICAL_DOCUMENT';
    public const STATUS_UNDER_VERIFICATION = 'UNDER_VERIFICATION';
    public const STATUS_NEED_REVISION = 'NEED_REVISION';
    public const STATUS_READY_TO_PAY = 'READY_TO_PAY';
    public const STATUS_PAID = 'PAID';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_WAITING_PHYSICAL_DOCUMENT,
        self::STATUS_UNDER_VERIFICATION,
        self::STATUS_NEED_REVISION,
        self::STATUS_READY_TO_PAY,
        self::STATUS_PAID,
        self::STATUS_EXPIRED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'invoice_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'po_value_snapshot' => 'decimal:2',
            'po_invoiced_snapshot' => 'decimal:2',
            'po_remaining_snapshot' => 'decimal:2',
            'has_po_discrepancy' => 'boolean',
            'submitted_ppn_amount' => 'decimal:2',
            'scheduled_physical_delivery_date' => 'date',
            'missed_delivery_count' => 'integer',
            'rescheduled_at' => 'datetime',
            'cashier_received_at' => 'datetime',
            'payment_term_days_snapshot' => 'integer',
            'revision_number' => 'integer',
            'submitted_at' => 'datetime',
            'physical_verified_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'ready_to_pay_at' => 'datetime',
            'due_date' => 'date',
            'payment_scheduled_at' => 'datetime',
            'scheduled_payment_date' => 'date',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function revisions()
    {
        return $this->hasMany(LocalInvoiceRevision::class);
    }

    public function latestRevision(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LocalInvoiceRevision::class)->ofMany([
            'revision_number' => 'max',
        ]);
    }

    public function documents()
    {
        return $this->hasMany(LocalInvoiceDocument::class);
    }

    public function receipt()
    {
        return $this->hasOne(LocalInvoiceReceipt::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(LocalInvoiceStatusHistory::class);
    }

    public function physicalVerifications()
    {
        return $this->hasMany(LocalInvoicePhysicalVerification::class);
    }

    public function verifications()
    {
        return $this->hasMany(LocalInvoiceVerification::class);
    }

    public function currentVerification(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LocalInvoiceVerification::class)->ofMany([
            'revision_number' => 'max',
        ]);
    }

    public function paymentItem()
    {
        return $this->morphOne(PaymentItem::class, 'payable');
    }

    public function localPurchaseOrder()
    {
        return $this->belongsTo(LocalPurchaseOrder::class, 'local_purchase_order_id');
    }

    public function goodsReceiptHistories()
    {
        return $this->hasMany(LocalInvoiceGoodsReceipt::class);
    }

    public function activeGoodsReceiptHistories()
    {
        return $this->hasMany(LocalInvoiceGoodsReceipt::class)->whereIn('state', [LocalInvoiceGoodsReceipt::STATE_RESERVED, LocalInvoiceGoodsReceipt::STATE_CONSUMED]);
    }

    public function voucher()
    {
        return $this->hasOne(LocalInvoiceVoucher::class);
    }

    public function payment()
    {
        return $this->hasOne(LocalInvoicePayment::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID || $this->status === 'COMPLETED';
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isReadyToPay(): bool
    {
        return $this->status === self::STATUS_READY_TO_PAY || $this->status === 'APPROVED';
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid()
            && ! $this->isExpired()
            && $this->due_date !== null
            && $this->due_date->lt(today());
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    public function paymentCategory(): string
    {
        if ($this->isPaid()) {
            return 'Completed';
        }
        if ($this->isOverdue()) {
            return 'Overdue';
        }
        if ($this->due_date && $this->due_date->lt(today()->addDays(7))) {
            return 'Due < 7 Days';
        }

        if ($this->status === 'PAYMENT_SCHEDULED' || $this->scheduled_payment_date) {
            return 'Scheduled';
        }

        return $this->isReadyToPay() ? 'Ready to Pay' : 'Unscheduled';
    }

    public function remainingDays(): ?int
    {
        return $this->due_date ? (int) today()->diffInDays($this->due_date, false) : null;
    }

    public function scopeOverdue(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotIn('status', [self::STATUS_PAID, 'COMPLETED', self::STATUS_EXPIRED])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());
    }

    public function scopeIsOverdue(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $this->scopeOverdue($query);
    }
}
