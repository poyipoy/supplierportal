<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\PoDocument;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Support\BusinessTime;
use App\Support\NotificationCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ShipmentService
{
    private const LEGACY_ONLY_ARRIVAL_MESSAGE = 'shipments.validation.legacy_only_arrival';

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
     *     items?: array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_qty: int, actual_weight_kg: numeric-string|float, notes?: string|null}>
     * } $data
     */
    public function createDraft(User $supplier, array $data = []): Shipment
    {
        return DB::transaction(function () use ($supplier, $data) {
            $shipment = Shipment::create([
                'shipment_number' => Shipment::generateShipmentNumber(),
                'supplier_id' => $supplier->id,
                'status' => Shipment::STATUS_DRAFT,
                'shipment_date' => $data['shipment_date'] ?? BusinessTime::today()->toDateString(),
                'estimated_arrival_date' => $data['estimated_arrival_date'] ?? BusinessTime::today()->addDays(14)->toDateString(),
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
     * @param  array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_qty: int, actual_weight_kg: numeric-string|float, notes?: string|null}>  $items
     */
    public function syncDraftItems(Shipment $shipment, array $items): void
    {
        DB::transaction(function () use ($shipment, $items): void {
            $lockedShipment = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->syncDraftItemsWithinTransaction($lockedShipment, $items);
        });
    }

    /**
     * Apply draft item changes while the caller's transaction owns the
     * shipment row and every affected PurchaseOrder row.
     *
     * @param  array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_qty: int, actual_weight_kg: numeric-string|float, notes?: string|null}>  $items
     */
    private function syncDraftItemsWithinTransaction(Shipment $shipment, array $items): void
    {
        if ($shipment->status !== Shipment::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('shipments.guard_copy.modify_status', ['status' => $shipment->status]));
        }

        // Defense in depth: reject duplicate allocations for the same PO item within one shipment
        $duplicates = collect($items)
            ->groupBy(fn ($i) => (int) ($i['purchase_order_id'] ?? 0).':'.(int) ($i['quotation_item_id'] ?? 0))
            ->filter(fn ($group) => $group->count() > 1);

        if ($duplicates->isNotEmpty()) {
            throw new InvalidArgumentException(__('shipments.copy.duplicate_item_entries_detected_for_the_same_purchase_order_item_in_this_shipment'));
        }

        $existingPoIds = $shipment->items()->pluck('purchase_order_id');
        $poIds = collect($items)
            ->pluck('purchase_order_id')
            ->merge($existingPoIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $pos = PurchaseOrder::with(['awards'])
            ->whereIn('id', $poIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($pos->count() !== count($poIds)) {
            throw new InvalidArgumentException(__('shipments.copy.one_or_more_referenced_purchase_orders_could_not_be_found'));
        }

        // Validate supplier ownership on every PO
        foreach ($pos as $po) {
            if ((int) $po->supplier_id !== (int) $shipment->supplier_id) {
                throw new InvalidArgumentException(__('shipments.guard_copy.po_supplier', ['po' => $po->po_number, 'supplier' => $shipment->supplier_id]));
            }

            if ($po->hasLegacyOnlyArrivalState()) {
                throw new InvalidArgumentException(__(self::LEGACY_ONLY_ARRIVAL_MESSAGE));
            }
        }

        $validatedItems = [];
        foreach ($items as $item) {
            $rawQty = $item['shipped_qty'] ?? $item['shipped_quantity'] ?? null;
            if ($rawQty === null || $rawQty === '') {
                throw new InvalidArgumentException(__('shipments.copy.shipped_quantity_must_be_greater_than_zero'));
            }

            if (! is_numeric($rawQty) || (float) $rawQty != (int) $rawQty || (int) $rawQty <= 0) {
                throw new InvalidArgumentException(__('shipments.copy.shipped_quantity_must_be_a_positive_integer'));
            }
            $shippedQty = (int) $rawQty;

            // Weight is a distinct physical measurement, never derived from the
            // piece count. `shipped_quantity` is a legacy *quantity* alias only.
            $rawWeight = $item['actual_weight_kg'] ?? null;
            if ($rawWeight === null || $rawWeight === '' || ! is_numeric($rawWeight) || (float) $rawWeight <= 0) {
                throw new InvalidArgumentException(__('shipments.copy.actual_weight_kg_must_be_supplied_explicitly_and_be_greater_than_zero'));
            }
            $actualWeightKg = round((float) $rawWeight, 4);

            $poId = (int) $item['purchase_order_id'];
            $qItemId = (int) $item['quotation_item_id'];
            $po = $pos->get($poId);

            if (! $po) {
                throw new InvalidArgumentException(__('shipments.errors.po_not_found', ['id' => $poId]));
            }

            // Cross-validate commercial source consistency
            $award = null;
            if ($po->awards()->exists()) {
                $award = PrItemAward::where('purchase_order_id', $poId)
                    ->where('quotation_item_id', $qItemId)
                    ->first();

                if (! $award) {
                    throw new InvalidArgumentException(__('shipments.guard_copy.item_po', ['item' => $qItemId, 'po' => $po->po_number]));
                }

                if ((int) $award->supplier_id !== (int) $shipment->supplier_id) {
                    throw new InvalidArgumentException(__('shipments.guard_copy.item_supplier', ['supplier' => $shipment->supplier_id]));
                }
            } else {
                $isLegacyItem = DB::table('po_quotations')
                    ->join('quotation_items', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
                    ->where('po_quotations.po_id', $poId)
                    ->where('quotation_items.id', $qItemId)
                    ->exists();

                if (! $isLegacyItem) {
                    throw new InvalidArgumentException(__('shipments.guard_copy.item_po', ['item' => $qItemId, 'po' => $po->po_number]));
                }
            }

            $validatedItems[] = [
                'item' => $item,
                'po_id' => $poId,
                'award_id' => $award?->id,
                'shipped_qty' => $shippedQty,
                'actual_weight_kg' => $actualWeightKg,
            ];
        }

        $shipment->items()->delete();

        foreach ($validatedItems as $validatedItem) {
            $item = $validatedItem['item'];

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $validatedItem['po_id'],
                'quotation_item_id' => (int) $item['quotation_item_id'],
                'pr_item_award_id' => $validatedItem['award_id'],
                'shipped_qty' => $validatedItem['shipped_qty'],
                'actual_weight_kg' => $validatedItem['actual_weight_kg'],
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
                throw new InvalidArgumentException(__('shipments.copy.this_shipment_does_not_belong_to_the_authenticated_supplier'));
            }

            if ($lockedShipment->status !== Shipment::STATUS_DRAFT) {
                throw new InvalidArgumentException(__('shipments.copy.only_draft_shipments_can_be_edited'));
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
     *     items?: array<int, array{purchase_order_id: int, quotation_item_id: int, shipped_qty: int, actual_weight_kg: numeric-string|float, notes?: string|null}>
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
                throw new InvalidArgumentException(__('shipments.errors.not_found', ['id' => $shipmentId]));
            }

            if ($lockedShipment->status !== Shipment::STATUS_DRAFT) {
                throw new InvalidArgumentException(__('shipments.guard_copy.submit_status', ['number' => $lockedShipment->shipment_number, 'status' => $lockedShipment->status]));
            }

            // A locking read does not establish a REPEATABLE READ snapshot.
            // The first ordinary read must follow acquisition of every PO lock.
            $itemsData = $data['items'] ?? $lockedShipment->items()
                ->orderBy('id')->lockForUpdate()->get()->map(fn ($item) => [
                    'purchase_order_id' => $item->purchase_order_id,
                    'quotation_item_id' => $item->quotation_item_id,
                    'shipped_qty' => $item->shipped_qty,
                    'actual_weight_kg' => $item->actual_weight_kg,
                    'notes' => $item->notes,
                ])->all();

            if (empty($itemsData)) {
                throw new InvalidArgumentException(__('shipments.copy.a_shipment_must_contain_at_least_one_item_allocation'));
            }

            // Defense in depth: disallow duplicate item entries within the same shipment request
            $duplicates = collect($itemsData)
                ->groupBy(fn ($i) => (int) ($i['purchase_order_id'] ?? 0).':'.(int) ($i['quotation_item_id'] ?? 0))
                ->filter(fn ($group) => $group->count() > 1);

            if ($duplicates->isNotEmpty()) {
                throw new InvalidArgumentException(__('shipments.copy.duplicate_item_entries_detected_for_the_same_purchase_order_item_in_this_shipment'));
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
                throw new InvalidArgumentException(__('shipments.copy.one_or_more_referenced_purchase_orders_could_not_be_found'));
            }

            // Invariant 5: ONE SHIPMENT -> EXACTLY ONE SUPPLIER
            foreach ($lockedPos as $po) {
                if ((int) $po->supplier_id !== (int) $lockedShipment->supplier_id) {
                    throw new InvalidArgumentException(__('shipments.guard_copy.multiple_suppliers', ['po' => $po->po_number]));
                }

                if (! in_array($po->status, ['active', 'overdue', 'waiting_qc'])) {
                    throw new InvalidArgumentException(__('shipments.guard_copy.po_ineligible', ['po' => $po->po_number, 'status' => $po->status]));
                }

                if ($po->hasLegacyOnlyArrivalState()) {
                    throw new InvalidArgumentException(__(self::LEGACY_ONLY_ARRIVAL_MESSAGE));
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

            // Values proven by the validation pass below, consumed verbatim by the
            // persistence pass. Re-deriving them a second time is how the
            // pcs -> kg fallback survived the first remediation.
            $verifiedItems = [];

            // Cross-validate source consistency and Invariant 7: SUM(active shipment quantities) <= ordered quantity
            foreach ($itemsData as $itemIndex => $itemAlloc) {
                $poId = (int) $itemAlloc['purchase_order_id'];
                $qItemId = (int) $itemAlloc['quotation_item_id'];

                $rawQty = $itemAlloc['shipped_qty'] ?? $itemAlloc['shipped_quantity'] ?? null;
                if ($rawQty === null || $rawQty === '') {
                    throw new InvalidArgumentException(__('shipments.copy.shipped_quantity_must_be_greater_than_zero'));
                }
                if (! is_numeric($rawQty) || (float) $rawQty != (int) $rawQty || (int) $rawQty <= 0) {
                    throw new InvalidArgumentException(__('shipments.copy.shipped_quantity_must_be_a_positive_integer'));
                }
                $shippedQty = (int) $rawQty;

                // Weight is a distinct physical measurement, never derived from the
                // piece count. `shipped_quantity` is a legacy *quantity* alias only.
                $rawWeight = $itemAlloc['actual_weight_kg'] ?? null;
                if ($rawWeight === null || $rawWeight === '' || ! is_numeric($rawWeight) || (float) $rawWeight <= 0) {
                    throw new InvalidArgumentException(__('shipments.copy.actual_weight_kg_must_be_supplied_explicitly_and_be_greater_than_zero'));
                }
                $actualWeightKg = round((float) $rawWeight, 4);

                $po = $lockedPos->get($poId);
                if (! $po) {
                    throw new InvalidArgumentException(__('shipments.errors.po_not_found', ['id' => $poId]));
                }

                // Cross-validate quotation item belongs to this PO (commercial consistency)
                $award = null;
                if ($po->awards()->exists()) {
                    $award = PrItemAward::where('purchase_order_id', $poId)
                        ->where('quotation_item_id', $qItemId)
                        ->first();

                    if (! $award) {
                        throw new InvalidArgumentException(__('shipments.guard_copy.item_po', ['item' => $qItemId, 'po' => $po->po_number]));
                    }

                    if ((int) $award->supplier_id !== (int) $lockedShipment->supplier_id) {
                        throw new InvalidArgumentException(__('shipments.guard_copy.item_supplier', ['supplier' => $lockedShipment->supplier_id]));
                    }
                } else {
                    $isLegacyItem = DB::table('po_quotations')
                        ->join('quotation_items', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
                        ->where('po_quotations.po_id', $poId)
                        ->where('quotation_items.id', $qItemId)
                        ->exists();

                    if (! $isLegacyItem) {
                        throw new InvalidArgumentException(__('shipments.guard_copy.item_po', ['item' => $qItemId, 'po' => $po->po_number]));
                    }
                }

                $qItem = $lockedQItems->get($qItemId);
                if (! $qItem) {
                    throw new InvalidArgumentException(__('shipments.guard_copy.item_missing', ['item' => $qItemId]));
                }

                // PO serialization precedes the consistent-read snapshot used by
                // fulfillment, including QC and claims. Do not lock another
                // draft's lines here: its submitter may already be waiting on PO.
                $fulfillment = $po->itemFulfillmentStatus($qItemId, $lockedShipment->id);
                $remainingQty = (int) $fulfillment['remaining_qty'];

                if ($shippedQty > $remainingQty) {
                    throw new InvalidArgumentException(
                        __('shipments.guard_copy.quantity_remaining', ['qty' => $shippedQty, 'remaining' => $remainingQty, 'material' => $qItem->prItem?->material_name])
                    );
                }

                $verifiedItems[$itemIndex] = [
                    'purchase_order_id' => $poId,
                    'quotation_item_id' => $qItemId,
                    'pr_item_award_id' => $award?->id,
                    'shipped_qty' => $shippedQty,
                    'actual_weight_kg' => $actualWeightKg,
                    'notes' => $itemAlloc['notes'] ?? null,
                ];
            }

            // Persist the verified items
            $lockedShipment->items()->delete();
            foreach ($verifiedItems as $verified) {
                ShipmentItem::create([
                    'shipment_id' => $lockedShipment->id,
                    'purchase_order_id' => $verified['purchase_order_id'],
                    'quotation_item_id' => $verified['quotation_item_id'],
                    'pr_item_award_id' => $verified['pr_item_award_id'],
                    'shipped_qty' => $verified['shipped_qty'],
                    'actual_weight_kg' => $verified['actual_weight_kg'],
                    'notes' => $verified['notes'],
                ]);
            }

            // Update shipment to SUBMITTED
            $lockedShipment->update([
                'status' => Shipment::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'shipment_date' => $data['shipment_date'] ?? $lockedShipment->shipment_date ?? BusinessTime::today()->toDateString(),
                'estimated_arrival_date' => $data['estimated_arrival_date'] ?? $lockedShipment->estimated_arrival_date ?? BusinessTime::today()->addDays(14)->toDateString(),
                'notes' => $data['notes'] ?? $lockedShipment->notes,
            ]);

            // Notify purchasing team
            $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
            $poNumbers = $lockedPos->pluck('po_number')->implode(', ');
            $this->notifications->send(
                $purchasingUsers,
                'shipment.submitted',
                "shipment.submitted:{$lockedShipment->id}",
                'shipments.copy.new_shipment_submitted',
                'shipments.notify.submitted_body',
                route('purchasing.purchase-orders.show', $lockedPos->first(), absolute: false),
                'truck text-primary',
                [
                    'category' => NotificationCategory::OTHER,
                    'shipment_id' => $lockedShipment->id,
                    'shipment_number' => $lockedShipment->shipment_number,
                ],
                ['supplier' => $lockedShipment->supplier->name, 'shipment' => $lockedShipment->shipment_number, 'po' => $poNumbers],
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
                throw new InvalidArgumentException(__('shipments.errors.not_found', ['id' => $shipmentId]));
            }

            if ($lockedShipment->status === Shipment::STATUS_ARRIVED) {
                throw new InvalidArgumentException(__('shipments.copy.cannot_cancel_a_shipment_that_has_already_arrived'));
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
                throw new InvalidArgumentException(__('shipments.errors.not_found', ['id' => $shipmentId]));
            }

            if ($lockedShipment->status !== Shipment::STATUS_SUBMITTED) {
                throw new InvalidArgumentException(__('shipments.guard_copy.arrival_status', ['status' => $lockedShipment->status]));
            }

            $arrivalDate = $options['actual_arrival_date'] ?? BusinessTime::today()->toDateString();

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
                    'shipments.notify.arrived_title',
                    'shipments.notify.arrived_body',
                    route('qc.inspections.create', $po, absolute: false),
                    'package text-warning',
                    [
                        'category' => NotificationCategory::OTHER,
                        'po_id' => $po->id,
                        'po_number' => $po->po_number,
                        'shipment_id' => $lockedShipment->id,
                    ],
                    ['po' => $po->po_number, 'shipment' => $lockedShipment->shipment_number],
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
            throw new InvalidArgumentException(__('shipments.copy.this_shipment_document_does_not_belong_to_the_authenticated_supplier'));
        }

        $path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName(); // biz-time:ignore storage path

        $disk = Storage::disk('private');
        $stored = app(FileInspectionService::class)->storeUpload($file, 'shipment', $path, 'file');

        try {
            return DB::transaction(function () use ($document, $documentNumber, $stored, $user) {
                $lockedDocument = ShipmentDocument::query()
                    ->with('shipment')
                    ->whereKey($document->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedDocument->shipment?->supplier_id !== (int) $user->id) {
                    throw new InvalidArgumentException(__('shipments.copy.this_shipment_document_does_not_belong_to_the_authenticated_supplier'));
                }

                $attachment = $lockedDocument->attachments()->create([
                    'file_path' => $stored['file_path'],
                    'file_name' => $stored['file_name'],
                    'file_type' => $stored['file_type'],
                    'file_inspection_id' => $stored['file_inspection_id'],
                    'uploaded_by' => $user->id,
                ]);

                $lockedDocument->update([
                    'document_number' => $documentNumber ?? $lockedDocument->document_number,
                    'status' => ShipmentDocument::STATUS_RECEIVED,
                ]);

                PoDocument::syncFromShipmentDocument($lockedDocument);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }
}
