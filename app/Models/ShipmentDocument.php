<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ShipmentDocument extends Model
{
    use HasFactory;

    public const DOC_TYPE_INVOICE = 'invoice';

    public const DOC_TYPE_PACKING_LIST = 'packing_list';

    public const DOC_TYPE_BL = 'bl';

    public const DOC_TYPE_FORM_E = 'form_e';

    public const DOC_TYPES = [
        self::DOC_TYPE_INVOICE,
        self::DOC_TYPE_PACKING_LIST,
        self::DOC_TYPE_BL,
        self::DOC_TYPE_FORM_E,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RECEIVED,
        self::STATUS_VERIFIED,
        self::STATUS_ISSUED,
        self::STATUS_PROCESSING,
        self::STATUS_DONE,
    ];

    protected $fillable = [
        'shipment_id',
        'doc_type',
        'status',
        'document_number',
        'notes',
    ];

    // ─── Relationships ───

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function latestAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->ofMany([
                'created_at' => 'max',
                'id' => 'max',
            ]);
    }
}
