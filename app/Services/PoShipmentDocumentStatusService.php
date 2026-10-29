<?php

namespace App\Services;

use App\Exceptions\BusinessRuleArgumentException;
use App\Exceptions\PoShipmentDocumentBatchRefused;
use App\Models\Attachment;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Support\BusinessTime;
use App\Support\HashidCodec;
use App\Support\NotificationCategory;
use App\Support\Procurement\CustomsDocumentationProgressCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PoShipmentDocumentStatusService
{
    public const ALLOWED_TARGETS = CustomsDocumentationProgressCalculator::ALLOWED_TARGETS;

    public function __construct(
        private readonly CustomsDocumentationProgressCalculator $progressCalculator,
        private readonly FileAccessGuard $fileAccessGuard,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Atomically update explicitly selected Shipment documents for a given PurchaseOrder and doc_type.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     *
     * @throws PoShipmentDocumentBatchRefused
     */
    public function updateSelected(
        PurchaseOrder $po,
        string $docType,
        array $rows,
        string $target,
        ?string $reason,
        User $actor,
    ): array {
        if (! in_array($docType, ShipmentDocument::DOC_TYPES, true)) {
            throw new BusinessRuleArgumentException(__('documents.sync.invalid_doc_type'));
        }

        $allowedTargets = self::ALLOWED_TARGETS[$docType] ?? [];
        if (! in_array($target, $allowedTargets, true)) {
            throw new BusinessRuleArgumentException(__('documents.sync.invalid_target_status'));
        }

        if ($rows === [] || count($rows) > 50) {
            throw new BusinessRuleArgumentException(__('documents.sync.invalid_row_count'));
        }

        $normalizedRows = $this->normalizeRows($rows);
        $trimmedReason = $reason !== null ? trim($reason) : '';

        try {
            $txResult = DB::transaction(function () use ($po, $docType, $normalizedRows, $target, $trimmedReason, $actor): array {
                $sortedShipmentIds = collect($normalizedRows)
                    ->pluck('shipment_id')
                    ->filter(fn ($id) => is_int($id) && $id > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                // (1) Lock shipments in ascending ID order.
                $lockedShipments = $sortedShipmentIds === []
                    ? collect()
                    : Shipment::withTrashed()
                        ->whereIn('id', $sortedShipmentIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                // (2) Authorize reviewDocument per shipment, then lock shipment_documents ordered by shipment_id ascending.
                if ($lockedShipments->isEmpty()) {
                    Gate::forUser($actor)->authorize('reviewDocument', new Shipment);
                } else {
                    foreach ($lockedShipments as $shipment) {
                        Gate::forUser($actor)->authorize('reviewDocument', $shipment);
                    }
                }

                $lockedShipmentDocs = $sortedShipmentIds === []
                    ? collect()
                    : ShipmentDocument::query()
                        ->whereIn('shipment_id', $sortedShipmentIds)
                        ->where('doc_type', $docType)
                        ->orderBy('shipment_id')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('shipment_id');

                // (3) Read shipment_items to verify PO linkage and collect all affected PO IDs.
                $shipmentItemsByShipment = $sortedShipmentIds === []
                    ? collect()
                    : ShipmentItem::query()
                        ->whereIn('shipment_id', $sortedShipmentIds)
                        ->orderBy('shipment_id')
                        ->orderBy('id')
                        ->get()
                        ->groupBy('shipment_id');

                $linkedPoIdsByShipment = [];
                $allAffectedPoIds = [(int) $po->id];

                foreach ($sortedShipmentIds as $shipmentId) {
                    /** @var Shipment|null $shipment */
                    $shipment = $lockedShipments->get($shipmentId);
                    if (! $shipment || $shipment->trashed()) {
                        continue;
                    }

                    $items = $shipmentItemsByShipment->get($shipmentId, collect());
                    $poIds = $items
                        ->pluck('purchase_order_id')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    if (in_array((int) $po->id, $poIds, true)) {
                        $linkedPoIdsByShipment[$shipmentId] = $poIds;
                        foreach ($poIds as $affectedPoId) {
                            $allAffectedPoIds[] = $affectedPoId;
                        }
                    }
                }

                $allAffectedPoIds = array_values(array_unique($allAffectedPoIds));
                sort($allAffectedPoIds);

                // (4) Lock po_documents (po_id, doc_type) in ascending po_id order, creating missing rows.
                $lockedPoDocs = PoDocument::query()
                    ->whereIn('po_id', $allAffectedPoIds)
                    ->where('doc_type', $docType)
                    ->orderBy('po_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('po_id');

                foreach ($allAffectedPoIds as $affectedPoId) {
                    if (! $lockedPoDocs->has($affectedPoId)) {
                        $createdPoDoc = PoDocument::create([
                            'po_id' => $affectedPoId,
                            'doc_type' => $docType,
                            'status' => PoDocument::STATUSES[0],
                        ]);
                        $lockedPoDoc = PoDocument::query()
                            ->whereKey($createdPoDoc->id)
                            ->lockForUpdate()
                            ->first() ?? $createdPoDoc;
                        $lockedPoDocs->put($affectedPoId, $lockedPoDoc);
                    }
                }

                // Lock latest attachments per existing ShipmentDocument in ascending shipment_id order.
                $latestAttachmentsByShipment = [];
                foreach ($sortedShipmentIds as $shipmentId) {
                    /** @var ShipmentDocument|null $sDoc */
                    $sDoc = $lockedShipmentDocs->get($shipmentId);
                    if ($sDoc !== null) {
                        $latestAttachmentsByShipment[$shipmentId] = Attachment::query()
                            ->where('attachable_type', ShipmentDocument::class)
                            ->where('attachable_id', $sDoc->id)
                            ->orderByDesc('created_at')
                            ->orderByDesc('id')
                            ->lockForUpdate()
                            ->first();
                    } else {
                        $latestAttachmentsByShipment[$shipmentId] = null;
                    }
                }

                // (5) Validate every row before any write.
                $rowPlans = [];
                $hasFailure = false;
                $hasStale = false;

                foreach ($normalizedRows as $row) {
                    $shipmentId = $row['shipment_id'];
                    /** @var Shipment|null $shipment */
                    $shipment = $shipmentId ? $lockedShipments->get($shipmentId) : null;
                    /** @var ShipmentDocument|null $sDoc */
                    $sDoc = $shipmentId ? $lockedShipmentDocs->get($shipmentId) : null;
                    /** @var Attachment|null $latestAttachment */
                    $latestAttachment = $shipmentId ? ($latestAttachmentsByShipment[$shipmentId] ?? null) : null;

                    $currentStatus = $sDoc?->status ?? ShipmentDocument::STATUS_PENDING;
                    $currentAttachmentHash = $latestAttachment?->hash;
                    $shipmentHash = $shipment ? (string) $shipment->hash : (string) $row['raw_shipment'];
                    $shipmentNumber = $shipment?->shipment_number;

                    if (! $shipment || $shipment->trashed() || ! isset($linkedPoIdsByShipment[$shipmentId])) {
                        $hasFailure = true;
                        $rowPlans[] = [
                            'shipment' => $shipmentHash,
                            'shipment_id' => $shipmentId,
                            'shipment_number' => $shipmentNumber,
                            'result' => 'rejected',
                            'reason_code' => 'not_linked',
                            'message' => __('documents.sync.reasons.not_linked'),
                            'from' => $currentStatus,
                            'to' => null,
                            'current_status' => $currentStatus,
                            'current_attachment' => $currentAttachmentHash,
                        ];

                        continue;
                    }

                    if (! in_array($shipment->status, CustomsDocumentationProgressCalculator::ACTIVE_SHIPMENT_STATUSES, true)) {
                        $hasFailure = true;
                        $rowPlans[] = [
                            'shipment' => $shipmentHash,
                            'shipment_id' => $shipmentId,
                            'shipment_number' => $shipmentNumber,
                            'result' => 'rejected',
                            'reason_code' => 'invalid_shipment_status',
                            'message' => __('documents.sync.reasons.invalid_shipment_status'),
                            'from' => $currentStatus,
                            'to' => null,
                            'current_status' => $currentStatus,
                            'current_attachment' => $currentAttachmentHash,
                        ];

                        continue;
                    }

                    if ($row['expected_status'] !== null && $currentStatus !== $row['expected_status']) {
                        $hasFailure = true;
                        $hasStale = true;
                        $rowPlans[] = [
                            'shipment' => $shipmentHash,
                            'shipment_id' => $shipmentId,
                            'shipment_number' => $shipmentNumber,
                            'result' => 'stale',
                            'reason_code' => 'stale_status',
                            'message' => __('documents.sync.reasons.stale_status'),
                            'from' => $currentStatus,
                            'to' => null,
                            'current_status' => $currentStatus,
                            'current_attachment' => $currentAttachmentHash,
                        ];

                        continue;
                    }

                    if (
                        in_array($target, CustomsDocumentationProgressCalculator::VERIFIED_STATUSES, true)
                        && $row['expected_attachment_provided']
                    ) {
                        if ($row['expected_attachment_invalid']) {
                            $hasFailure = true;
                            $hasStale = true;
                            $rowPlans[] = [
                                'shipment' => $shipmentHash,
                                'shipment_id' => $shipmentId,
                                'shipment_number' => $shipmentNumber,
                                'result' => 'stale',
                                'reason_code' => 'stale_attachment',
                                'message' => __('documents.sync.reasons.stale_attachment'),
                                'from' => $currentStatus,
                                'to' => null,
                                'current_status' => $currentStatus,
                                'current_attachment' => $currentAttachmentHash,
                            ];

                            continue;
                        }

                        $expectedAttachmentId = $row['expected_attachment_id'];
                        $actualAttachmentId = $latestAttachment?->id ? (int) $latestAttachment->id : null;

                        if ($expectedAttachmentId !== null && $actualAttachmentId !== $expectedAttachmentId) {
                            $hasFailure = true;
                            $hasStale = true;
                            $rowPlans[] = [
                                'shipment' => $shipmentHash,
                                'shipment_id' => $shipmentId,
                                'shipment_number' => $shipmentNumber,
                                'result' => 'stale',
                                'reason_code' => 'stale_attachment',
                                'message' => __('documents.sync.reasons.stale_attachment'),
                                'from' => $currentStatus,
                                'to' => null,
                                'current_status' => $currentStatus,
                                'current_attachment' => $currentAttachmentHash,
                            ];

                            continue;
                        }

                        if ($expectedAttachmentId === null && $actualAttachmentId !== null) {
                            $hasFailure = true;
                            $hasStale = true;
                            $rowPlans[] = [
                                'shipment' => $shipmentHash,
                                'shipment_id' => $shipmentId,
                                'shipment_number' => $shipmentNumber,
                                'result' => 'stale',
                                'reason_code' => 'stale_attachment',
                                'message' => __('documents.sync.reasons.stale_attachment'),
                                'from' => $currentStatus,
                                'to' => null,
                                'current_status' => $currentStatus,
                                'current_attachment' => $currentAttachmentHash,
                            ];

                            continue;
                        }
                    }

                    if ($currentStatus === $target) {
                        $rowPlans[] = [
                            'shipment' => $shipmentHash,
                            'shipment_id' => $shipmentId,
                            'shipment_number' => $shipmentNumber,
                            'result' => 'skipped',
                            'reason_code' => null,
                            'message' => null,
                            'from' => $currentStatus,
                            'to' => $target,
                            'current_status' => $currentStatus,
                            'current_attachment' => $currentAttachmentHash,
                        ];

                        continue;
                    }

                    $currentRank = PoDocument::STATUS_RANKS[$currentStatus] ?? 0;
                    $targetRank = PoDocument::STATUS_RANKS[$target] ?? 0;

                    if ($targetRank <= $currentRank && $trimmedReason === '') {
                        $hasFailure = true;
                        $rowPlans[] = [
                            'shipment' => $shipmentHash,
                            'shipment_id' => $shipmentId,
                            'shipment_number' => $shipmentNumber,
                            'result' => 'rejected',
                            'reason_code' => 'reason_required',
                            'message' => __('documents.sync.reasons.reason_required'),
                            'from' => $currentStatus,
                            'to' => null,
                            'current_status' => $currentStatus,
                            'current_attachment' => $currentAttachmentHash,
                        ];

                        continue;
                    }

                    if (in_array($target, CustomsDocumentationProgressCalculator::VERIFIED_STATUSES, true)) {
                        if ($latestAttachment === null) {
                            $hasFailure = true;
                            $rowPlans[] = [
                                'shipment' => $shipmentHash,
                                'shipment_id' => $shipmentId,
                                'shipment_number' => $shipmentNumber,
                                'result' => 'rejected',
                                'reason_code' => 'missing_attachment',
                                'message' => __('documents.sync.reasons.missing_attachment'),
                                'from' => $currentStatus,
                                'to' => null,
                                'current_status' => $currentStatus,
                                'current_attachment' => null,
                            ];

                            continue;
                        }

                        $classification = $this->fileAccessGuard->classification($latestAttachment);
                        if (! in_array($classification, CustomsDocumentationProgressCalculator::VALID_EVIDENCE_CLASSIFICATIONS, true)) {
                            $hasFailure = true;
                            $rowPlans[] = [
                                'shipment' => $shipmentHash,
                                'shipment_id' => $shipmentId,
                                'shipment_number' => $shipmentNumber,
                                'result' => 'rejected',
                                'reason_code' => 'invalid_attachment',
                                'message' => __('documents.sync.reasons.invalid_attachment'),
                                'from' => $currentStatus,
                                'to' => null,
                                'current_status' => $currentStatus,
                                'current_attachment' => $currentAttachmentHash,
                            ];

                            continue;
                        }
                    }

                    $rowPlans[] = [
                        'shipment' => $shipmentHash,
                        'shipment_id' => $shipmentId,
                        'shipment_number' => $shipmentNumber,
                        'result' => 'updated',
                        'reason_code' => null,
                        'message' => null,
                        'from' => $currentStatus,
                        'to' => $target,
                        'current_status' => $target,
                        'current_attachment' => $currentAttachmentHash,
                    ];
                }

                // All-or-nothing refusal before any write to shipment_documents / po_documents.
                if ($hasFailure) {
                    $refusedRows = array_map(function (array $plan): array {
                        if (in_array($plan['result'], ['updated', 'skipped'], true)) {
                            $plan['result'] = 'not_applied';
                            $plan['reason_code'] = null;
                            $plan['message'] = __('documents.sync.reasons.not_applied');
                            $plan['to'] = null;
                            $plan['current_status'] = $plan['from'];
                        }
                        unset($plan['shipment_id']);

                        return $plan;
                    }, $rowPlans);

                    $errorCode = $hasStale ? 'stale_state' : 'validation_failed';
                    $httpStatus = $hasStale ? 409 : 422;
                    $message = $hasStale
                        ? __('documents.sync.batch_refused_stale')
                        : __('documents.sync.batch_refused_invalid');

                    throw new PoShipmentDocumentBatchRefused(
                        errorCode: $errorCode,
                        message: $message,
                        rows: $refusedRows,
                        httpStatus: $httpStatus,
                    );
                }

                // Capture pre-update all_received state for each affected PO.
                $affectedPos = PurchaseOrder::query()
                    ->with([
                        'creator',
                        'documents',
                        'shipmentItems.shipment.documents.latestAttachment.fileInspection',
                        'shipmentItems.shipment.items.purchaseOrder:id,po_number',
                    ])
                    ->whereIn('id', $allAffectedPoIds)
                    ->orderBy('id')
                    ->get()
                    ->keyBy('id');

                $wasAllReceivedByPo = [];
                foreach ($affectedPos as $affectedPoId => $affectedPoModel) {
                    $wasAllReceivedByPo[(int) $affectedPoId] = (bool) ($this->progressCalculator->calculate($affectedPoModel)['all_received'] ?? false);
                }

                // Apply updates in ascending shipment_id order for deterministic writes.
                $updatedShipmentDocIdsByPo = [];
                $plansByShipmentId = collect($rowPlans)->keyBy('shipment_id');

                foreach ($sortedShipmentIds as $shipmentId) {
                    $plan = $plansByShipmentId->get($shipmentId);
                    if (! $plan || $plan['result'] !== 'updated') {
                        continue;
                    }

                    /** @var Shipment $shipment */
                    $shipment = $lockedShipments->get($shipmentId);
                    /** @var ShipmentDocument|null $sDoc */
                    $sDoc = $lockedShipmentDocs->get($shipmentId);

                    if (! $sDoc) {
                        $sDoc = ShipmentDocument::create([
                            'shipment_id' => $shipment->id,
                            'doc_type' => $docType,
                            'status' => ShipmentDocument::STATUS_PENDING,
                        ]);
                        $sDoc = ShipmentDocument::query()
                            ->whereKey($sDoc->id)
                            ->lockForUpdate()
                            ->first() ?? $sDoc;
                    }

                    $newNotes = $sDoc->notes;
                    if ($trimmedReason !== '') {
                        $auditLine = sprintf(
                            '[%s] %s (%s -> %s): %s',
                            BusinessTime::now()->format('Y-m-d H:i:s'),
                            $actor->name,
                            $plan['from'],
                            $target,
                            $trimmedReason,
                        );
                        $newNotes = $newNotes !== null && trim($newNotes) !== ''
                            ? $newNotes."\n".$auditLine
                            : $auditLine;
                    }

                    $sDoc->update([
                        'status' => $target,
                        'notes' => $newNotes,
                    ]);

                    $sDoc->setRelation('shipment', $shipment->setRelation('items', $shipmentItemsByShipment->get($shipmentId, collect())));
                    PoDocument::syncFromShipmentDocument($sDoc);

                    foreach ($linkedPoIdsByShipment[$shipmentId] ?? [] as $affectedPoId) {
                        $updatedShipmentDocIdsByPo[(int) $affectedPoId][] = (int) $sDoc->id;
                    }

                    Log::info('po.shipment_document.status_updated', [
                        'actor_id' => $actor->id,
                        'po_id' => $po->id,
                        'po_number' => $po->po_number,
                        'shipment_id' => $shipment->id,
                        'shipment_number' => $shipment->shipment_number,
                        'shipment_document_id' => $sDoc->id,
                        'doc_type' => $docType,
                        'from_status' => $plan['from'],
                        'to_status' => $target,
                        'reason' => $trimmedReason !== '' ? $trimmedReason : null,
                    ]);
                }

                $responseRows = array_map(function (array $plan): array {
                    unset($plan['shipment_id']);

                    return $plan;
                }, $rowPlans);

                return [
                    'rows' => $responseRows,
                    'all_affected_po_ids' => $allAffectedPoIds,
                    'updated_shipment_doc_ids_by_po' => $updatedShipmentDocIdsByPo,
                    'was_all_received_by_po' => $wasAllReceivedByPo,
                ];
            }, 3);
        } catch (PoShipmentDocumentBatchRefused $refused) {
            $freshPo = PurchaseOrder::query()
                ->with([
                    'documents',
                    'shipmentItems.shipment.documents.latestAttachment.fileInspection',
                    'shipmentItems.shipment.items.purchaseOrder:id,po_number',
                ])
                ->find($po->id) ?? $po;

            $freshProjection = $this->progressCalculator->calculate($freshPo);
            $rowsState = $freshProjection['documents'][$docType]['rows'] ?? [];

            throw new PoShipmentDocumentBatchRefused(
                errorCode: $refused->errorCode,
                message: $refused->getMessage(),
                rows: $refused->rows,
                rowsState: $rowsState,
                projection: $freshProjection,
                httpStatus: $refused->httpStatus,
            );
        }

        // Post-commit notifications & fresh projection
        $batchUuid = (string) Str::uuid();
        $affectedPoSummaries = [];

        $affectedPosAfter = PurchaseOrder::query()
            ->with([
                'creator',
                'documents',
                'shipmentItems.shipment.documents.latestAttachment.fileInspection',
                'shipmentItems.shipment.items.purchaseOrder:id,po_number',
            ])
            ->whereIn('id', $txResult['all_affected_po_ids'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($txResult['all_affected_po_ids'] as $affectedPoId) {
            /** @var PurchaseOrder|null $affectedPo */
            $affectedPo = $affectedPosAfter->get($affectedPoId);
            if (! $affectedPo) {
                continue;
            }

            $poProgress = $this->progressCalculator->calculate($affectedPo);
            $poDoc = $affectedPo->documents->firstWhere('doc_type', $docType);

            $affectedPoSummaries[] = [
                'po_id' => (int) $affectedPo->id,
                'po_hash' => (string) $affectedPo->hash,
                'po_number' => (string) $affectedPo->po_number,
                'po_document_status' => $poDoc?->status ?? PoDocument::STATUSES[0],
                'all_received' => (bool) ($poProgress['all_received'] ?? false),
                'all_verified' => (bool) ($poProgress['all_verified'] ?? false),
            ];

            $updatedDocIds = array_values(array_unique($txResult['updated_shipment_doc_ids_by_po'][$affectedPoId] ?? []));
            if ($updatedDocIds === [] || ! $affectedPo->creator) {
                continue;
            }

            $this->notifications->send(
                $affectedPo->creator,
                'document.status_updated',
                "document.status_updated:{$affectedPo->id}:{$docType}:{$target}:{$batchUuid}",
                'documents.copy.document_status_updated',
                'documents.notify.shipment_updated_body',
                route('purchasing.purchase-orders.show', $affectedPo, absolute: false),
                'file-check text-primary',
                [
                    'category' => NotificationCategory::DOCUMENT,
                    'document_id' => $poDoc?->id,
                    'shipment_document_ids' => $updatedDocIds,
                    'doc_type' => $docType,
                    'status' => $target,
                    'count' => count($updatedDocIds),
                    'po_id' => $affectedPo->id,
                    'po_number' => $affectedPo->po_number,
                ],
                [
                    'po' => $affectedPo->po_number,
                    'count' => count($updatedDocIds),
                ],
                [
                    'document' => 'documents.notify.type_'.(in_array($docType, ['invoice', 'bl', 'packing_list', 'form_e'], true) ? $docType : 'other'),
                    'status' => 'documents.notify.status_'.(in_array($target, ['pending', 'received', 'verified', 'issued', 'processing', 'done'], true) ? $target : 'other'),
                ],
            );

            $wasAllReceived = (bool) ($txResult['was_all_received_by_po'][$affectedPoId] ?? false);
            $isNowAllReceived = (bool) ($poProgress['all_received'] ?? false);

            if ($isNowAllReceived && ! $wasAllReceived) {
                $this->notifications->send(
                    $affectedPo->creator,
                    'document.all_completed',
                    "document.all_completed:{$affectedPo->id}:{$batchUuid}",
                    'documents.copy.all_import_documents_complete',
                    'documents.notify.completed_body',
                    route('purchasing.purchase-orders.show', $affectedPo, absolute: false),
                    'circle-check text-success',
                    [
                        'category' => NotificationCategory::DOCUMENT,
                        'document_id' => $poDoc?->id,
                        'shipment_document_ids' => $updatedDocIds,
                        'po_id' => $affectedPo->id,
                        'po_number' => $affectedPo->po_number,
                    ],
                    ['po' => $affectedPo->po_number],
                );
            }
        }

        /** @var PurchaseOrder $primaryPo */
        $primaryPo = $affectedPosAfter->get((int) $po->id) ?? $po->fresh();
        $finalProjection = $this->progressCalculator->calculate($primaryPo);

        $updatedCount = collect($txResult['rows'])->where('result', 'updated')->count();
        $skippedCount = collect($txResult['rows'])->where('result', 'skipped')->count();

        return [
            'success' => true,
            'message' => __('documents.sync.updated_success', [
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
            ]),
            'counts' => [
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'total' => count($txResult['rows']),
            ],
            'rows' => $txResult['rows'],
            'affected_pos' => $affectedPoSummaries,
            'rows_state' => $finalProjection['documents'][$docType]['rows'] ?? [],
            'projection' => $finalProjection,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{
     *     raw_shipment: string,
     *     shipment_id: ?int,
     *     expected_status: ?string,
     *     expected_attachment_provided: bool,
     *     expected_attachment_id: ?int,
     *     expected_attachment_invalid: bool
     * }>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        $seenShipmentIds = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new BusinessRuleArgumentException(__('documents.sync.invalid_row_payload'));
            }

            $rawShipment = isset($row['shipment']) ? trim((string) $row['shipment']) : '';
            $shipmentId = null;

            if ($rawShipment !== '' && ! ctype_digit($rawShipment)) {
                $shipmentId = HashidCodec::decode($rawShipment);
            }

            if ($shipmentId !== null) {
                if (isset($seenShipmentIds[$shipmentId])) {
                    throw new BusinessRuleArgumentException(__('documents.sync.duplicate_shipment_row'));
                }
                $seenShipmentIds[$shipmentId] = true;
            }

            $expectedStatus = isset($row['expected_status']) && is_string($row['expected_status']) && trim($row['expected_status']) !== ''
                ? trim($row['expected_status'])
                : null;

            $expectedAttachmentProvided = array_key_exists('expected_attachment', $row);
            $expectedAttachmentId = null;
            $expectedAttachmentInvalid = false;

            if ($expectedAttachmentProvided && $row['expected_attachment'] !== null && $row['expected_attachment'] !== '') {
                $rawAttachment = trim((string) $row['expected_attachment']);
                if (ctype_digit($rawAttachment)) {
                    $expectedAttachmentInvalid = true;
                } else {
                    $decodedAttachment = HashidCodec::decode($rawAttachment);
                    if ($decodedAttachment === null) {
                        $expectedAttachmentInvalid = true;
                    } else {
                        $expectedAttachmentId = $decodedAttachment;
                    }
                }
            }

            $normalized[] = [
                'raw_shipment' => $rawShipment,
                'shipment_id' => $shipmentId,
                'expected_status' => $expectedStatus,
                'expected_attachment_provided' => $expectedAttachmentProvided,
                'expected_attachment_id' => $expectedAttachmentId,
                'expected_attachment_invalid' => $expectedAttachmentInvalid,
            ];
        }

        return $normalized;
    }
}
