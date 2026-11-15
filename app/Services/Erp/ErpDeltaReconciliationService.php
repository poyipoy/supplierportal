<?php

namespace App\Services\Erp;

use App\Models\ErpDeltaRow;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpDeltaReconciliationService
{
    public function __construct(
        private readonly LocalProcurementMasterService $masterService,
        private readonly LocalFinanceAuditService $audit,
    ) {}

    /**
     * Compute a deterministic xxh3 hex digest across all rows of a document group.
     *
     * @param  iterable<int, ErpDeltaRow>  $rows
     */
    public function computeCompositeDigest(iterable $rows): string
    {
        $hashes = [];
        foreach ($rows as $row) {
            $hashes[] = strtolower(trim((string) $row->row_hash));
        }
        sort($hashes, SORT_STRING);

        return hash('xxh3', implode("\n", $hashes));
    }

    /**
     * Map an ERP status string to a LocalPurchaseOrder status, or null when unmapped (e.g. GR status).
     */
    public function mapErpStatusToLocalPo(string $erpStatus): ?string
    {
        $normalized = strtoupper(trim($erpStatus));

        return match ($normalized) {
            'OPEN', 'APPROVED', 'ACTIVE', 'FREE' => LocalPurchaseOrder::STATUS_OPEN,
            'CLOSED', 'COMPLETED' => LocalPurchaseOrder::STATUS_CLOSED,
            'CANCELLED', 'CANCELED' => LocalPurchaseOrder::STATUS_CANCELLED,
            default => null,
        };
    }

    /**
     * Project line-grain ErpDeltaRows into document-grain reconciliation summaries.
     *
     * @return list<array<string, mixed>>
     */
    public function projectDocumentsForSupplier(int $supplierId): array
    {
        $rows = ErpDeltaRow::query()
            ->where('supplier_id', $supplierId)
            ->orderBy('doc_no')
            ->orderBy('item_code')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $grouped = $rows->groupBy(fn (ErpDeltaRow $row): string => $this->normalizeDocKey((string) $row->doc_no));
        $lowerDocNos = $grouped->keys()
            ->map(fn (string $docNo): string => mb_strtolower($docNo, 'UTF-8'))
            ->values()
            ->all();

        $localPos = $this->loadLocalPosForDocs($supplierId, $lowerDocNos);
        $blockedGrLinkPoIds = $this->loadBlockedGrPoIds($localPos);

        $unmatchedLowerDocs = array_values(array_diff($lowerDocNos, $localPos->keys()->all()));
        $importPos = $this->loadImportPosForDocs($supplierId, $unmatchedLowerDocs);

        $projections = [];
        foreach ($grouped as $docNo => $docRows) {
            $lowerKey = mb_strtolower((string) $docNo, 'UTF-8');
            $projections[] = $this->buildDocumentProjection(
                (string) $docNo,
                $docRows,
                $localPos->get($lowerKey),
                $blockedGrLinkPoIds,
                $importPos->get($lowerKey),
            );
        }

        return $projections;
    }

    /**
     * Apply aggregated ERP delta values for one document through LocalProcurementMasterService.
     *
     * @return array<string, mixed>
     */
    public function applyDocument(
        User $actor,
        int $supplierId,
        string $docNo,
        string $expectedDigest,
        ?string $note = null,
    ): array {
        $docKey = $this->normalizeDocKey($docNo);
        $cleanNote = $this->normalizeNote($note);

        return DB::transaction(function () use ($actor, $supplierId, $docKey, $expectedDigest, $cleanNote): array {
            $lockedRows = ErpDeltaRow::query()
                ->where('supplier_id', $supplierId)
                ->where('doc_no', $docKey)
                ->orderBy('item_code')
                ->lockForUpdate()
                ->get();

            if ($lockedRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'doc_no' => __('local_procurement.delta_sync.reconciliation.not_found'),
                ]);
            }

            $actualDigest = $this->computeCompositeDigest($lockedRows);
            if (! hash_equals($actualDigest, strtolower(trim($expectedDigest)))) {
                abort(409, __('local_procurement.delta_sync.reconciliation.stale_digest'));
            }

            $po = $this->findLockedTargetLocalPo($supplierId, $docKey);
            $erpTotal = $this->sumDocumentAmount($lockedRows);
            $erpStatus = $this->resolveDocumentErpStatus($lockedRows);
            $mappedStatus = $this->mapErpStatusToLocalPo($erpStatus);

            $before = [
                'total_amount' => (string) $po->total_amount,
                'status' => (string) $po->status,
            ];

            $po = $this->applyDomainMutations($actor, $po, $erpTotal, $mappedStatus);

            $timestamp = now();
            ErpDeltaRow::query()
                ->where('supplier_id', $supplierId)
                ->where('doc_no', $docKey)
                ->update([
                    'reconciliation_status' => ErpDeltaRow::RECONCILIATION_APPLIED,
                    'matched_local_po_id' => $po->id,
                    'applied_at' => $timestamp,
                    'applied_by' => $actor->id,
                    'ignored_at' => null,
                    'ignored_by' => null,
                    'apply_note' => $cleanNote,
                    'updated_at' => $timestamp,
                ]);

            $this->audit->record(
                $po,
                'erp_delta_applied',
                $actor,
                $before,
                ['total_amount' => (string) $po->total_amount, 'status' => (string) $po->status],
                [
                    'doc_no' => $docKey,
                    'composite_digest' => $actualDigest,
                    'line_count' => $lockedRows->count(),
                    'note' => $cleanNote,
                ],
            );

            return [
                'doc_no' => $docKey,
                'po_id' => $po->id,
                'po_number' => $po->po_number,
                'total_amount' => Money::normalize($po->total_amount),
                'status' => (string) $po->status,
                'applied_count' => $lockedRows->count(),
            ];
        });
    }

    /**
     * Mark all rows for a document as ignored at the verified composite digest.
     *
     * @return array<string, mixed>
     */
    public function ignoreDocument(
        User $actor,
        int $supplierId,
        string $docNo,
        string $expectedDigest,
        ?string $note = null,
    ): array {
        $docKey = $this->normalizeDocKey($docNo);
        $cleanNote = $this->normalizeNote($note);

        return DB::transaction(function () use ($actor, $supplierId, $docKey, $expectedDigest, $cleanNote): array {
            $lockedRows = ErpDeltaRow::query()
                ->where('supplier_id', $supplierId)
                ->where('doc_no', $docKey)
                ->orderBy('item_code')
                ->lockForUpdate()
                ->get();

            if ($lockedRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'doc_no' => __('local_procurement.delta_sync.reconciliation.not_found'),
                ]);
            }

            $actualDigest = $this->computeCompositeDigest($lockedRows);
            if (! hash_equals($actualDigest, strtolower(trim($expectedDigest)))) {
                abort(409, __('local_procurement.delta_sync.reconciliation.stale_digest'));
            }

            $timestamp = now();
            ErpDeltaRow::query()
                ->where('supplier_id', $supplierId)
                ->where('doc_no', $docKey)
                ->update([
                    'reconciliation_status' => ErpDeltaRow::RECONCILIATION_IGNORED,
                    'ignored_at' => $timestamp,
                    'ignored_by' => $actor->id,
                    'applied_at' => null,
                    'applied_by' => null,
                    'apply_note' => $cleanNote,
                    'updated_at' => $timestamp,
                ]);

            $localPo = LocalPurchaseOrder::query()
                ->where('supplier_id', $supplierId)
                ->whereRaw('LOWER(po_number) = ?', [mb_strtolower($docKey, 'UTF-8')])
                ->first();

            if ($localPo instanceof LocalPurchaseOrder) {
                $this->audit->record(
                    $localPo,
                    'erp_delta_ignored',
                    $actor,
                    null,
                    null,
                    [
                        'doc_no' => $docKey,
                        'composite_digest' => $actualDigest,
                        'line_count' => $lockedRows->count(),
                        'note' => $cleanNote,
                    ],
                );
            }

            return [
                'doc_no' => $docKey,
                'ignored_count' => $lockedRows->count(),
            ];
        });
    }

    /**
     * @param  list<string>  $lowerDocNos
     * @return Collection<string, LocalPurchaseOrder>
     */
    private function loadLocalPosForDocs(int $supplierId, array $lowerDocNos): Collection
    {
        if ($lowerDocNos === []) {
            return collect();
        }

        return LocalPurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn(DB::raw('LOWER(po_number)'), $lowerDocNos)
            ->withSum([
                'invoices as active_invoiced_amount' => fn ($query) => $query
                    ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED]),
            ], 'invoice_amount')
            ->withExists([
                'goodsReceipts as has_blocked_gr' => fn ($query) => $query
                    ->whereIn('status', [LocalGoodsReceipt::STATUS_RESERVED, LocalGoodsReceipt::STATUS_INVOICED]),
            ])
            ->get()
            ->keyBy(fn (LocalPurchaseOrder $po): string => mb_strtolower($po->po_number, 'UTF-8'));
    }

    /**
     * @param  Collection<string, LocalPurchaseOrder>  $localPos
     * @return Collection<int, int>
     */
    private function loadBlockedGrPoIds(Collection $localPos): Collection
    {
        $poIds = $localPos->pluck('id')->all();
        if ($poIds === []) {
            return collect();
        }

        return LocalInvoiceGoodsReceipt::query()
            ->whereIn('local_purchase_order_id', $poIds)
            ->whereIn('state', [LocalInvoiceGoodsReceipt::STATE_RESERVED, LocalInvoiceGoodsReceipt::STATE_CONSUMED])
            ->distinct()
            ->pluck('local_purchase_order_id')
            ->flip();
    }

    /**
     * @param  list<string>  $lowerDocNos
     * @return Collection<string, PurchaseOrder>
     */
    private function loadImportPosForDocs(int $supplierId, array $lowerDocNos): Collection
    {
        if ($lowerDocNos === []) {
            return collect();
        }

        return PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn(DB::raw('LOWER(po_number)'), $lowerDocNos)
            ->withCount('shipmentItems')
            ->with(['awards', 'quotations.items.prItem', 'quotations.exchange_rate'])
            ->get()
            ->keyBy(fn (PurchaseOrder $po): string => mb_strtolower($po->po_number, 'UTF-8'));
    }

    /**
     * @param  Collection<int, ErpDeltaRow>  $docRows
     * @param  Collection<int, int>  $blockedGrLinkPoIds
     * @return array<string, mixed>
     */
    private function buildDocumentProjection(
        string $docNo,
        Collection $docRows,
        ?LocalPurchaseOrder $localPo,
        Collection $blockedGrLinkPoIds,
        ?PurchaseOrder $importPo,
    ): array {
        $erpTotal = $this->sumDocumentAmount($docRows);
        $erpStatus = $this->resolveDocumentErpStatus($docRows);
        $mappedPoStatus = $this->mapErpStatusToLocalPo($erpStatus);
        $reconciliationStatus = $this->resolveGroupReconciliationStatus($docRows);
        $compositeDigest = $this->computeCompositeDigest($docRows);
        $lastUpdated = $docRows->max('updated_at');

        $base = [
            'doc_no' => $docNo,
            'line_count' => $docRows->count(),
            'erp_total_amount' => $erpTotal,
            'erp_status' => $erpStatus,
            'mapped_po_status' => $mappedPoStatus,
            'composite_digest' => $compositeDigest,
            'reconciliation_status' => $reconciliationStatus,
            'last_updated_at' => $lastUpdated?->toIso8601String(),
        ];

        if ($localPo instanceof LocalPurchaseOrder) {
            return [
                ...$base,
                ...$this->evaluateLocalPoDiff(
                    $localPo,
                    $blockedGrLinkPoIds,
                    $erpTotal,
                    $mappedPoStatus,
                    $reconciliationStatus,
                ),
            ];
        }

        if ($importPo instanceof PurchaseOrder) {
            $hasShipments = (int) ($importPo->shipment_items_count ?? 0) > 0;

            return [
                ...$base,
                'portal_domain' => 'import_po',
                'portal_po_id' => $importPo->id,
                'portal_po_number' => $importPo->po_number,
                'portal_total_amount' => Money::normalize($importPo->orderedAmountTotal()),
                'portal_status' => strtoupper((string) $importPo->status),
                'diff_kind' => $reconciliationStatus === ErpDeltaRow::RECONCILIATION_IGNORED ? 'ignored' : 'import_diff',
                'can_apply' => false,
                'block_reason' => $hasShipments ? 'import_shipment_active' : 'import_po_read_only',
            ];
        }

        return [
            ...$base,
            'portal_domain' => null,
            'portal_po_id' => null,
            'portal_po_number' => null,
            'portal_total_amount' => null,
            'portal_status' => null,
            'diff_kind' => $reconciliationStatus === ErpDeltaRow::RECONCILIATION_IGNORED ? 'ignored' : 'unmatched',
            'can_apply' => false,
            'block_reason' => 'unmatched_po',
        ];
    }

    /**
     * @param  Collection<int, int>  $blockedGrLinkPoIds
     * @return array<string, mixed>
     */
    private function evaluateLocalPoDiff(
        LocalPurchaseOrder $localPo,
        Collection $blockedGrLinkPoIds,
        string $erpTotal,
        ?string $mappedPoStatus,
        string $reconciliationStatus,
    ): array {
        $portalTotal = Money::normalize($localPo->total_amount);
        $portalStatus = (string) $localPo->status;
        $amountPositive = Money::compare($erpTotal, Money::ZERO, Money::SCALE) > 0;
        $amountDiff = $amountPositive && Money::compare($erpTotal, $portalTotal, Money::SCALE) !== 0;
        $statusDiff = $mappedPoStatus !== null && $mappedPoStatus !== $portalStatus;

        $blockReason = $this->detectLocalPoBlockReason(
            $localPo,
            $blockedGrLinkPoIds,
            $erpTotal,
            $mappedPoStatus,
            $amountDiff,
            $statusDiff,
        );

        $diffKind = match (true) {
            $reconciliationStatus === ErpDeltaRow::RECONCILIATION_APPLIED && ! $amountDiff && ! $statusDiff => 'applied',
            $reconciliationStatus === ErpDeltaRow::RECONCILIATION_IGNORED && ! $amountDiff && ! $statusDiff => 'ignored',
            $blockReason !== null => 'blocked',
            $amountDiff && $statusDiff => 'amount_and_status_diff',
            $amountDiff => 'amount_diff',
            $statusDiff => 'status_diff',
            default => 'matched',
        };

        $canApply = $reconciliationStatus === ErpDeltaRow::RECONCILIATION_PENDING
            && $blockReason === null
            && ($amountDiff || $statusDiff);

        return [
            'portal_domain' => 'local_po',
            'portal_po_id' => $localPo->id,
            'portal_po_number' => $localPo->po_number,
            'portal_total_amount' => $portalTotal,
            'portal_status' => $portalStatus,
            'diff_kind' => $diffKind,
            'can_apply' => $canApply,
            'block_reason' => $blockReason,
        ];
    }

    /**
     * @param  Collection<int, int>  $blockedGrLinkPoIds
     */
    private function detectLocalPoBlockReason(
        LocalPurchaseOrder $localPo,
        Collection $blockedGrLinkPoIds,
        string $erpTotal,
        ?string $mappedPoStatus,
        bool $amountDiff,
        bool $statusDiff,
    ): ?string {
        if ($amountDiff && $localPo->status === LocalPurchaseOrder::STATUS_CANCELLED) {
            return 'cancelled_edit';
        }

        if ($amountDiff) {
            $activeInvoiced = Money::normalize($localPo->active_invoiced_amount ?? 0);
            if (Money::compare($erpTotal, $activeInvoiced, Money::SCALE) < 0) {
                return 'ceiling_low';
            }
        }

        if ($statusDiff && $localPo->status !== LocalPurchaseOrder::STATUS_OPEN) {
            return 'non_open_po';
        }

        if ($statusDiff && $mappedPoStatus === LocalPurchaseOrder::STATUS_CANCELLED) {
            if ((bool) $localPo->has_blocked_gr || $blockedGrLinkPoIds->has($localPo->id)) {
                return 'reserved_cancel';
            }
        }

        return null;
    }

    private function findLockedTargetLocalPo(int $supplierId, string $docKey): LocalPurchaseOrder
    {
        $po = LocalPurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereRaw('LOWER(po_number) = ?', [mb_strtolower($docKey, 'UTF-8')])
            ->lockForUpdate()
            ->first();

        if ($po instanceof LocalPurchaseOrder) {
            return $po;
        }

        $isImportPo = PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereRaw('LOWER(po_number) = ?', [mb_strtolower($docKey, 'UTF-8')])
            ->exists();

        throw ValidationException::withMessages([
            'doc_no' => __($isImportPo
                ? 'local_procurement.delta_sync.reconciliation.block_import_po_read_only'
                : 'local_procurement.delta_sync.reconciliation.block_unmatched_po'),
        ]);
    }

    private function applyDomainMutations(
        User $actor,
        LocalPurchaseOrder $po,
        string $erpTotal,
        ?string $mappedStatus,
    ): LocalPurchaseOrder {
        $amountPositive = Money::compare($erpTotal, Money::ZERO, Money::SCALE) > 0;
        $amountDiff = $amountPositive && Money::compare($erpTotal, Money::normalize($po->total_amount), Money::SCALE) !== 0;

        if ($amountDiff) {
            $po = $this->masterService->updatePurchaseOrder($actor, $po, [
                'po_number' => $po->po_number,
                'supplier_id' => $po->supplier_id,
                'po_date' => $po->po_date?->format('Y-m-d') ?? BusinessTime::today()->toDateString(),
                'total_amount' => $erpTotal,
                'description' => $po->description,
            ]);
        }

        $statusDiff = $mappedStatus !== null && $mappedStatus !== (string) $po->status;
        if (! $statusDiff) {
            return $po;
        }

        if ($po->status !== LocalPurchaseOrder::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'status' => __('local_procurement.delta_sync.reconciliation.block_non_open_po'),
            ]);
        }

        if ($mappedStatus === LocalPurchaseOrder::STATUS_CLOSED) {
            return $this->masterService->closePurchaseOrder($actor, $po);
        }

        if ($mappedStatus === LocalPurchaseOrder::STATUS_CANCELLED) {
            return $this->masterService->cancelPurchaseOrder($actor, $po);
        }

        return $po;
    }

    /**
     * Sum line amounts (`qty * price`) via BCMath before rounding to Money::SCALE (2 dp).
     *
     * @param  iterable<int, ErpDeltaRow>  $rows
     */
    private function sumDocumentAmount(iterable $rows): string
    {
        $workingScale = 6;
        $total = Money::zero($workingScale);

        foreach ($rows as $row) {
            $lineAmount = bcmul(
                Money::normalize($row->qty, $workingScale),
                Money::normalize($row->price, $workingScale),
                $workingScale,
            );
            $total = bcadd($total, $lineAmount, $workingScale);
        }

        return Money::normalize($total, Money::SCALE);
    }

    /**
     * @param  Collection<int, ErpDeltaRow>  $docRows
     */
    private function resolveDocumentErpStatus(Collection $docRows): string
    {
        foreach ($docRows as $row) {
            $candidate = strtoupper(trim((string) $row->status));
            if ($candidate !== '' && $this->mapErpStatusToLocalPo($candidate) !== null) {
                return $candidate;
            }
        }

        return strtoupper(trim((string) ($docRows->first()?->status ?? '')));
    }

    /**
     * @param  Collection<int, ErpDeltaRow>  $docRows
     */
    private function resolveGroupReconciliationStatus(Collection $docRows): string
    {
        $statuses = $docRows
            ->pluck('reconciliation_status')
            ->filter(fn (mixed $s): bool => is_string($s) && $s !== '')
            ->unique()
            ->values();

        if ($statuses->contains(ErpDeltaRow::RECONCILIATION_PENDING) || $statuses->isEmpty()) {
            return ErpDeltaRow::RECONCILIATION_PENDING;
        }

        if ($statuses->contains(ErpDeltaRow::RECONCILIATION_APPLIED)) {
            return ErpDeltaRow::RECONCILIATION_APPLIED;
        }

        return ErpDeltaRow::RECONCILIATION_IGNORED;
    }

    private function normalizeDocKey(string $docNo): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $docNo) ?? trim($docNo);

        return mb_strtoupper($trimmed, 'UTF-8');
    }

    private function normalizeNote(?string $note): ?string
    {
        if (! is_string($note)) {
            return null;
        }
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $note) ?? trim($note);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 500, 'UTF-8');
    }
}
