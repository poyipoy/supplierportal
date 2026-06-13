<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MaterialClaim extends Model
{
    use SoftDeletes, HasFactory, HasHashids;

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
            'deadline' => 'date',
        ];
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
}
