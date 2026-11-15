<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Erp\DeltaSyncImportRequest;
use App\Http\Requests\Erp\ReconcileErpDocumentRequest;
use App\Models\ErpDeltaRow;
use App\Models\LocalPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Erp\CanonicalRowHasher;
use App\Services\Erp\ErpDeltaReconciliationService;
use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ErpDeltaImportController extends Controller
{
    public function hashes(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $targetSupplier = $this->resolveTargetSupplierFromQuery($request, $actor);

        Gate::authorize('viewHashes', [ErpDeltaRow::class, $targetSupplier]);

        $hashes = ErpDeltaRow::query()
            ->where('supplier_id', $targetSupplier->id)
            ->orderBy('id')
            ->pluck('row_hash')
            ->values()
            ->all();

        return response()
            ->json(['hashes' => $hashes])
            ->header('Cache-Control', 'private, no-store');
    }

    public function sync(DeltaSyncImportRequest $request, FileInspectionService $inspectionService): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        $targetSupplier = $actor->isSupplier()
            ? $actor
            : User::query()->findOrFail((int) $validated['supplier_id']);

        Gate::authorize('sync', [ErpDeltaRow::class, $targetSupplier]);

        $normalizedByCompositeKey = [];
        $docNoVariants = [];

        foreach ($validated['rows'] as $row) {
            $docNo = $this->normalizeUpper((string) $row['doc_no']);
            $itemCode = $this->normalizeUpper((string) $row['item_code']);
            $status = $this->normalizeUpper((string) $row['status']);
            $qtyNorm = CanonicalRowHasher::normalizeDecimal($row['qty'], 4);
            $priceNorm = CanonicalRowHasher::normalizeDecimal($row['price'], 2);

            // Recompute canonical xxh3 digest on the server; client hashes are never trusted.
            $serverHash = CanonicalRowHasher::hash($docNo, $itemCode, $qtyNorm, $priceNorm, $status);

            $rawTrimmedDocNo = $this->trimUnicode((string) $row['doc_no']);
            $docNoVariants[$docNo] = $docNo;
            $docNoVariants[$rawTrimmedDocNo] = $rawTrimmedDocNo;

            $normalizedByCompositeKey[$docNo."\0".$itemCode] = [
                'supplier_id' => $targetSupplier->id,
                'doc_no' => $docNo,
                'item_code' => $itemCode,
                'qty' => $qtyNorm,
                'price' => $priceNorm,
                'status' => $status,
                'row_hash' => $serverHash,
                'last_synced_by' => $actor->id,
            ];
        }

        $documentSha256 = null;
        if ($request->hasFile('document')) {
            $inspected = $inspectionService->inspectUpload($request->file('document'), 'po_pdf', 'document');
            $documentSha256 = $inspected->sha256;
        }

        $uniqueDocNos = array_values($docNoVariants);

        $syncedRows = DB::transaction(function () use ($targetSupplier, $normalizedByCompositeKey, $uniqueDocNos): int {
            $foreignImportPoExists = PurchaseOrder::withTrashed()
                ->whereIn('po_number', $uniqueDocNos)
                ->where('supplier_id', '!=', $targetSupplier->id)
                ->lockForUpdate()
                ->exists();

            if ($foreignImportPoExists) {
                abort(403);
            }

            $foreignLocalPoExists = LocalPurchaseOrder::query()
                ->whereIn('po_number', $uniqueDocNos)
                ->where('supplier_id', '!=', $targetSupplier->id)
                ->lockForUpdate()
                ->exists();

            if ($foreignLocalPoExists) {
                abort(403);
            }

            $foreignDeltaExists = ErpDeltaRow::query()
                ->whereIn('doc_no', $uniqueDocNos)
                ->where('supplier_id', '!=', $targetSupplier->id)
                ->lockForUpdate()
                ->exists();

            if ($foreignDeltaExists) {
                abort(403);
            }

            // Lock existing target supplier delta rows so unchanged hashes preserve prior decisions
            // while modified rows automatically re-enter pending reconciliation.
            $existingRows = ErpDeltaRow::query()
                ->where('supplier_id', $targetSupplier->id)
                ->whereIn('doc_no', $uniqueDocNos)
                ->lockForUpdate()
                ->get([
                    'doc_no',
                    'item_code',
                    'row_hash',
                    'reconciliation_status',
                    'matched_local_po_id',
                    'applied_at',
                    'applied_by',
                    'ignored_at',
                    'ignored_by',
                    'apply_note',
                ])
                ->keyBy(static fn (ErpDeltaRow $existing): string => $existing->doc_no."\0".$existing->item_code);

            $timestamp = now();
            $upsertPayload = array_map(
                static function (array $row) use ($existingRows, $timestamp): array {
                    $existing = $existingRows->get($row['doc_no']."\0".$row['item_code']);
                    $unchanged = $existing instanceof ErpDeltaRow
                        && hash_equals((string) $existing->row_hash, (string) $row['row_hash']);

                    $reconciliationFields = $unchanged
                        ? [
                            'reconciliation_status' => $existing->reconciliation_status,
                            'matched_local_po_id' => $existing->matched_local_po_id,
                            'applied_at' => $existing->applied_at,
                            'applied_by' => $existing->applied_by,
                            'ignored_at' => $existing->ignored_at,
                            'ignored_by' => $existing->ignored_by,
                            'apply_note' => $existing->apply_note,
                        ]
                        : [
                            'reconciliation_status' => ErpDeltaRow::RECONCILIATION_PENDING,
                            'matched_local_po_id' => null,
                            'applied_at' => null,
                            'applied_by' => null,
                            'ignored_at' => null,
                            'ignored_by' => null,
                            'apply_note' => null,
                        ];

                    return [
                        ...$row,
                        ...$reconciliationFields,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                },
                array_values($normalizedByCompositeKey)
            );

            ErpDeltaRow::query()->upsert(
                $upsertPayload,
                ['supplier_id', 'doc_no', 'item_code'],
                [
                    'qty',
                    'price',
                    'status',
                    'row_hash',
                    'last_synced_by',
                    'reconciliation_status',
                    'matched_local_po_id',
                    'applied_at',
                    'applied_by',
                    'ignored_at',
                    'ignored_by',
                    'apply_note',
                    'updated_at',
                ]
            );

            return count($upsertPayload);
        });

        return response()
            ->json([
                'synced_count' => $syncedRows,
                'document_sha256' => $documentSha256,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function reconciliation(Request $request, ErpDeltaReconciliationService $reconciliationService): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $targetSupplier = $this->resolveTargetSupplierFromQuery($request, $actor);

        Gate::authorize('reconcile', [ErpDeltaRow::class, $targetSupplier]);

        return response()
            ->json([
                'supplier_id' => $targetSupplier->id,
                'can_apply' => $actor->can('apply', [ErpDeltaRow::class, $targetSupplier]),
                'documents' => $reconciliationService->projectDocumentsForSupplier($targetSupplier->id),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function applyDocument(
        ReconcileErpDocumentRequest $request,
        ErpDeltaReconciliationService $reconciliationService,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $targetSupplier = User::query()->findOrFail((int) $validated['supplier_id']);

        Gate::authorize('apply', [ErpDeltaRow::class, $targetSupplier]);

        $result = $reconciliationService->applyDocument(
            actor: $actor,
            supplierId: $targetSupplier->id,
            docNo: (string) $validated['doc_no'],
            expectedDigest: (string) $validated['expected_digest'],
            note: $validated['note'] ?? null,
        );

        return response()
            ->json($result)
            ->header('Cache-Control', 'private, no-store');
    }

    public function ignoreDocument(
        ReconcileErpDocumentRequest $request,
        ErpDeltaReconciliationService $reconciliationService,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();
        $targetSupplier = User::query()->findOrFail((int) $validated['supplier_id']);

        Gate::authorize('ignore', [ErpDeltaRow::class, $targetSupplier]);

        $result = $reconciliationService->ignoreDocument(
            actor: $actor,
            supplierId: $targetSupplier->id,
            docNo: (string) $validated['doc_no'],
            expectedDigest: (string) $validated['expected_digest'],
            note: $validated['note'] ?? null,
        );

        return response()
            ->json($result)
            ->header('Cache-Control', 'private, no-store');
    }

    private function resolveTargetSupplierFromQuery(Request $request, User $actor): User
    {
        if ($actor->isSupplier()) {
            if ($request->filled('supplier_id')) {
                $requestedId = $request->input('supplier_id');
                $matchesSelf = (is_numeric($requestedId) && (int) $requestedId === (int) $actor->id)
                    || (is_string($requestedId) && $requestedId === (string) $actor->getRouteKey());

                if (! $matchesSelf) {
                    abort(403);
                }
            }

            return $actor;
        }

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return User::query()->findOrFail((int) $validated['supplier_id']);
    }

    private function normalizeUpper(string $value): string
    {
        return mb_strtoupper($this->trimUnicode($value), 'UTF-8');
    }

    private function trimUnicode(string $value): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $value) ?? trim($value);
    }
}
