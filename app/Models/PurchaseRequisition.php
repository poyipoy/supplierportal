<?php

namespace App\Models;

use App\Support\BusinessTime;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class PurchaseRequisition extends Model
{
    use HasFactory, HasHashids, SoftDeletes;

    protected $table = 'purchase_requisitions';

    protected $fillable = [
        'period_id',
        'created_by',
        'pr_number',
        'notes',
        'status',
        'port_of_discharge',
        'incoterm',
    ];

    // ─── Helpers ───

    public static function generatePrNumber(): string
    {
        return DB::transaction(function () {
            $bizNow = BusinessTime::now();
            $year = (int) $bizNow->year;
            $month = (int) $bizNow->month;

            $seq = DB::table('document_sequences')
                ->where('type', 'PR')
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($seq) {
                $next = $seq->last_number + 1;
                DB::table('document_sequences')
                    ->where('id', $seq->id)
                    ->update(['last_number' => $next, 'updated_at' => now()]);
            } else {
                $next = 1;
                DB::table('document_sequences')->insert([
                    'type' => 'PR',
                    'year' => $year,
                    'month' => $month,
                    'last_number' => $next,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return 'REQ/'.$bizNow->format('m/Y').'/'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
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

    /**
     * Read visibility for supplier lists and the request overview: visible to the
     * supplier and either open for quotation or holding the supplier's own
     * non-trashed quotation. Grants no write rights.
     */
    public function scopeAccessibleBySupplier($query, int $supplierId)
    {
        return $query->visibleToSupplier($supplierId)
            ->where(function ($query) use ($supplierId) {
                $query->whereIn('status', ['submitted', 'bidding'])
                    ->orWhereHas('quotations', function ($q) use ($supplierId) {
                        $q->where('supplier_id', $supplierId);
                    });
            });
    }

    /**
     * The quotation action Supplier\QuotationController::create()/show() would
     * allow: create/continue/revise open the form, view opens the quotation.
     * Uses `has_invitations`/`is_invited` when selected, else queries.
     *
     * @return array{type: ?string, url: ?string, quotation: ?Quotation}
     */
    public function supplierQuotationAction(User $supplier): array
    {
        $supplierId = (int) $supplier->id;
        $quotation = $this->relationLoaded('quotations')
            ? $this->quotations->firstWhere('supplier_id', $supplierId)
            : $this->quotations()->where('supplier_id', $supplierId)->first();

        // create() checks the loaded invitation list: open, or this supplier invited.
        $hasInvitations = array_key_exists('has_invitations', $this->attributes)
            ? (bool) $this->has_invitations
            : $this->invitedSuppliers()->exists();
        $isInvited = array_key_exists('is_invited', $this->attributes)
            ? (bool) $this->is_invited
            : $this->invitedSuppliers()->where('users.id', $supplierId)->exists();
        $canWrite = in_array($this->status, ['submitted', 'bidding'], true) && (! $hasInvitations || $isInvited);

        if ($canWrite && (! $quotation || ($quotation->canBeRevisedBySupplier() && ! $quotation->isReferencedByAwardOrPurchaseOrder()))) {
            $type = match ($quotation?->status) {
                null => 'create',
                Quotation::STATUS_REVISION_REQUESTED => 'revise',
                default => 'continue',
            };

            return ['type' => $type, 'url' => route('supplier.quotations.create', $this), 'quotation' => $quotation];
        }

        if ($quotation) {
            return ['type' => 'view', 'url' => route('supplier.quotations.show', $quotation), 'quotation' => $quotation];
        }

        return ['type' => null, 'url' => null, 'quotation' => null];
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

    public function awards(): HasMany
    {
        return $this->hasMany(PrItemAward::class, 'pr_id');
    }
}
