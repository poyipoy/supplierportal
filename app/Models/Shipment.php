<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Shipment extends Model
{
    use HasFactory, HasHashids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_ARRIVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'shipment_number',
        'supplier_id',
        'status',
        'shipment_date',
        'estimated_arrival_date',
        'actual_arrival_date',
        'notes',
        'created_by',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'shipment_date' => 'date',
            'estimated_arrival_date' => 'date',
            'actual_arrival_date' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'shipment_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class, 'shipment_id');
    }

    public function qcInspections(): HasMany
    {
        return $this->hasMany(QcInspection::class, 'shipment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ─── Helpers ───

    /**
     * Unique purchase orders derived through shipment items.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function purchaseOrders(): Collection
    {
        return $this->items->map(fn ($item) => $item->purchaseOrder)->filter()->unique('id')->values();
    }

    // ─── Scopes ───

    public function scopeVisibleToSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeActiveAllocations(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_ARRIVED]);
    }

    /**
     * Generate the next sequential shipment number atomically.
     */
    public static function generateShipmentNumber(): string
    {
        return DB::transaction(function () {
            $year = (int) now()->year;
            $month = (int) now()->month;

            $seq = DB::table('document_sequences')
                ->where('type', 'SHP')
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
                    'type' => 'SHP',
                    'year' => $year,
                    'month' => $month,
                    'last_number' => $next,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return 'SHP/'.now()->format('m/Y').'/'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }
}
