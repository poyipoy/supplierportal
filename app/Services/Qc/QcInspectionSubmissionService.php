<?php

namespace App\Services\Qc;

use App\Http\Requests\Qc\StoreQcInspectionRequest;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QcInspectionSubmissionService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly FileInspectionService $fileInspection,
    ) {}

    /**
     * @param  Collection<int, PrItem>  $poPrItems
     * @param  array<string, mixed>  $validated
     */
    public function submit(Request $request, int|string $poId, Collection $poPrItems, array $validated): QcInspection
    {
        $shipment = null;
        if ($request->filled('shipment_id')) {
            $rawShipmentId = $request->input('shipment_id');
            $shipment = (new Shipment)->resolveRouteBinding($rawShipmentId);
        }
        $shipmentId = $shipment?->id;
        $shipment = null;
        $stagedAttachments = [];

        try {
            DB::beginTransaction();

            if ($shipmentId) {
                $shipment = Shipment::query()
                    ->whereKey($shipmentId)
                    ->lockForUpdate()
                    ->first();

                if (! $shipment) {
                    throw new \RuntimeException(__('qc.copy.the_specified_shipment_could_not_be_found'));
                }
            }

            $po = PurchaseOrder::whereKey($poId)
                ->lockForUpdate()
                ->firstOrFail();
            $po->load(['supplier', 'quotations.items.prItem']);

            if (! in_array($po->status, ['waiting_qc', 'claim_needed'], true)) {
                throw new \RuntimeException(__('qc.copy.this_po_is_not_valid_for_inspection'));
            }

            // The PO lock serializes participation. Lock only the inspected
            // Shipment's expected lines below, never a sibling draft's rows.
            $hasShipmentItems = ShipmentItem::query()
                ->where('purchase_order_id', $po->id)->exists();

            if ($hasShipmentItems && ! $request->filled('shipment_id')) {
                throw new \RuntimeException(__('qc.copy.a_shipment_is_required_for_inspection_because_this_purchase_order_has_shipment_items'));
            }

            if ($request->filled('shipment_id') && ! $shipmentId) {
                throw new \RuntimeException(__('qc.copy.the_specified_shipment_could_not_be_found'));
            }

            $expectedShipmentItems = collect();
            $expectedByPrItem = collect();
            if ($shipmentId) {
                if ($shipment->status !== Shipment::STATUS_ARRIVED) {
                    throw new \RuntimeException(__('qc.copy.qc_inspection_is_only_allowed_for_an_arrived_shipment'));
                }

                $expectedShipmentItems = ShipmentItem::query()
                    ->with('quotationItem')
                    ->where('shipment_id', $shipment->id)
                    ->where('purchase_order_id', $po->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($expectedShipmentItems->isEmpty()) {
                    throw new \RuntimeException(__('qc.copy.the_specified_shipment_does_not_contain_items_for_this_purchase_order'));
                }

                if (QcInspection::where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()) {
                    throw new \RuntimeException(__('qc.feedback.already_inspected', ['po' => $po->po_number]));
                }

                $expectedByPrItem = $expectedShipmentItems->mapWithKeys(function (ShipmentItem $shipmentItem) {
                    return [(int) $shipmentItem->quotationItem->pr_item_id => $shipmentItem];
                });
                $submittedPrItemIds = collect($validated['items'])
                    ->pluck('pr_item_id')
                    ->map(fn ($id) => (int) $id);

                if ($submittedPrItemIds->duplicates()->isNotEmpty()) {
                    throw new \RuntimeException(__('qc.copy.each_shipment_item_must_be_inspected_exactly_once_duplicate_lines_were_submitted'));
                }

                $submittedKeys = $submittedPrItemIds->sort()->values()->all();
                $expectedKeys = $expectedByPrItem->keys()->sort()->values()->all();
                if ($submittedKeys !== $expectedKeys) {
                    throw new \RuntimeException(__('qc.copy.the_inspection_must_contain_every_item_from_the_selected_shipment_for_this_purchase_order_exactly_on'));
                }
            } else {
                if ($hasShipmentItems) {
                    throw new \RuntimeException(__('qc.copy.shipment_aware_qc_items_must_reference_a_shipment_item'));
                }

                if (QcInspection::where('po_id', $po->id)->whereNull('shipment_id')->exists()) {
                    throw new \RuntimeException(__('qc.copy.this_po_has_already_been_inspected'));
                }
            }

            $overallStatus = 'ok';
            foreach ($validated['items'] as $itemData) {
                if ($itemData['status'] === 'ng') {
                    $overallStatus = 'ng';
                    break;
                }
            }

            $inspection = QcInspection::create([
                'po_id' => $po->id,
                'shipment_id' => $shipment?->id,
                'inspected_by' => auth()->id(),
                'status' => $overallStatus,
                'inspected_at' => now(),
            ]);

            $uploadedFiles = $request->file('attachments', []);

            foreach ($validated['items'] as $index => $itemData) {
                $measurements = StoreQcInspectionRequest::sanitizeActualMeasurements(
                    $itemData,
                    $poPrItems->get((int) $itemData['pr_item_id'])
                );

                $shipmentItemId = $shipment
                    ? $expectedByPrItem->get((int) $itemData['pr_item_id'])?->id
                    : null;

                if ($shipment && ! $shipmentItemId) {
                    throw new \RuntimeException(__('qc.copy.shipment_aware_qc_items_must_reference_a_shipment_item'));
                }

                QcItem::create($measurements + [
                    'inspection_id' => $inspection->id,
                    'shipment_item_id' => $shipmentItemId,
                    'pr_item_id' => $itemData['pr_item_id'],
                    'status' => $itemData['status'],
                    'notes' => $itemData['notes'] ?? null,
                ]);

                if (($itemData['status'] ?? null) === 'ng') {
                    foreach ($uploadedFiles[$index] ?? [] as $file) {
                        if (! $file instanceof UploadedFile || ! $file->isValid()) {
                            continue;
                        }

                        $stagedAttachments[] = $this->saveAttachment($file, $inspection);
                    }
                }
            }

            if ($overallStatus === 'ng' && ! $inspection->attachments()->exists()) {
                throw new \RuntimeException(__('qc.copy.ng_evidence_photos_were_not_uploaded_please_upload_the_evidence_photos_again_before_saving_the_inspe'));
            }

            // One authoritative precedence rule owns the resulting PO state.
            $po->reconcileOperationalStatus();

            DB::commit();

            $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
            $isOk = $overallStatus === 'ok';
            $this->notifications->send(
                $purchasingUsers,
                $isOk ? 'qc.inspection_ok' : 'qc.inspection_ng',
                'qc.inspection_result:'.$inspection->id,
                $isOk ? 'qc.notify.ok_title' : 'qc.copy.ng_material_found',
                $isOk ? 'qc.notify.ok_body' : 'qc.notify.ng_body',
                $isOk
                    ? route('purchasing.purchase-orders.show', $po, absolute: false)
                    : route('purchasing.claims.create', $inspection, absolute: false),
                $isOk ? 'check-circle text-success' : 'triangle-alert text-danger',
                [
                    'category' => NotificationCategory::OTHER,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                    'inspection_id' => $inspection->id,
                ],
                ['po' => $po->po_number],
            );

            return $inspection;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStagedAttachments($stagedAttachments);
            throw $e;
        }
    }

    public function storeAdditionalAttachments(Request $request, QcInspection $inspection): void
    {
        $stagedAttachments = [];

        try {
            DB::beginTransaction();

            foreach ($request->file('attachments', []) as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }

                $stagedAttachments[] = $this->saveAttachment($file, $inspection);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStagedAttachments($stagedAttachments);
            throw $e;
        }
    }

    public function saveAttachment(UploadedFile $file, Model $attachable): string
    {
        $path = 'attachments/'.now()->format('Y/m').'/'.$file->hashName(); // biz-time:ignore storage path
        $stored = $this->fileInspection->storeUpload($file, 'qc', $path, 'attachments');

        try {
            $attachable->attachments()->create([
                'file_path' => $stored['file_path'],
                'file_name' => $stored['file_name'],
                'file_type' => $stored['file_type'],
                'file_inspection_id' => $stored['file_inspection_id'],
                'uploaded_by' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }

        return $path;
    }

    /**
     * Delete files staged to disk during an aborted transaction.
     *
     * @param  array<int, string>  $stagedPaths
     */
    public function cleanupStagedAttachments(array $stagedPaths): void
    {
        foreach ($stagedPaths as $path) {
            if ($path) {
                Storage::disk('private')->delete($path);
            }
        }
    }
}
