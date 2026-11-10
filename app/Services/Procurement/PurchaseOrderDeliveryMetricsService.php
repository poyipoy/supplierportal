<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\Attachment;
use App\Models\MaterialClaim;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Support\Procurement\CustomsDocumentationProgressCalculator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderDeliveryMetricsService
{
    /**
     * Aggregate customs documentation status combining PO documents and linked shipment documents.
     *
     * @return array<string, array{
     *     doc_type: string,
     *     label: string,
     *     po_doc: ?PoDocument,
     *     status: string,
     *     shipment_documents: array<int, array{
     *         shipment: Shipment,
     *         shipment_id: int,
     *         shipment_hash: string,
     *         shipment_number: string,
     *         status: string,
     *         document_number: ?string,
     *         attachment: ?Attachment
     *     }>
     * }>
     */
    public function customsDocumentationSummary(PurchaseOrder $po): array
    {
        $po->loadMissing([
            'documents',
            'shipmentItems.shipment.documents.latestAttachment',
        ]);

        $docLabels = [
            'invoice' => __('purchasing.copy.commercial_invoice'),
            'bl' => __('purchasing.copy.bill_of_lading_b_l'),
            'packing_list' => __('purchasing.copy.packing_list'),
            'form_e' => 'Form-E Certificate',
        ];

        $poDocsByType = $po->documents->keyBy('doc_type');
        $shipments = $po->shipments();
        $derivedStatuses = app(CustomsDocumentationProgressCalculator::class)->derivedStatuses($po);

        $summary = [];

        foreach (['invoice', 'bl', 'packing_list', 'form_e'] as $docType) {
            $poDoc = $poDocsByType->get($docType);
            $shipmentDocs = [];

            foreach ($shipments as $shipment) {
                $sDoc = $shipment->documents->firstWhere('doc_type', $docType);
                if ($sDoc) {
                    $attachment = $sDoc->relationLoaded('latestAttachment')
                        ? $sDoc->latestAttachment
                        : ($sDoc->attachments()->latest('id')->first() ?? $sDoc->latestAttachment);

                    $shipmentDocs[] = [
                        'shipment' => $shipment,
                        'shipment_id' => $shipment->id,
                        'shipment_hash' => $shipment->hash,
                        'shipment_number' => $shipment->shipment_number,
                        'status' => $sDoc->status,
                        'document_number' => $sDoc->document_number,
                        'attachment' => $attachment,
                    ];
                }
            }

            $summary[$docType] = [
                'doc_type' => $docType,
                'label' => $docLabels[$docType] ?? ucfirst(str_replace('_', ' ', $docType)),
                'po_doc' => $poDoc,
                'status' => $derivedStatuses[$docType] ?? ($poDoc?->status ?? PoDocument::STATUSES[0]),
                'shipment_documents' => $shipmentDocs,
            ];
        }

        return $summary;
    }

    public function deliveryProgress(PurchaseOrder $po): string
    {
        if ($po->shipmentItems()->doesntExist()) {
            return $po->actual_arrival ? 'received' : 'not_shipped';
        }

        $totalOrdered = $po->total_ordered_quantity;

        $arrivedAllocations = (int) $po->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_ARRIVED))
            ->sum('shipped_qty');

        if ($totalOrdered > 0 && $arrivedAllocations >= $totalOrdered) {
            return 'received';
        }

        $activeAllocations = (int) $po->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
            ->sum('shipped_qty');

        if ($activeAllocations <= 0) {
            return 'not_shipped';
        }

        if ($totalOrdered > 0 && $activeAllocations >= $totalOrdered) {
            return 'fully_shipped';
        }

        return 'partially_shipped';
    }

    /**
     * @return array<string, int|float|bool>
     */
    public function itemFulfillmentStatus(PurchaseOrder $po, int $quotationItemId, ?int $excludeShipmentId = null): array
    {
        $quotationItem = QuotationItem::with('prItem')->findOrFail($quotationItemId);
        $orderedQty = $po->orderedLineFor($quotationItemId)?->qty ?? $quotationItem->fulfillment_quantity;

        $query = $po->shipmentItems()
            ->where('quotation_item_id', $quotationItemId)
            ->whereHas('shipment', fn ($shipmentQuery) => $shipmentQuery
                ->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
            ->with([
                'shipment',
                'qcItems.inspection.materialClaims',
            ])
            ->orderBy('id');

        if ($excludeShipmentId !== null) {
            $query->where('shipment_id', '!=', $excludeShipmentId);
        }

        return $this->tallyFulfillment($query->get(), $orderedQty, (int) $po->id);
    }

    /**
     * @param  iterable<ShipmentItem>  $shipmentItems
     * @return array<string, int|float|bool>
     */
    public function tallyFulfillment(iterable $shipmentItems, int $orderedQty, int $poId): array
    {
        $physicalShippedQty = 0;
        $physicalArrivedQty = 0;
        $acceptedQty = 0;
        $ngQty = 0;
        $replacementEligibleQty = 0;
        $reservedQty = 0;
        $inTransitQty = 0;
        $waitingQcQty = 0;
        $unresolvedNgQty = 0;

        foreach ($shipmentItems as $shipmentItem) {
            $qty = (int) $shipmentItem->shipped_qty;
            $physicalShippedQty += $qty;

            if ($shipmentItem->shipment?->status !== Shipment::STATUS_ARRIVED) {
                $reservedQty += $qty;
                $inTransitQty += $qty;

                continue;
            }

            $physicalArrivedQty += $qty;
            $qcItems = $shipmentItem->qcItems->filter(function (QcItem $qcItem) use ($shipmentItem, $poId) {
                return $qcItem->inspection
                    && (int) $qcItem->inspection->po_id === $poId
                    && (int) $qcItem->inspection->shipment_id === (int) $shipmentItem->shipment_id;
            });

            if ($qcItems->isEmpty()) {
                $reservedQty += $qty;
                $waitingQcQty += $qty;

                continue;
            }

            if ($qcItems->contains(fn (QcItem $qcItem) => $qcItem->status === 'ng')) {
                $ngQty += $qty;
                $claims = $qcItems
                    ->map(fn (QcItem $qcItem) => $qcItem->inspection?->materialClaims)
                    ->filter()
                    ->flatten();
                $hasActiveClaim = $claims->contains(fn (MaterialClaim $claim) => in_array(
                    $claim->status,
                    ['pending', 'responded', 'escalated'],
                    true
                ));
                $hasResolvedClaim = $claims->contains(fn (MaterialClaim $claim) => $claim->status === 'resolved');

                if ($hasResolvedClaim && ! $hasActiveClaim) {
                    $replacementEligibleQty += $qty;
                } else {
                    $reservedQty += $qty;
                    $unresolvedNgQty += $qty;
                }

                continue;
            }

            if ($qcItems->contains(fn (QcItem $qcItem) => $qcItem->status === 'ok')) {
                $acceptedQty += $qty;
            } else {
                $reservedQty += $qty;
                $waitingQcQty += $qty;
            }
        }

        $allocatedQty = $acceptedQty + $reservedQty;
        $remainingQty = max(0, $orderedQty - $allocatedQty);

        return [
            'ordered_qty' => $orderedQty,
            'physical_shipped_qty' => $physicalShippedQty,
            'physical_arrived_qty' => $physicalArrivedQty,
            'accepted_qty' => $acceptedQty,
            'ng_qty' => $ngQty,
            'replacement_eligible_qty' => $replacementEligibleQty,
            'reserved_qty' => $reservedQty,
            'allocated_qty' => $allocatedQty,
            'remaining_qty' => $remainingQty,

            'ordered_units' => $orderedQty,
            'physical_shipped_units' => $physicalShippedQty,
            'physical_arrived_units' => $physicalArrivedQty,
            'accepted_units' => $acceptedQty,
            'ng_units' => $ngQty,
            'replacement_eligible_units' => $replacementEligibleQty,
            'reserved_units' => $reservedQty,
            'allocated_units' => $allocatedQty,
            'remaining_units' => $remainingQty,
            'ordered' => (float) $orderedQty,
            'physical_shipped' => (float) $physicalShippedQty,
            'physical_arrived' => (float) $physicalArrivedQty,
            'accepted' => (float) $acceptedQty,
            'ng' => (float) $ngQty,
            'replacement_eligible' => (float) $replacementEligibleQty,
            'reserved' => (float) $reservedQty,
            'allocated' => (float) $allocatedQty,
            'remaining' => (float) $remainingQty,

            'is_fully_allocated' => $remainingQty === 0,
            'is_fully_accepted' => $acceptedQty >= $orderedQty,

            'in_transit_qty' => $inTransitQty,
            'waiting_qc_qty' => $waitingQcQty,
            'unresolved_ng_qty' => $unresolvedNgQty,
        ];
    }

    /**
     * @return array{items: array<int, array<string, int|float|bool>>}
     */
    public function fulfillmentProjection(PurchaseOrder $po): array
    {
        $byItem = $po->shipmentItems()
            ->whereHas('shipment', fn ($shipmentQuery) => $shipmentQuery
                ->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
            ->with(['shipment', 'qcItems.inspection.materialClaims'])
            ->orderBy('id')
            ->get()
            ->groupBy('quotation_item_id');

        $items = [];
        foreach ($po->orderedLines() as $line) {
            $id = (int) $line->quotationItem->id;
            $items[$id] = $this->tallyFulfillment(
                $byItem->get($id, collect()),
                (int) ($line->qty ?? $line->quotationItem->fulfillment_quantity),
                (int) $po->id
            );
        }

        return ['items' => $items];
    }

    public function isFullyFulfilledAndInspected(PurchaseOrder $po, ?Shipment $currentOkShipment = null): bool
    {
        if ($po->shipmentItems()->doesntExist()) {
            $hasNg = QcInspection::where('po_id', $po->id)->where('status', 'ng')->exists();
            $hasOk = QcInspection::where('po_id', $po->id)->where('status', 'ok')->exists();

            return ! $hasNg && ($hasOk || (bool) $po->actual_arrival);
        }

        $quotationItems = ($po->awards()->exists()
            ? $po->awards()->with('quotationItem.prItem')->get()->pluck('quotationItem')->filter()
            : $po->allQuotationItems())
            ->filter(fn (QuotationItem $item) => $item->is_available !== false);

        if ($quotationItems->isEmpty()) {
            return true;
        }

        return $quotationItems->every(function (QuotationItem $quotationItem) use ($po) {
            $status = $this->itemFulfillmentStatus($po, $quotationItem->id);

            return $status['accepted_qty'] >= $status['ordered_qty'];
        });
    }

    public function hasArrivedShipmentsAwaitingQc(PurchaseOrder $po, ?int $excludeShipmentId = null): bool
    {
        $inspectedShipmentIds = QcInspection::where('po_id', $po->id)
            ->whereNotNull('shipment_id')
            ->pluck('shipment_id')
            ->all();

        $query = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->where('shipment_items.purchase_order_id', $po->id)
            ->where('shipments.status', Shipment::STATUS_ARRIVED)
            ->whereNull('shipments.deleted_at');

        if (! empty($inspectedShipmentIds)) {
            $query->whereNotIn('shipments.id', $inspectedShipmentIds);
        }

        if ($excludeShipmentId) {
            $query->where('shipments.id', '!=', $excludeShipmentId);
        }

        return $query->exists();
    }

    public function reconcileOperationalStatus(PurchaseOrder $po): string
    {
        if (in_array($po->status, ['cancelled', 'completed'], true)) {
            return $po->status;
        }

        $hasActiveClaim = $po->materialClaims()
            ->whereIn('status', ['pending', 'responded', 'escalated'])
            ->exists();

        $hasUnresolvedNg = $po->qcInspections()
            ->where('status', 'ng')
            ->where(function ($query) {
                $query->whereDoesntHave('materialClaims', fn ($claimQuery) => $claimQuery
                    ->where('status', 'resolved'))
                    ->orWhereHas('materialClaims', fn ($claimQuery) => $claimQuery
                        ->whereIn('status', ['pending', 'responded', 'escalated']));
            })
            ->exists();

        if ($hasActiveClaim || $hasUnresolvedNg) {
            $target = 'claim_needed';
        } elseif ($this->hasArrivedShipmentsAwaitingQc($po)) {
            $target = 'waiting_qc';
        } elseif ($this->isFullyFulfilledAndInspected($po)) {
            $target = 'completed';
        } else {
            $target = $po->status === 'overdue' ? 'overdue' : 'active';
        }

        if ($po->status !== $target) {
            app(PurchaseOrderStateEngine::class)->transition(
                model: $po,
                target: PurchaseOrderStatus::tryFromWithLegacy($target),
                reason: 'Operational status reconciliation'
            );
        }

        return $target;
    }
}
