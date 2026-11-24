<?php

namespace App\Models;

use App\Casts\PurchaseRequisitionStatusCast;
use App\Enums\PurchaseRequisitionStatus;
use App\Support\BusinessTime;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseRequisition extends Model
{
    use HasFactory, HasHashids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_BIDDING = 'bidding';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

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

    protected function casts(): array
    {
        return [
            'status' => PurchaseRequisitionStatusCast::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function statusEnum(): PurchaseRequisitionStatus
    {
        return PurchaseRequisitionStatus::tryFromWithLegacy($this->status ?? self::STATUS_DRAFT);
    }

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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrItem::class, 'pr_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'pr_id');
    }

    /** Active supplier invitations only (excludes soft-cancelled invitations). */
    public function invitedSuppliers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'purchase_requisition_suppliers', 'pr_id', 'supplier_id')
            ->wherePivotNull('cancelled_at')
            ->withPivot(['invited_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])
            ->withTimestamps();
    }

    /** Soft-cancelled supplier invitations on this requisition. */
    public function cancelledInvitedSuppliers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'purchase_requisition_suppliers', 'pr_id', 'supplier_id')
            ->wherePivotNotNull('cancelled_at')
            ->withPivot(['invited_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])
            ->withTimestamps();
    }

    /** All invitation pivot rows (active and soft-cancelled). */
    public function allInvitedSuppliers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'purchase_requisition_suppliers', 'pr_id', 'supplier_id')
            ->withPivot(['invited_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])
            ->withTimestamps();
    }

    public function supplierInvitationEvents(): HasMany
    {
        return $this->hasMany(PrSupplierInvitationEvent::class, 'pr_id');
    }

    /**
     * Active participation visibility: excludes soft-cancelled invitations and
     * cancelled PRs so dashboards and active response counters remain accurate.
     */
    public function scopeVisibleToSupplier($query, int $supplierId)
    {
        return $query->whereNotIn('status', [self::STATUS_DRAFT, self::STATUS_REJECTED, self::STATUS_CANCELLED])
            ->where(function ($q) use ($supplierId) {
                $q->whereDoesntHave('allInvitedSuppliers')
                    ->orWhereHas('invitedSuppliers', fn ($supplier) => $supplier->where('users.id', $supplierId))
                    ->orWhereHas('quotations', fn ($quotation) => $quotation->where('supplier_id', $supplierId));
            });
    }

    /**
     * Read visibility for supplier lists and the request overview: includes active
     * open PRs, PRs with the supplier's own non-trashed quotation, PRs where the
     * supplier's invitation was cancelled, and cancelled PRs the supplier was
     * eligible for. Grants no write rights.
     */
    public function scopeAccessibleBySupplier($query, int $supplierId)
    {
        return $query->where(function ($outer) use ($supplierId) {
            $outer->where(function ($active) use ($supplierId) {
                $active->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_BIDDING])
                    ->visibleToSupplier($supplierId);
            })
                ->orWhere(function ($quoted) use ($supplierId) {
                    $quoted->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_BIDDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED])
                        ->whereHas('quotations', fn ($q) => $q->where('supplier_id', $supplierId));
                })
                ->orWhere(function ($removed) use ($supplierId) {
                    $removed->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_BIDDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED])
                        ->whereHas('cancelledInvitedSuppliers', fn ($supplier) => $supplier->where('users.id', $supplierId));
                })
                ->orWhere(function ($cancelled) use ($supplierId) {
                    $cancelled->where('status', self::STATUS_CANCELLED)
                        ->where(function ($eligibility) use ($supplierId) {
                            $eligibility->whereDoesntHave('allInvitedSuppliers')
                                ->orWhereHas('invitedSuppliers', fn ($supplier) => $supplier->where('users.id', $supplierId));
                        });
                });
        });
    }

    /**
     * Filter PRs that appear as Cancelled in the given supplier's POV
     * (either whole PR cancelled or this supplier's invitation was soft-cancelled).
     */
    public function scopeCancelledForSupplier($query, int $supplierId)
    {
        return $query->where(function ($q) use ($supplierId) {
            $q->where('status', self::STATUS_CANCELLED)
                ->orWhereHas('cancelledInvitedSuppliers', fn ($supplier) => $supplier->where('users.id', $supplierId));
        });
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check whether the given supplier's invitation was soft-cancelled.
     * Note: When `is_cancelled_invitation` is selected via `withExists`, callers must
     * scope that subquery to the same `$supplierId` passed here.
     */
    public function hasCancelledInvitationFor(int $supplierId): bool
    {
        if (array_key_exists('is_cancelled_invitation', $this->attributes)) {
            return (bool) $this->attributes['is_cancelled_invitation'];
        }

        if ($this->relationLoaded('cancelledInvitedSuppliers')) {
            return $this->cancelledInvitedSuppliers->contains('id', $supplierId);
        }

        if ($this->relationLoaded('allInvitedSuppliers')) {
            return $this->allInvitedSuppliers->contains(
                fn ($supplier) => (int) $supplier->id === $supplierId && $supplier->pivot?->cancelled_at !== null
            );
        }

        return $this->cancelledInvitedSuppliers()->where('users.id', $supplierId)->exists();
    }

    public function isCancelledForSupplier(int $supplierId): bool
    {
        return $this->isCancelled() || $this->hasCancelledInvitationFor($supplierId);
    }

    /**
     * Resolve the cancellation timestamp for a single supplier POV (pivot timestamp first,
     * then whole-PR `cancelled_at`). Callers preloading `cancelledInvitedSuppliers` should
     * scope it to `$supplierId`.
     */
    public function supplierCancelledAt(int $supplierId): ?Carbon
    {
        $pivotTimestamp = null;

        if ($this->relationLoaded('cancelledInvitedSuppliers')) {
            $match = $this->cancelledInvitedSuppliers->firstWhere('id', $supplierId);
            $pivotTimestamp = $match?->pivot?->cancelled_at;
        } elseif ($this->relationLoaded('allInvitedSuppliers')) {
            $match = $this->allInvitedSuppliers->firstWhere('id', $supplierId);
            $pivotTimestamp = $match?->pivot?->cancelled_at;
        } else {
            $pivotTimestamp = DB::table('purchase_requisition_suppliers')
                ->where('pr_id', $this->id)
                ->where('supplier_id', $supplierId)
                ->value('cancelled_at');
        }

        if ($pivotTimestamp !== null) {
            return Carbon::parse($pivotTimestamp);
        }

        return $this->cancelled_at;
    }

    public function canBeCancelled(): bool
    {
        if ($this->status !== self::STATUS_SUBMITTED) {
            return false;
        }

        return ! Quotation::withTrashed()->where('pr_id', $this->id)->exists()
            && ! $this->awards()->exists()
            && ! PurchaseOrder::withTrashed()->whereHas('quotations', fn ($q) => $q->where('pr_id', $this->id))->exists();
    }

    /**
     * The quotation action Supplier\QuotationController::create()/show() would
     * allow: create/continue/revise open the form, view opens the quotation.
     * Uses `has_invitations`/`is_invited`/`is_cancelled_invitation` when selected, else queries.
     *
     * @return array{type: ?string, url: ?string, quotation: ?Quotation}
     */
    public function supplierQuotationAction(User $supplier): array
    {
        $supplierId = (int) $supplier->id;

        if ($this->isCancelledForSupplier($supplierId)) {
            return ['type' => null, 'url' => null, 'quotation' => null];
        }

        $quotation = $this->relationLoaded('quotations')
            ? $this->quotations->firstWhere('supplier_id', $supplierId)
            : $this->quotations()->where('supplier_id', $supplierId)->first();

        $hasInvitations = array_key_exists('has_any_invitations', $this->attributes)
            ? (bool) $this->has_any_invitations
            : (array_key_exists('has_invitations', $this->attributes)
                ? (bool) $this->has_invitations
                : $this->allInvitedSuppliers()->exists());
        $isInvited = array_key_exists('is_invited', $this->attributes)
            ? (bool) $this->is_invited
            : $this->invitedSuppliers()->where('users.id', $supplierId)->exists();
        $canWrite = in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_BIDDING], true) && (! $hasInvitations || $isInvited);

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
        if (in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED, self::STATUS_CANCELLED], true) || $this->hasCancelledInvitationFor($supplierId)) {
            return false;
        }

        if ($this->relationLoaded('allInvitedSuppliers')) {
            if ($this->allInvitedSuppliers->isEmpty()) {
                return true;
            }

            return $this->allInvitedSuppliers->contains(
                fn ($supplier) => (int) $supplier->id === $supplierId && $supplier->pivot?->cancelled_at === null
            ) || ($this->relationLoaded('quotations')
                ? $this->quotations->contains('supplier_id', $supplierId)
                : $this->quotations()->where('supplier_id', $supplierId)->exists());
        }

        return ! $this->allInvitedSuppliers()->exists()
            || $this->invitedSuppliers()->where('users.id', $supplierId)->exists()
            || $this->quotations()->where('supplier_id', $supplierId)->exists();
    }

    public function isAccessibleBySupplier(int $supplierId): bool
    {
        if (! in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_BIDDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        if ($this->hasCancelledInvitationFor($supplierId)) {
            return true;
        }

        if ($this->isCancelled()) {
            if ($this->relationLoaded('allInvitedSuppliers')) {
                if ($this->allInvitedSuppliers->isEmpty()) {
                    return true;
                }

                return $this->allInvitedSuppliers->contains(
                    fn ($supplier) => (int) $supplier->id === $supplierId && $supplier->pivot?->cancelled_at === null
                );
            }

            if (! $this->allInvitedSuppliers()->exists()) {
                return true;
            }

            return $this->relationLoaded('invitedSuppliers')
                ? $this->invitedSuppliers->contains('id', $supplierId)
                : $this->invitedSuppliers()->where('users.id', $supplierId)->exists();
        }

        if (in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_BIDDING], true)) {
            return $this->isVisibleToSupplier($supplierId);
        }

        return $this->relationLoaded('quotations')
            ? $this->quotations->contains('supplier_id', $supplierId)
            : $this->quotations()->where('supplier_id', $supplierId)->exists();
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
