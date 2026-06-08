<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequisition extends Model
{
    use SoftDeletes;

    protected $table = 'purchase_requisitions';

    protected $fillable = [
        'period_id',
        'created_by',
        'pr_number',
        'notes',
        'status',
    ];

    // ─── Helpers ───

    public static function generatePrNumber(): string
    {
        return \Illuminate\Support\Facades\DB::transaction(function () {
            $year = (int) now()->year;
            $month = (int) now()->month;

            $seq = \Illuminate\Support\Facades\DB::table('document_sequences')
                ->where('type', 'PR')
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($seq) {
                $next = $seq->last_number + 1;
                \Illuminate\Support\Facades\DB::table('document_sequences')
                    ->where('id', $seq->id)
                    ->update(['last_number' => $next, 'updated_at' => now()]);
            } else {
                $next = 1;
                \Illuminate\Support\Facades\DB::table('document_sequences')->insert([
                    'type' => 'PR',
                    'year' => $year,
                    'month' => $month,
                    'last_number' => $next,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return 'REQ/' . now()->format('m/Y') . '/' . str_pad($next, 3, '0', STR_PAD_LEFT);
        });
    }

    // ─── Relationships ───

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrItem::class, 'pr_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'pr_id');
    }

    public function invitedSuppliers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'purchase_requisition_suppliers', 'pr_id', 'supplier_id')
            ->withPivot('invited_at')
            ->withTimestamps();
    }

    public function scopeVisibleToSupplier($query, int $supplierId)
    {
        return $query->where(function ($q) use ($supplierId) {
            $q->whereDoesntHave('invitedSuppliers')
                ->orWhereHas('invitedSuppliers', fn ($supplier) => $supplier->where('users.id', $supplierId))
                ->orWhereHas('quotations', fn ($quotation) => $quotation->where('supplier_id', $supplierId));
        });
    }

    public function isVisibleToSupplier(int $supplierId): bool
    {
        if ($this->relationLoaded('invitedSuppliers')) {
            return $this->invitedSuppliers->isEmpty()
                || $this->invitedSuppliers->contains('id', $supplierId);
        }

        return ! $this->invitedSuppliers()->exists()
            || $this->invitedSuppliers()->where('users.id', $supplierId)->exists()
            || $this->quotations()->where('supplier_id', $supplierId)->exists();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
