<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpDeltaRow extends Model
{
    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_APPLIED = 'applied';

    public const RECONCILIATION_IGNORED = 'ignored';

    protected $fillable = [
        'supplier_id',
        'doc_no',
        'item_code',
        'qty',
        'price',
        'status',
        'row_hash',
        'last_synced_by',
        'reconciliation_status',
        'matched_local_po_id',
        'applied_at',
        'applied_by',
        'ignored_at',
        'ignored_by',
        'apply_note',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'qty' => 'decimal:4',
            'price' => 'decimal:2',
            'last_synced_by' => 'integer',
            'matched_local_po_id' => 'integer',
            'applied_at' => 'datetime',
            'applied_by' => 'integer',
            'ignored_at' => 'datetime',
            'ignored_by' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function syncedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_synced_by');
    }

    public function matchedLocalPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(LocalPurchaseOrder::class, 'matched_local_po_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by');
    }
}
