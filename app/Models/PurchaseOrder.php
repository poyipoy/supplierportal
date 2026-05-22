<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'quotation_id',
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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
