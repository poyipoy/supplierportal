<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'pr_id',
        'supplier_id',
        'exchange_rate_id',
        'currency',
        'status',
        'submitted_at',
        'estimated_delivery',
        'payment_terms',
        'validity_period',
        'general_notes'
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'estimated_delivery' => 'date',
            'validity_period' => 'date',
        ];
    }

    public function isExpired(): bool
    {
        return $this->validity_period !== null && $this->validity_period->lt(today());
    }

    public function canRequestRevision(): bool
    {
        return $this->status === self::STATUS_SUBMITTED
            && $this->isExpired()
            && $this->purchaseOrders()->count() === 0;
    }

    public function canBeRevisedBySupplier(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_REVISION_REQUESTED,
        ], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Terkirim',
            self::STATUS_REVISION_REQUESTED => 'Perlu Revisi',
            self::STATUS_ACCEPTED => 'Diterima',
            self::STATUS_REJECTED => 'Ditolak',
            default => ucwords(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'bg-secondary',
            self::STATUS_SUBMITTED => 'bg-primary',
            self::STATUS_REVISION_REQUESTED => 'bg-warning text-dark',
            self::STATUS_ACCEPTED => 'bg-success',
            self::STATUS_REJECTED => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    // ─── Relationships ───

    public function purchaseRequirement(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequirement::class, 'pr_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function exchange_rate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->exchange_rate();
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    /**
     * Semua PO yang mencakup quotation ini (Many-to-Many).
     */
    public function purchaseOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class, 'po_quotations', 'quotation_id', 'po_id')
            ->withTimestamps();
    }

    /**
     * Backward-compatible: ambil PO pertama.
     */
    public function getFirstPurchaseOrderAttribute(): ?PurchaseOrder
    {
        return $this->purchaseOrders->first();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
