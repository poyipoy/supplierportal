<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property Carbon|null $estimated_arrival
 * @property Carbon|null $actual_arrival
 */
class PurchaseOrder extends Model
{
    use HasHashids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

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
                && ! $this->actual_arrival
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
     * Ambil quotation pertama dari relasi many-to-many.
     */
    public function getFirstQuotationAttribute(): ?Quotation
    {
        return $this->quotations->first();
    }

    public function getPrReferenceAttribute(): string
    {
        $reference = $this->purchaseRequisitions()
            ->pluck('pr_number')
            ->filter(fn ($number) => is_string($number) && trim($number) !== '')
            ->map(fn ($number) => trim($number))
            ->unique()
            ->implode(', ');

        return $reference !== '' ? $reference : '-';
    }

    public function scopeWherePrReferenceContains(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->whereHas('quotations.purchaseRequisition', function (Builder $requisitionQuery) use ($term) {
            $requisitionQuery->where('pr_number', 'like', '%'.$term.'%');
        });
    }

    /**
     * Project the list total while preserving QuotationItem::resolved_amount semantics.
     */
    public function scopeWithResolvedTotalIdr(Builder $query): Builder
    {
        $quantityExpression = <<<'SQL'
            CASE
                WHEN pi.quantity IS NULL OR pi.quantity < 1 THEN 1
                ELSE pi.quantity
            END
            SQL;
        $totalWeightExpression = "ROUND(COALESCE(pi.weight_needed, 0) * ({$quantityExpression}), 4)";
        $resolvedAmountExpression = <<<SQL
            CASE
                WHEN COALESCE(qi.amount, 0) > 0 THEN qi.amount
                WHEN COALESCE(qi.price_per_kg, 0) > 0
                    AND pi.id IS NOT NULL
                    AND {$totalWeightExpression} > 0
                    THEN ROUND(qi.price_per_kg * {$totalWeightExpression}, 4)
                ELSE COALESCE(qi.amount, 0)
            END
            SQL;

        return $query->selectSub(
            DB::table('po_quotations as links')
                ->join('quotations as q', 'q.id', '=', 'links.quotation_id')
                ->join('quotation_items as qi', 'qi.quotation_id', '=', 'q.id')
                ->leftJoin('pr_items as pi', 'pi.id', '=', 'qi.pr_item_id')
                ->leftJoin('exchange_rates as er', 'er.id', '=', 'q.exchange_rate_id')
                ->whereColumn('links.po_id', 'purchase_orders.id')
                ->whereNull('q.deleted_at')
                ->selectRaw("COALESCE(SUM(({$resolvedAmountExpression}) * COALESCE(er.rate_to_idr, 1)), 0)"),
            'resolved_total_idr',
        );
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
    public function purchaseRequisitions(): Collection
    {
        return $this->quotations->map(function ($q) {
            return $q->purchaseRequisition;
        })->filter()->unique('id');
    }

    /**
     * Generate the next PO number for the current month.
     */
    public static function generatePoNumber(): string
    {
        return DB::transaction(function () {
            $year = (int) now()->year;
            $month = (int) now()->month;

            $seq = DB::table('document_sequences')
                ->where('type', 'PO')
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
                    'type' => 'PO',
                    'year' => $year,
                    'month' => $month,
                    'last_number' => $next,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return 'PO/'.now()->format('m/Y').'/'.str_pad($next, 3, '0', STR_PAD_LEFT);
        });
    }
}
