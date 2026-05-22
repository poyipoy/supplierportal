<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'currency',
        'exchange_rate_id',
        'po_number',
        'status',
        'created_by',
        'estimated_arrival',
        'actual_arrival',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimated_arrival' => 'date',
            'actual_arrival' => 'date',
        ];
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'overdue'
            || (
                $this->status === 'active'
                && $this->estimated_arrival
                && $this->estimated_arrival->isBefore(today())
                && !$this->actual_arrival
            );
    }

    // ─── Relationships ───

    /**
     * Supplier pemilik PO (langsung, bukan lewat quotation).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    /**
     * Kurs snapshot saat PO dibuat (opsional fallback).
     */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    /**
     * Semua quotation yang termasuk dalam PO ini (Many-to-Many via po_quotations).
     */
    public function quotations(): BelongsToMany
    {
        return $this->belongsToMany(Quotation::class, 'po_quotations', 'po_id', 'quotation_id')
            ->withTimestamps();
    }

    /**
     * Backward-compatible: ambil quotation pertama (untuk kode lama yang belum di-refactor).
     */
    public function quotation(): BelongsTo
    {
        // Fallback: return first quotation via pivot relationship
        // This is a "virtual" BelongsTo for backward compat in edge cases
        return $this->belongsTo(Quotation::class, 'id', 'id');
    }

    /**
     * Ambil quotation pertama dari relasi many-to-many.
     */
    public function getFirstQuotationAttribute(): ?Quotation
    {
        return $this->quotations->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PoDocument::class, 'po_id');
    }

    public function qcInspections(): HasMany
    {
        return $this->hasMany(QcInspection::class, 'po_id');
    }

    public function materialClaims(): HasMany
    {
        return $this->hasMany(MaterialClaim::class, 'po_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ─── Helpers ───

    /**
     * Ambil semua quotation items dari semua quotation di PO ini.
     */
    public function allQuotationItems(): Collection
    {
        return $this->quotations->flatMap(function ($quotation) {
            return $quotation->items;
        });
    }

    /**
     * Ambil semua PR terkait PO ini.
     */
    public function purchaseRequirements(): Collection
    {
        return $this->quotations->map(function ($q) {
            return $q->purchaseRequirement;
        })->filter()->unique('id');
    }

    /**
     * Generate the next PO number for the current month.
     */
    public static function generatePoNumber(): string
    {
        $count = static::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return 'PO/' . now()->format('m/Y') . '/' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    }
}
