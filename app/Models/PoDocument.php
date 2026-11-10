<?php

namespace App\Models;

use App\Casts\CustomsDocumentStatusCast;
use App\Enums\CustomsDocumentStatus;
use App\Support\Procurement\CustomsDocumentationProgressCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    protected function casts(): array
    {
        return [
            'status' => CustomsDocumentStatusCast::class,
        ];
    }

    public function statusEnum(): CustomsDocumentStatus
    {
        return CustomsDocumentStatus::tryFromWithLegacy($this->status ?? 'pending');
    }

    /**
     * Refresh the PO document status of every purchase order linked to the
     * shipment once the caller's transaction commits.
     */
    public static function syncFromShipmentDocument(ShipmentDocument $shipmentDocument): void
    {
        $shipment = $shipmentDocument->shipment ?? $shipmentDocument->shipment()->with('items')->first();
        if (! $shipment) {
            return;
        }

        self::recomputeAfterCommit(
            $shipment->items->pluck('purchase_order_id')->all(),
            [$shipmentDocument->doc_type],
        );
    }

    /**
     * Schedule recomputeForPurchaseOrders() after the current transaction
     * commits (immediately when none is open).
     *
     * Recomputing inside the writing transaction would read sibling shipments
     * through a snapshot taken before concurrent writers committed; running it
     * afterwards under the po_documents row lock lets the last writer see every
     * committed shipment document.
     *
     * @param  array<int, int|string|null>  $purchaseOrderIds
     * @param  array<int, string>|null  $docTypes
     */
    public static function recomputeAfterCommit(array $purchaseOrderIds, ?array $docTypes = null): void
    {
        if (self::normalizePurchaseOrderIds($purchaseOrderIds) === []) {
            return;
        }

        DB::afterCommit(function () use ($purchaseOrderIds, $docTypes): void {
            try {
                self::recomputeForPurchaseOrders($purchaseOrderIds, $docTypes);
            } catch (Throwable $exception) {
                // The shipment change is already committed. Drift is reported by
                // procurement:reconcile and repaired by procurement:resync-document-status.
                report($exception);
            }
        });
    }

    /**
     * Persist the derived status (least advanced active shipment document, see
     * CustomsDocumentationProgressCalculator::derivedStatuses()) for each PO and
     * doc type, moving it up or down. Legacy POs keep their manual status.
     *
     * @param  array<int, int|string|null>  $purchaseOrderIds
     * @param  array<int, string>|null  $docTypes
     * @return int Number of po_documents rows written
     */
    public static function recomputeForPurchaseOrders(array $purchaseOrderIds, ?array $docTypes = null): int
    {
        $poIds = self::normalizePurchaseOrderIds($purchaseOrderIds);
        $docTypes = array_values(array_intersect(
            CustomsDocumentationProgressCalculator::DOC_TYPES,
            $docTypes ?? CustomsDocumentationProgressCalculator::DOC_TYPES,
        ));

        if ($poIds === [] || $docTypes === []) {
            return 0;
        }

        $calculator = app(CustomsDocumentationProgressCalculator::class);

        return DB::transaction(function () use ($poIds, $docTypes, $calculator): int {
            // Lock first so the reads below see every writer that committed before us.
            $lockedByPo = self::query()
                ->whereIn('po_id', $poIds)
                ->whereIn('doc_type', $docTypes)
                ->orderBy('po_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('po_id');

            $purchaseOrders = PurchaseOrder::query()
                ->with(['documents', 'shipmentItems.shipment.documents'])
                ->whereIn('id', $poIds)
                ->get()
                ->keyBy('id');

            $written = 0;

            foreach ($poIds as $poId) {
                /** @var PurchaseOrder|null $po */
                $po = $purchaseOrders->get($poId);
                if (! $po) {
                    continue;
                }

                $derived = $calculator->derivedStatuses($po);
                $lockedRows = $lockedByPo->get($poId, collect());

                foreach ($docTypes as $docType) {
                    $target = $derived[$docType];
                    $rows = $lockedRows->where('doc_type', $docType);

                    if ($rows->isEmpty()) {
                        // Historical POs may lack a row; every PO keeps all four.
                        self::create(['po_id' => $poId, 'doc_type' => $docType, 'status' => $target]);
                        $written++;

                        continue;
                    }

                    foreach ($rows as $row) {
                        if ($row->status !== $target) {
                            $row->update(['status' => $target]);
                            $written++;
                        }
                    }
                }
            }

            return $written;
        }, 3);
    }

    /**
     * @param  array<int, int|string|null>  $purchaseOrderIds
     * @return array<int, int>
     */
    private static function normalizePurchaseOrderIds(array $purchaseOrderIds): array
    {
        return collect($purchaseOrderIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
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
