<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PoDocument extends Model
{
    public const STATUSES = [
        'pending',
        'received',
        'verified',
        'issued',
        'processing',
        'done',
    ];

    public const STATUS_RANKS = [
        'pending' => 0,
        'issued' => 1,
        'processing' => 1,
        'received' => 2,
        'verified' => 3,
        'done' => 3,
    ];

    protected $fillable = [
        'po_id',
        'doc_type',
        'status',
    ];

    /**
     * Synchronize PO document status from a shipment document for all affected purchase orders.
     */
    public static function syncFromShipmentDocument(ShipmentDocument $shipmentDocument): void
    {
        $shipment = $shipmentDocument->shipment ?? $shipmentDocument->shipment()->with('items.purchaseOrder')->first();
        if (! $shipment) {
            return;
        }

        $purchaseOrderIds = $shipment->items
            ->pluck('purchase_order_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($purchaseOrderIds)) {
            return;
        }

        $docType = $shipmentDocument->doc_type;
        $targetStatus = $shipmentDocument->status;
        $targetRank = self::STATUS_RANKS[$targetStatus] ?? 0;

        foreach ($purchaseOrderIds as $poId) {
            $poDoc = self::firstOrCreate(
                ['po_id' => $poId, 'doc_type' => $docType],
                ['status' => 'pending']
            );

            $currentRank = self::STATUS_RANKS[$poDoc->status] ?? 0;
            if ($targetRank > $currentRank) {
                $poDoc->update(['status' => $targetStatus]);
            }
        }
    }

    // ─── Relationships ───

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
