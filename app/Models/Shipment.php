<?php

namespace App\Models;

use App\Casts\ShipmentStatusCast;
use App\Enums\ShipmentStatus;
use App\Support\BusinessTime;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /** Q6B fail-closed allow-lists; every other status is refused (T-6). */
    public const MTC_UPLOAD_STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED];

    public const MTC_DELETE_STATUSES = [self::STATUS_DRAFT];

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_ARRIVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'shipment_number',
        'invoice_number',
        'supplier_reference',
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
            'status' => ShipmentStatusCast::class,
            'shipment_date' => 'date',
            'estimated_arrival_date' => 'date',
            'actual_arrival_date' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function statusEnum(): ShipmentStatus
    {
        return ShipmentStatus::tryFromWithLegacy($this->status ?? self::STATUS_DRAFT);
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

    /**
     * Mill Test Certificates (Q6B). Shipment attachments are MTC only; the
     * four customs documents keep their own ShipmentDocument rows.
     */
    public function mtcAttachments(): MorphMany
    {
        return $this->attachments()->orderBy('id');
    }

    public function preshipmentInspection(): HasOne
    {
        return $this->hasOne(ShipmentPreshipmentInspection::class, 'shipment_id');
    }

    /**
     * D5 & E11: Compute missing mandatory shipping documents (Commercial Invoice,
     * Packing List, Bill of Lading, Form E) for dashboard/list views without extra
     * queries when documents.latestAttachment is loaded. MTC is optional and
     * reported separately via mtc_count so it never marks a shipment incomplete.
     *
     * @return array{
     *     missing_labels: list<string>,
     *     missing_types: list<string>,
     *     uploaded_count: int,
     *     total_count: int,
     *     is_complete: bool,
     *     revised_count: int,
     *     mtc_count: int
     * }
     */
    public function missingDocumentsSummary(): array
    {
        $docLabels = [
            ShipmentDocument::DOC_TYPE_INVOICE => __('shipments.copy.commercial_invoice'),
            ShipmentDocument::DOC_TYPE_PACKING_LIST => __('shipments.copy.packing_list'),
            ShipmentDocument::DOC_TYPE_BL => __('shipments.copy.bill_of_lading_bl'),
            ShipmentDocument::DOC_TYPE_FORM_E => 'Form E / COO',
        ];

        $docsByType = $this->relationLoaded('documents')
            ? $this->documents->keyBy('doc_type')
            : $this->documents()->with('latestAttachment')->get()->keyBy('doc_type');

        $missingLabels = [];
        $missingTypes = [];
        $uploadedCount = 0;
        $revisedCount = 0;

        foreach ($docLabels as $type => $label) {
            /** @var ShipmentDocument|null $doc */
            $doc = $docsByType->get($type);
            $hasFile = $doc !== null && (
                ($doc->relationLoaded('latestAttachment') ? $doc->latestAttachment !== null : $doc->latestAttachment()->exists())
                || in_array($doc->status, ['received', 'verified', 'issued', 'processing', 'done'], true)
            );
            if ($hasFile) {
                $uploadedCount++;
            } else {
                $missingLabels[] = $label;
                $missingTypes[] = $type;
            }
            if ($doc !== null && (int) ($doc->revision_count ?? 0) > 0) {
                $revisedCount += (int) $doc->revision_count;
            }
        }

        $mtcCount = array_key_exists('mtc_attachments_count', $this->attributes)
            ? (int) $this->attributes['mtc_attachments_count']
            : ($this->relationLoaded('mtcAttachments') ? $this->mtcAttachments->count() : $this->mtcAttachments()->count());

        $totalCount = 4;

        return [
            'missing_labels' => $missingLabels,
            'missing_types' => $missingTypes,
            'uploaded_count' => $uploadedCount,
            'total_count' => $totalCount,
            'is_complete' => $missingLabels === [],
            'revised_count' => $revisedCount,
            'mtc_count' => $mtcCount,
        ];
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
            $bizNow = BusinessTime::now();
            $year = (int) $bizNow->year;
            $month = (int) $bizNow->month;

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

            return 'SHP/'.$bizNow->format('m/Y').'/'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }
}
