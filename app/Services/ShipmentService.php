<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Support\NotificationCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ShipmentService
{
    public function __construct(
        protected NotificationService $notifications
    ) {}

    /**
     * Calculate remaining quantity for a PO item.
     *
     * @return array{
     *     ordered: float,
     *     allocated: float,
     *     remaining: float,
     *     is_fully_allocated: bool
     * }
     */
    public function getItemDeliveryStatus(int $poId, int $quotationItemId): array
    {
        return PurchaseOrder::findOrFail($poId)->itemFulfillmentStatus($quotationItemId);
    }

    /**
     * Create a new shipment in draft status.
     *
     * @param array{
     *     shipment_date?: string|null,
     *     estimated_arrival_date?: string|null,
     *     notes?: string|null,
     *     items?: array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_quantity: float, notes?: string|null}>
     * } $data
     */
    public function createDraft(User $supplier, array $data = []): Shipment
    {
        return DB::transaction(function () use ($supplier, $data) {
            $shipment = Shipment::create([
                'shipment_number' => Shipment::generateShipmentNumber(),
                'supplier_id' => $supplier->id,
                'status' => Shipment::STATUS_DRAFT,
                'shipment_date' => $data['shipment_date'] ?? now()->toDateString(),
                'estimated_arrival_date' => $data['estimated_arrival_date'] ?? now()->addDays(14)->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $supplier->id,
            ]);

            // Create default 4 shipment documents
            foreach (ShipmentDocument::DOC_TYPES as $docType) {
                ShipmentDocument::create([
                    'shipment_id' => $shipment->id,
                    'doc_type' => $docType,
                    'status' => ShipmentDocument::STATUS_PENDING,
                ]);
            }

            if (! empty($data['items'])) {
                $this->syncDraftItems($shipment, $data['items']);
            }

            return $shipment->fresh(['items', 'documents']);
        });
    }

    /**
     * Sync items on a draft shipment.
     *
     * @param  array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_quantity: float, notes?: string|null}>  $items
     */
    public function syncDraftItems(Shipment $shipment, array $items): void
    {
        if ($shipment->status !== Shipment::STATUS_DRAFT) {
            throw new InvalidArgumentException("Cannot modify items of a shipment that is already {$shipment->status}.");
        }

        // Defense in depth: reject duplicate allocations for the same PO item within one shipment
        $duplicates = collect($items)
            ->groupBy(fn ($i) => (int) ($i['purchase_order_id'] ?? 0).':'.(int) ($i['quotation_item_id'] ?? 0))
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw new InvalidArgumentException('Duplicate item entries detected for the same Purchase Order item in this shipment.');
        }

        $poIds = collect($items)->pluck('purchase_order_id')->unique()->sort()->values()->all();
        $pos = PurchaseOrder::with(['awards'])->whereIn('id', $poIds)->get()->keyBy('id');

        // Validate supplier ownership on every PO
        foreach ($pos as $po) {
            if ((int) $po->supplier_id !== (int) $shipment->supplier_id) {
                throw new InvalidArgumentException("Purchase Order #{$po->po_number} does not belong to supplier #{$shipment->supplier_id}.");
            }
        }

        $shipment->items()->delete();

        foreach ($items as $item) {
            $shippedUnits = PurchaseOrder::quantityToUnits($item['shipped_quantity']);
            if ($shippedUnits <= 0) {
                throw new InvalidArgumentException('Shipped quantity must be greater than zero.');
            }
            $shippedQty = PurchaseOrder::quantityUnitsToDecimal($shippedUnits);

            $poId = (int) $item['purchase_order_id'];
            $qItemId = (int) $item['quotation_item_id'];
            $po = $pos->get($poId);

            if (! $po) {
                throw new InvalidArgumentException("Purchase Order #{$poId} not found.");
            }

            // Cross-validate commercial source consistency
            $award = null;
            if ($po->awards()->exists()) {
                $award = PrItemAward::where('purchase_order_id', $poId)
                    ->where('quotation_item_id', $qItemId)
                    ->first();

                if (! $award) {
                    throw new InvalidArgumentException("Quotation item #{$qItemId} does not belong to Purchase Order #{$po->po_number}.");
                }

                if ((int) $award->supplier_id !== (int) $shipment->supplier_id) {
                    throw new InvalidArgumentException("Item does not belong to supplier #{$shipment->supplier_id}.");
                }
            } else {
                $isLegacyItem = DB::table('po_quotations')
                    ->join('quotation_items', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
                    ->where('po_quotations.po_id', $poId)
                    ->where('quotation_items.id', $qItemId)
                    ->exists();

                if (! $isLegacyItem) {
                    throw new InvalidArgumentException("Quotation item #{$qItemId} does not belong to Purchase Order #{$po->po_number}.");
                }
            }

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $poId,
                'quotation_item_id' => $qItemId,
                'pr_item_award_id' => $award?->id,
                'shipped_quantity' => $shippedQty,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    /**
     * Update supplier-owned draft shipment metadata and line items atomically.
     */
    public function updateDraft(int|Shipment $shipment, User $supplier, array $data): Shipment
    {
        $shipmentId = $shipment instanceof Shipment ? $shipment->id : $shipment;

        return DB::transaction(function () use ($shipmentId, $supplier, $data) {
            $lockedShipment = Shipment::whereKey($shipmentId)->lockForUpdate()->firstOrFail();

            if ((int) $lockedShipment->supplier_id !== (int) $supplier->id) {
                throw new InvalidArgumentException('This shipment does not belong to the authenticated supplier.');
            }

            if ($lockedShipment->status !== Shipment::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only draft shipments can be edited.');
            }

            $lockedShipment->update([
                'shipment_date' => $data['shipment_date'],
                'estimated_arrival_date' => $data['estimated_arrival_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $this->syncDraftItems($lockedShipment, $data['items']);

            return $lockedShipment->fresh(['items', 'documents']);
        });
    }

    /**
     * Submit and finalize a shipment with deterministic row locking and concurrency checks.
     *
     * @param array{
     *     shipment_date?: string|null,
     *     estimated_arrival_date?: string|null,
     *     notes?: string|null,
     *     items?: array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_quantity: float, notes?: string|null}>
     * } $data
     *
     * @throws InvalidArgumentException
     */
    public function submitShipment(int|Shipment $shipment, array $data = []): Shipment
    {
        $shipmentId = $shipment instanceof Shipment ? $shipment->id : $shipment;

        return DB::transaction(function () use ($shipmentId, $data) {
            /** @var Shipment|null $lockedShipment */
            $lockedShipment = Shipment::where('id', $shipmentId)->lockForUpdate()->first();
            if (! $lockedShipment) {
                throw new InvalidArgumentException("Shipment #{$shipmentId} not found.");
            }

            if ($lockedShipment->status !== Shipment::STATUS_DRAFT) {
                throw new InvalidArgumentException("Shipment #{$lockedShipment->shipment_number} cannot be submitted because its status is {$lockedShipment->status}.");
            }

            $itemsData = $data['items'] ?? $lockedShipment->items->map(fn ($i) => [
                'purchase_order_id' => $i->purchase_order_id,
                'quotation_item_id' => $i->quotation_item_id,
                'shipped_quantity' => (float) $i->shipped_quantity,
                'notes' => $i->notes,
            ])->all();

            if (empty($itemsData)) {
                throw new InvalidArgumentException('A shipment must contain at least one item allocation.');
            }

            // Defense in depth: disallow duplicate item entries within the same shipment request
            $duplicates = collect($itemsData)
                ->groupBy(fn ($i) => (int) ($i['purchase_order_id'] ?? 0).':'.(int) ($i['quotation_item_id'] ?? 0))
                ->filter(fn ($group) => $group->count() > 1);

            if ($duplicates->isNotEmpty()) {
                throw new InvalidArgumentException('Duplicate item entries detected for the same Purchase Order item in this shipment.');
            }

            $itemsData = collect($itemsData)
                ->sortBy(fn ($item) => sprintf(
                    '%020d:%020d',
                    (int) ($item['purchase_order_id'] ?? 0),
                    (int) ($item['quotation_item_id'] ?? 0)
                ))
                ->values()
                ->all();

            // Lock all affected POs deterministically
            $poIds = collect($itemsData)->pluck('purchase_order_id')->unique()->sort()->values()->all();
            $lockedPos = PurchaseOrder::with(['awards'])->whereIn('id', $poIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedPos->count() !== count($poIds)) {
                throw new InvalidArgumentException('One or more referenced Purchase Orders could not be found.');
            }

            // Invariant 5: ONE SHIPMENT -> EXACTLY ONE SUPPLIER
            foreach ($lockedPos as $po) {
                if ((int) $po->supplier_id !== (int) $lockedShipment->supplier_id) {
                    throw new InvalidArgumentException("Purchase Order #{$po->po_number} belongs to another supplier. Multi-supplier shipments are forbidden.");
                }

                if (! in_array($po->status, ['active', 'overdue', 'waiting_qc'])) {
                    throw new InvalidArgumentException("Purchase Order #{$po->po_number} is not eligible for delivery (status: {$po->status}).");
                }
            }

            // Lock all quotation items deterministically
            $qItemIds = collect($itemsData)->pluck('quotation_item_id')->unique()->sort()->values()->all();
            $lockedQItems = QuotationItem::with('prItem')
                ->whereIn('id', $qItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Cross-validate source consistency and Invariant 7: SUM(active shipment quantities) <= ordered quantity
            foreach ($itemsData as $itemAlloc) {
                $poId = (int) $itemAlloc['purchase_order_id'];
                $qItemId = (int) $itemAlloc['quotation_item_id'];
                $shippedUnits = PurchaseOrder::quantityToUnits($itemAlloc['shipped_quantity']);

                if ($shippedUnits <= 0) {
                    throw new InvalidArgumentException('Shipped quantity must be greater than zero.');
                }
                $shippedQty = PurchaseOrder::quantityUnitsToDecimal($shippedUnits);

                $po = $lockedPos->get($poId);
                if (! $po) {
                    throw new InvalidArgumentException("Purchase Order #{$poId} not found.");
                }

                // Cross-validate quotation item belongs to this PO (commercial consistency)
                $award = null;
                if ($po->awards()->exists()) {
                    $award = PrItemAward::where('purchase_order_id', $poId)
                        ->where('quotation_item_id', $qItemId)
                        ->first();

                    if (! $award) {
                        throw new InvalidArgumentException("Quotation item #{$qItemId} does not belong to Purchase Order #{$po->po_number}.");
                    }

                    if ((int) $award->supplier_id !== (int) $lockedShipment->supplier_id) {
                        throw new InvalidArgumentException("Item does not belong to supplier #{$lockedShipment->supplier_id}.");
                    }
                } else {
                    $isLegacyItem = DB::table('po_quotations')
                        ->join('quotation_items', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
                        ->where('po_quotations.po_id', $poId)
                        ->where('quotation_items.id', $qItemId)
                        ->exists();

                    if (! $isLegacyItem) {
                        throw new InvalidArgumentException("Quotation item #{$qItemId} does not belong to Purchase Order #{$po->po_number}.");
                    }
                }

                $qItem = $lockedQItems->get($qItemId);
                if (! $qItem) {
                    throw new InvalidArgumentException("Quotation item #{$qItemId} not found.");
                }

                ShipmentItem::query()
                    ->where('purchase_order_id', $poId)
                    ->where('quotation_item_id', $qItemId)
                    ->where('shipment_id', '!=', $lockedShipment->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $fulfillment = $po->itemFulfillmentStatus($qItemId, $lockedShipment->id);
                $remainingUnits = $fulfillment['remaining_units'];
                $remainingQty = PurchaseOrder::quantityUnitsToDecimal($remainingUnits);

                if ($shippedUnits > $remainingUnits) {
                    throw new InvalidArgumentException(
                        "Shipped quantity ({$shippedQty} kg) exceeds remaining ordered balance ({$remainingQty} kg) for item '{$qItem->prItem?->material_name}'."
                    );
                }
            }

            // Persist the verified items
            $lockedShipment->items()->delete();
            foreach ($itemsData as $itemAlloc) {
                $poId = (int) $itemAlloc['purchase_order_id'];
                $qItemId = (int) $itemAlloc['quotation_item_id'];
                $award = PrItemAward::where('purchase_order_id', $poId)
                    ->where('quotation_item_id', $qItemId)
                    ->first();

                ShipmentItem::create([
                    'shipment_id' => $lockedShipment->id,
                    'purchase_order_id' => $poId,
                    'quotation_item_id' => $qItemId,
                    'pr_item_award_id' => $award?->id,
                    'shipped_quantity' => PurchaseOrder::quantityUnitsToDecimal(
                        PurchaseOrder::quantityToUnits($itemAlloc['shipped_quantity'])
                    ),
                    'notes' => $itemAlloc['notes'] ?? null,
                ]);
            }

            // Update shipment to SUBMITTED
            $lockedShipment->update([
                'status' => Shipment::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'shipment_date' => $data['shipment_date'] ?? $lockedShipment->shipment_date ?? now()->toDateString(),
                'estimated_arrival_date' => $data['estimated_arrival_date'] ?? $lockedShipment->estimated_arrival_date ?? now()->addDays(14)->toDateString(),
                'notes' => $data['notes'] ?? $lockedShipment->notes,
            ]);

            // Notify purchasing team
            $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
            $poNumbers = $lockedPos->pluck('po_number')->implode(', ');
            $this->notifications->send(
                $purchasingUsers,
                'shipment.submitted',
                "shipment.submitted:{$lockedShipment->id}",
                'New Shipment Submitted',
                "Supplier {$lockedShipment->supplier->name} submitted shipment {$lockedShipment->shipment_number} for PO(s): {$poNumbers}.",
                route('purchasing.purchase-orders.show', $lockedPos->first(), absolute: false),
                'truck text-primary',
                [
                    'category' => NotificationCategory::OTHER,
                    'shipment_id' => $lockedShipment->id,
                    'shipment_number' => $lockedShipment->shipment_number,
                ]
            );

            return $lockedShipment->fresh(['items', 'documents', 'supplier']);
        });
    }

    /**
     * Cancel an active or draft shipment and release its reserved allocation.
     */
    public function cancelShipment(int|Shipment $shipment, User $user): Shipment
    {
        $shipmentId = $shipment instanceof Shipment ? $shipment->id : $shipment;

        return DB::transaction(function () use ($shipmentId) {
            /** @var Shipment|null $lockedShipment */
            $lockedShipment = Shipment::where('id', $shipmentId)->lockForUpdate()->first();
            if (! $lockedShipment) {
                throw new InvalidArgumentException("Shipment #{$shipmentId} not found.");
            }

            if ($lockedShipment->status === Shipment::STATUS_ARRIVED) {
                throw new InvalidArgumentException('Cannot cancel a shipment that has already arrived.');
            }

            if ($lockedShipment->status === Shipment::STATUS_CANCELLED) {
                return $lockedShipment;
            }

            $lockedShipment->update([
                'status' => Shipment::STATUS_CANCELLED,
            ]);

            return $lockedShipment->fresh(['items']);
        });
    }

    /**
     * Confirm physical arrival for a shipment.
     *
     * @param  array{actual_arrival_date?: string|null}  $options
     */
    public function confirmArrival(
        int|Shipment $shipment,
        User $purchasingUser,
        array $options = []
    ): Shipment {
        $shipmentId = $shipment instanceof Shipment ? $shipment->id : $shipment;

        return DB::transaction(function () use ($shipmentId, $options) {
            /** @var Shipment|null $lockedShipment */
            $lockedShipment = Shipment::with(['items.purchaseOrder', 'supplier'])->where('id', $shipmentId)->lockForUpdate()->first();
            if (! $lockedShipment) {
                throw new InvalidArgumentException("Shipment #{$shipmentId} not found.");
            }

            if ($lockedShipment->status !== Shipment::STATUS_SUBMITTED) {
                throw new InvalidArgumentException("Arrival can only be confirmed for submitted shipments (current status: {$lockedShipment->status}).");
            }

            $arrivalDate = $options['actual_arrival_date'] ?? now()->toDateString();

            $lockedShipment->update([
                'status' => Shipment::STATUS_ARRIVED,
                'actual_arrival_date' => $arrivalDate,
            ]);

            // Update associated POs under deterministic locks.
            $poIds = $lockedShipment->items
                ->pluck('purchase_order_id')
                ->unique()
                ->sort()
                ->values()
                ->all();
            $pos = PurchaseOrder::query()
                ->whereIn('id', $poIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($pos as $po) {
                $po->update([
                    'actual_arrival' => $arrivalDate,
                ]);
                $po->reconcileOperationalStatus();

                // Notify QC team for inspection
                $qcUsers = User::where('role', 'qc')->where('is_active', true)->get();
                $this->notifications->send(
                    $qcUsers,
                    'po.material_arrived',
                    "shipment.arrived:{$lockedShipment->id}:po:{$po->id}",
                    'Shipment Material Arrived - Ready for QC',
                    "Shipment {$lockedShipment->shipment_number} for PO {$po->po_number} has arrived. Please perform QC inspection.",
                    route('qc.inspections.create', $po, absolute: false),
                    'package text-warning',
                    [
                        'category' => NotificationCategory::OTHER,
                        'po_id' => $po->id,
                        'po_number' => $po->po_number,
                        'shipment_id' => $lockedShipment->id,
                    ]
                );
            }

            return $lockedShipment->fresh(['items', 'documents']);
        });
    }

    /**
     * Upload or update a shipment document attachment.
     */
    public function uploadDocument(
        ShipmentDocument $document,
        UploadedFile $file,
        User $user,
        ?string $documentNumber = null
    ): Attachment {
        $document->loadMissing('shipment');
        if (! $document->shipment || (int) $document->shipment->supplier_id !== (int) $user->id) {
            throw new InvalidArgumentException('This shipment document does not belong to the authenticated supplier.');
        }

        $path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName();

        $stream = fopen($file->getPathname(), 'r');
        if (! is_resource($stream)) {
            throw new \RuntimeException('The uploaded document could not be read.');
        }

        $disk = Storage::disk('private');
        try {
            $stored = $disk->put($path, $stream);
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            $disk->delete($path);
            throw new \RuntimeException('The shipment document could not be stored on the private disk.');
        }

        try {
            return DB::transaction(function () use ($document, $documentNumber, $file, $path, $user) {
                $lockedDocument = ShipmentDocument::query()
                    ->with('shipment')
                    ->whereKey($document->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedDocument->shipment?->supplier_id !== (int) $user->id) {
                    throw new InvalidArgumentException('This shipment document does not belong to the authenticated supplier.');
                }

                $attachment = $lockedDocument->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $file->getMimeType(),
                    'uploaded_by' => $user->id,
                ]);

                $lockedDocument->update([
                    'document_number' => $documentNumber ?? $lockedDocument->document_number,
                    'status' => ShipmentDocument::STATUS_RECEIVED,
                ]);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }
}
