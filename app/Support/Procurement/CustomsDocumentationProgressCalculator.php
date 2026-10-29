<?php

namespace App\Support\Procurement;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\FileSecurity\FileAccessGuard;
use Illuminate\Support\Facades\DB;

class CustomsDocumentationProgressCalculator
{
    public const DOC_TYPES = [
        ShipmentDocument::DOC_TYPE_INVOICE,
        ShipmentDocument::DOC_TYPE_BL,
        ShipmentDocument::DOC_TYPE_PACKING_LIST,
        ShipmentDocument::DOC_TYPE_FORM_E,
    ];

    public const ALLOWED_TARGETS = [
        ShipmentDocument::DOC_TYPE_INVOICE => [
            ShipmentDocument::STATUS_PENDING,
            ShipmentDocument::STATUS_RECEIVED,
            ShipmentDocument::STATUS_VERIFIED,
        ],
        ShipmentDocument::DOC_TYPE_BL => [
            ShipmentDocument::STATUS_PENDING,
            ShipmentDocument::STATUS_ISSUED,
            ShipmentDocument::STATUS_DONE,
        ],
        ShipmentDocument::DOC_TYPE_PACKING_LIST => [
            ShipmentDocument::STATUS_PENDING,
            ShipmentDocument::STATUS_RECEIVED,
            ShipmentDocument::STATUS_VERIFIED,
        ],
        ShipmentDocument::DOC_TYPE_FORM_E => [
            ShipmentDocument::STATUS_PENDING,
            ShipmentDocument::STATUS_PROCESSING,
            ShipmentDocument::STATUS_DONE,
        ],
    ];

    public const ACTIVE_SHIPMENT_STATUSES = [
        Shipment::STATUS_SUBMITTED,
        Shipment::STATUS_ARRIVED,
    ];

    public const RECEIVED_STATUSES = [
        ShipmentDocument::STATUS_RECEIVED,
        ShipmentDocument::STATUS_VERIFIED,
        ShipmentDocument::STATUS_DONE,
    ];

    public const VERIFIED_STATUSES = [
        ShipmentDocument::STATUS_VERIFIED,
        ShipmentDocument::STATUS_DONE,
    ];

    public const IN_PROGRESS_STATUSES = [
        ShipmentDocument::STATUS_ISSUED,
        ShipmentDocument::STATUS_PROCESSING,
    ];

    public const VALID_EVIDENCE_CLASSIFICATIONS = [
        'VALIDATED',
        'HISTORICAL_UNVERIFIED',
    ];

    /**
     * Pure read-only customs documentation progress projection for a PurchaseOrder.
     *
     * @return array<string, mixed>
     */
    public function calculate(PurchaseOrder $po): array
    {
        $po->loadMissing([
            'documents',
            'shipmentItems.shipment.documents.latestAttachment.fileInspection',
            'shipmentItems.shipment.items.purchaseOrder:id,po_number',
        ]);

        $isLegacy = $this->resolveIsLegacy($po);

        $cutoverMaxId = $this->fetchAttachmentsCutoverMaxId();

        $allShipments = $po->shipmentItems
            ->map(fn ($item) => $item->shipment)
            ->filter(fn ($shipment) => $shipment instanceof Shipment && ! $shipment->trashed())
            ->unique('id')
            ->sortBy('id')
            ->values();

        $activeShipments = $allShipments
            ->filter(fn (Shipment $shipment) => in_array($shipment->status, self::ACTIVE_SHIPMENT_STATUSES, true))
            ->values();

        $activeShipmentCount = $activeShipments->count();
        $draftShipmentCount = $allShipments->where('status', Shipment::STATUS_DRAFT)->count();
        $cancelledShipmentCount = $allShipments->where('status', Shipment::STATUS_CANCELLED)->count();
        $inactiveShipmentCount = $draftShipmentCount + $cancelledShipmentCount;

        $poDocsByType = $po->documents->keyBy('doc_type');

        $docLabels = [
            ShipmentDocument::DOC_TYPE_INVOICE => __('purchasing.copy.commercial_invoice'),
            ShipmentDocument::DOC_TYPE_BL => __('purchasing.copy.bill_of_lading_b_l'),
            ShipmentDocument::DOC_TYPE_PACKING_LIST => __('purchasing.copy.packing_list'),
            ShipmentDocument::DOC_TYPE_FORM_E => 'Form-E Certificate',
        ];

        $documents = [];
        $totalEvidenceGapCount = 0;
        $receivedDocTypesCount = 0;
        $verifiedDocTypesCount = 0;
        $anyReceivedAcrossDocs = false;
        $anyVerifiedAcrossDocs = false;

        foreach (self::DOC_TYPES as $docType) {
            $poDoc = $poDocsByType->get($docType);

            $receivedCount = 0;
            $verifiedCount = 0;
            $inProgressCount = 0;
            $pendingCount = 0;
            $filesPresentCount = 0;
            $evidenceGapCount = 0;
            $rows = [];

            foreach ($allShipments as $shipment) {
                $isActiveShipment = in_array($shipment->status, self::ACTIVE_SHIPMENT_STATUSES, true);
                /** @var ShipmentDocument|null $sDoc */
                $sDoc = $shipment->documents->firstWhere('doc_type', $docType);

                $rawStatus = $sDoc?->status ?? ShipmentDocument::STATUS_PENDING;
                $missingRow = $sDoc === null;

                /** @var Attachment|null $latestAttachment */
                $latestAttachment = null;
                if ($sDoc !== null) {
                    $latestAttachment = $sDoc->relationLoaded('latestAttachment')
                        ? $sDoc->latestAttachment
                        : $sDoc->attachments()->orderByDesc('created_at')->orderByDesc('id')->first();
                }

                $classification = $this->classifyAttachment($latestAttachment, $cutoverMaxId);
                $hasValidEvidence = $classification !== null
                    && in_array($classification, self::VALID_EVIDENCE_CLASSIFICATIONS, true);
                $isLegacyEvidence = $classification === 'HISTORICAL_UNVERIFIED';

                $isReceived = in_array($rawStatus, self::RECEIVED_STATUSES, true);
                $isVerified = in_array($rawStatus, self::VERIFIED_STATUSES, true);
                $isInProgress = in_array($rawStatus, self::IN_PROGRESS_STATUSES, true);
                $hasEvidenceGap = $isActiveShipment && $isReceived && ! $hasValidEvidence;

                if ($isActiveShipment) {
                    if ($isReceived) {
                        $receivedCount++;
                    }
                    if ($isVerified) {
                        $verifiedCount++;
                    }
                    if ($isInProgress) {
                        $inProgressCount++;
                    }
                    if ($rawStatus === ShipmentDocument::STATUS_PENDING) {
                        $pendingCount++;
                    }
                    if ($hasValidEvidence) {
                        $filesPresentCount++;
                    }
                    if ($hasEvidenceGap) {
                        $evidenceGapCount++;
                    }
                }

                $sharedPoNumbers = $this->resolveSharedPoNumbers($shipment, (int) $po->id);

                $rows[] = [
                    'shipment_id' => (int) $shipment->id,
                    'shipment' => (string) $shipment->hash,
                    'shipment_hash' => (string) $shipment->hash,
                    'shipment_number' => (string) $shipment->shipment_number,
                    'shipment_status' => (string) $shipment->status,
                    'shipment_url' => route('purchasing.shipments.show', $shipment, false),
                    'shipment_document_id' => $sDoc?->id ? (int) $sDoc->id : null,
                    'doc_type' => $docType,
                    'status' => $rawStatus,
                    'status_rank' => PoDocument::STATUS_RANKS[$rawStatus] ?? 0,
                    'missing_row' => $missingRow,
                    'document_number' => $sDoc?->document_number,
                    'notes' => $sDoc?->notes,
                    'eligible' => $isActiveShipment,
                    'ineligible_reason' => $isActiveShipment ? null : 'invalid_shipment_status',
                    'has_file' => $latestAttachment !== null,
                    'has_valid_evidence' => $hasValidEvidence,
                    'is_legacy_evidence' => $isLegacyEvidence,
                    'attachment_classification' => $classification,
                    'attachment_id' => $latestAttachment?->id ? (int) $latestAttachment->id : null,
                    'attachment_hash' => $latestAttachment?->hash,
                    'attachment_name' => $latestAttachment?->file_name,
                    'attachment_url' => $latestAttachment ? route('attachments.show', $latestAttachment, false) : null,
                    'can_verify' => $isActiveShipment && $hasValidEvidence,
                    'has_evidence_gap' => $hasEvidenceGap,
                    'shared_po_numbers' => $sharedPoNumbers,
                    'is_multi_po' => count($sharedPoNumbers) > 0,
                ];
            }

            $waiting = $activeShipmentCount === 0;
            $docAllReceived = ! $waiting && $receivedCount === $activeShipmentCount;
            $docAllVerified = ! $waiting && $verifiedCount === $activeShipmentCount;
            $docPartialReceived = ! $waiting && $receivedCount > 0 && $receivedCount < $activeShipmentCount;
            $docPartialVerified = ! $waiting && $verifiedCount > 0 && $verifiedCount < $activeShipmentCount;

            if ($docAllReceived) {
                $receivedDocTypesCount++;
            }
            if ($docAllVerified) {
                $verifiedDocTypesCount++;
            }
            if ($receivedCount > 0) {
                $anyReceivedAcrossDocs = true;
            }
            if ($verifiedCount > 0) {
                $anyVerifiedAcrossDocs = true;
            }

            $totalEvidenceGapCount += $evidenceGapCount;

            $aggregateState = match (true) {
                $waiting => 'waiting',
                $docAllVerified => 'all_verified',
                $docPartialVerified => 'partial_verified',
                $docAllReceived => 'all_received',
                $docPartialReceived => 'partial_received',
                $inProgressCount > 0 => 'in_progress',
                default => 'pending',
            };

            $documents[$docType] = [
                'doc_type' => $docType,
                'label' => $docLabels[$docType] ?? ucfirst(str_replace('_', ' ', $docType)),
                'allowed_targets' => self::ALLOWED_TARGETS[$docType] ?? [],
                'po_document_id' => $poDoc?->id ? (int) $poDoc->id : null,
                'po_document_status' => $poDoc?->status ?? PoDocument::STATUSES[0],
                'active_shipment_count' => $activeShipmentCount,
                'draft_shipment_count' => $draftShipmentCount,
                'cancelled_shipment_count' => $cancelledShipmentCount,
                'inactive_shipment_count' => $inactiveShipmentCount,
                'total_shipment_count' => $allShipments->count(),
                'received_count' => $receivedCount,
                'verified_count' => $verifiedCount,
                'in_progress_count' => $inProgressCount,
                'pending_count' => $pendingCount,
                'files_present' => $filesPresentCount,
                'evidence_gap_count' => $evidenceGapCount,
                'waiting' => $waiting,
                'all_received' => $docAllReceived,
                'all_verified' => $docAllVerified,
                'partial_received' => $docPartialReceived,
                'partial_verified' => $docPartialVerified,
                'aggregate_state' => $aggregateState,
                'rows' => $rows,
            ];
        }

        $waiting = $activeShipmentCount === 0;
        $allReceived = ! $waiting && $receivedDocTypesCount === count(self::DOC_TYPES);
        $allVerified = ! $waiting && $verifiedDocTypesCount === count(self::DOC_TYPES);
        $partialReceived = ! $waiting && ! $allReceived && $anyReceivedAcrossDocs;
        $partialVerified = ! $waiting && ! $allVerified && $anyVerifiedAcrossDocs;

        return [
            'uses_shipment_workflow' => ! $isLegacy,
            'is_legacy' => $isLegacy,
            'active_shipment_count' => $activeShipmentCount,
            'draft_shipment_count' => $draftShipmentCount,
            'cancelled_shipment_count' => $cancelledShipmentCount,
            'inactive_shipment_count' => $inactiveShipmentCount,
            'total_shipment_count' => $allShipments->count(),
            'waiting' => $waiting,
            'all_received' => $allReceived,
            'all_verified' => $allVerified,
            'partial_received' => $partialReceived,
            'partial_verified' => $partialVerified,
            'received_doc_types_count' => $receivedDocTypesCount,
            'verified_doc_types_count' => $verifiedDocTypesCount,
            'evidence_gap_count' => $totalEvidenceGapCount,
            'documents' => $documents,
        ];
    }

    /**
     * Classify an Attachment without N+1 queries while maintaining exact parity
     * with FileAccessGuard::classification().
     */
    public function classifyAttachment(?Attachment $attachment, ?int $attachmentsCutoverMaxId = null): ?string
    {
        if ($attachment === null) {
            return null;
        }

        if ($attachmentsCutoverMaxId === null) {
            $attachmentsCutoverMaxId = $this->fetchAttachmentsCutoverMaxId();
        }

        if ($attachment->file_inspection_id) {
            $inspection = $attachment->relationLoaded('fileInspection')
                ? $attachment->fileInspection
                : FileInspection::find($attachment->file_inspection_id);

            if (! $inspection) {
                return 'ERROR';
            }

            if ($inspection->status === 'HISTORICAL_UNVERIFIED') {
                $table = $inspection->metadata['source_table'] ?? '';
                $id = (int) ($inspection->metadata['source_id'] ?? 0);

                if (
                    $table === 'attachments'
                    && $attachmentsCutoverMaxId !== null
                    && $id >= 1
                    && $id <= $attachmentsCutoverMaxId
                    && $id === (int) $attachment->getKey()
                    && $attachment->exists
                ) {
                    return 'HISTORICAL_UNVERIFIED';
                }

                return app(FileAccessGuard::class)->classification($attachment);
            }

            return $inspection->status ?: 'ERROR';
        }

        return ($attachmentsCutoverMaxId !== null && $attachment->exists && (int) $attachment->getKey() > 0 && (int) $attachment->getKey() <= $attachmentsCutoverMaxId)
            ? 'HISTORICAL_UNVERIFIED'
            : 'ERROR';
    }

    private function fetchAttachmentsCutoverMaxId(): ?int
    {
        $max = DB::table('file_security_cutovers')
            ->where('document_table', 'attachments')
            ->value('legacy_max_id');

        return $max !== null ? (int) $max : null;
    }

    private function resolveIsLegacy(PurchaseOrder $po): bool
    {
        if ($po->relationLoaded('shipmentItems') && $po->shipmentItems->isNotEmpty()) {
            return false;
        }

        if ($po->relationLoaded('awards') && $po->awards->isNotEmpty()) {
            return false;
        }

        return $po->isLegacyArrivalEligible();
    }

    /**
     * @return array<int, string>
     */
    private function resolveSharedPoNumbers(Shipment $shipment, int $currentPoId): array
    {
        if (! $shipment->relationLoaded('items')) {
            return [];
        }

        return $shipment->items
            ->filter(fn ($item) => (int) $item->purchase_order_id !== $currentPoId)
            ->map(fn ($item) => $item->purchaseOrder?->po_number)
            ->filter(fn ($poNumber) => is_string($poNumber) && trim($poNumber) !== '')
            ->unique()
            ->values()
            ->all();
    }
}
