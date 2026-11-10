<?php

namespace App\Models;

use App\Casts\MaterialClaimStatusCast;
use App\Enums\MaterialClaimStatus;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialClaim extends Model
{
    use HasFactory, HasHashids, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'inspection_id',
        'po_id',
        'submitted_by',
        'supplier_id',
        'status',
        'description',
        'resolution_expected',
        'deadline',
        'supplier_response',
    ];

    protected function casts(): array
    {
        return [
            'status' => MaterialClaimStatusCast::class,
            'deadline' => 'date',
        ];
    }

    public function statusEnum(): MaterialClaimStatus
    {
        return MaterialClaimStatus::tryFromWithLegacy($this->status ?? self::STATUS_PENDING);
    }

    // ─── Relationships ───

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'inspection_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function claimAttachments(): HasMany
    {
        return $this->hasMany(ClaimAttachment::class, 'claim_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get an obfuscated, business-friendly claim identifier (e.g., CLM-YRZVAEWK).
     */
    public function getClaimNumberAttribute(): string
    {
        return 'CLM-'.strtoupper($this->hash);
    }
}
