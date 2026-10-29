<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

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
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (empty($purchaseOrderIds)) {
            return;
        }

        $docType = $shipmentDocument->doc_type;
        $targetStatus = $shipmentDocument->status;
        $targetRank = self::STATUS_RANKS[$targetStatus] ?? 0;

        DB::transaction(function () use ($purchaseOrderIds, $docType, $targetStatus, $targetRank): void {
            foreach ($purchaseOrderIds as $poId) {
                $poDoc = self::query()
                    ->where('po_id', $poId)
                    ->where('doc_type', $docType)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $poDoc) {
                    $poDoc = self::create([
                        'po_id' => $poId,
                        'doc_type' => $docType,
                        'status' => 'pending',
                    ]);

                    $poDoc = self::query()
                        ->whereKey($poDoc->id)
                        ->lockForUpdate()
                        ->first() ?? $poDoc;
                }

                $currentRank = self::STATUS_RANKS[$poDoc->status] ?? 0;
                if ($targetRank > $currentRank) {
                    $poDoc->update(['status' => $targetStatus]);
                }
            }
        });
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
