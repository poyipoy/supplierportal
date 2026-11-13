<?php

namespace App\Services\LocalInvoice;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\FileSecurity\NativeArchiveInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Official Goods Receipt documents (PDF) linked to a registered local GR.
 *
 * Files are admitted through the native inspection pipeline, published in one
 * transaction (all or nothing) and appended as polymorphic attachments; earlier
 * versions are kept and the newest is shown. Unlike PO documents, ZIP uploads are
 * processed synchronously inside the existing native archive budgets.
 */
class LocalGrDocumentService
{
    private const PROFILE = 'gr_pdf';

    public function authorizeOperator(User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', LocalPurchaseOrder::class);
    }

    public function authorizePublication(User $actor, LocalPurchaseOrder $po): void
    {
        $this->authorizeOperator($actor);
        if ($po->status === LocalPurchaseOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['purchase_order' => __('local_procurement.gr_documents.po_cancelled_selected')]);
        }
        $supplier = $po->loadMissing('supplier')->supplier;
        if (! $supplier || ! $supplier->is_active || ! $supplier->isLocalEligible()) {
            throw ValidationException::withMessages(['purchase_order' => __('local_procurement.validation.supplier_ineligible')]);
        }
    }

    /** Locks the PO row and re-authorizes on its latest state, so a cancel or supplier change in between is honoured. */
    private function lockPurchaseOrder(User $actor, LocalPurchaseOrder $po): LocalPurchaseOrder
    {
        $locked = LocalPurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
        $this->authorizePublication($actor->fresh(), $locked);

        return $locked;
    }

    public function matchGr(LocalPurchaseOrder $po, string $filename): LocalGoodsReceipt
    {
        $candidate = trim(pathinfo($filename, PATHINFO_FILENAME));
        if ($candidate === '') {
            throw ValidationException::withMessages(['file' => __('local_procurement.gr_documents.filename')]);
        }
        $matches = $po->goodsReceipts()
            ->where(fn ($query) => $query->where('gr_number', $candidate)
                ->orWhereRaw('LOWER(TRIM(gr_number)) = ?', [mb_strtolower($candidate)]))
            ->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['file' => __('local_procurement.gr_documents.'.($matches->isEmpty() ? 'unmatched' : 'ambiguous'),
                ['filename' => $filename, 'po' => $po->po_number])]);
        }

        return $matches->first();
    }

    public function assertAcceptsDocument(LocalGoodsReceipt $gr, string $field = 'file'): void
    {
        if ($gr->isCancelled()) {
            throw ValidationException::withMessages([$field => __('local_procurement.gr_documents.cancelled', ['number' => $gr->gr_number])]);
        }

        $po = $gr->relationLoaded('purchaseOrder') ? $gr->purchaseOrder : $gr->purchaseOrder()->first();
        if ($po && $po->status === LocalPurchaseOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages([$field => __('local_procurement.gr_documents.po_cancelled', ['number' => $gr->gr_number])]);
        }
    }

    /** Bulk entry point: the GR is resolved from the file name within the chosen purchase order. */
    public function uploadSinglePdf(User $actor, LocalPurchaseOrder $po, UploadedFile $file): LocalGoodsReceipt
    {
        $this->authorizePublication($actor, $po);
        $name = $file->getClientOriginalName();
        $this->assertAcceptsDocument($this->matchGr($po, $name));
        $stored = $this->storeUpload($file, 'file');

        try {
            return DB::transaction(function () use ($actor, $po, $name, $stored) {
                $lockedPo = $this->lockPurchaseOrder($actor, $po);
                $gr = $this->lockedMatch($lockedPo, $name);
                $this->publish($actor, $gr, $stored, ['source' => 'single_pdf', 'po_id' => $lockedPo->id]);

                return $gr;
            });
        } catch (\Throwable $exception) {
            $this->discard([$stored]);
            throw $exception;
        }
    }

    /** Row entry point: the GR is fixed by the route, the file name is free. */
    public function attachPdf(User $actor, LocalGoodsReceipt $goodsReceipt, UploadedFile $file): LocalGoodsReceipt
    {
        $this->authorizeOperator($actor);
        $this->assertAcceptsDocument($goodsReceipt);
        $stored = $this->storeUpload($file, 'file');

        try {
            return DB::transaction(function () use ($actor, $goodsReceipt, $stored) {
                $gr = $this->lockForPublication($goodsReceipt->id);
                $this->publish($actor, $gr, $stored, ['source' => 'row']);

                return $gr;
            });
        } catch (\Throwable $exception) {
            $this->discard([$stored]);
            throw $exception;
        }
    }

    /** Creates the GR and its document together, so a rejected file never leaves a GR behind. */
    public function createGoodsReceiptWithDocument(User $actor, LocalPurchaseOrder $purchaseOrder, array $data, UploadedFile $file, LocalProcurementMasterService $master): LocalGoodsReceipt
    {
        $this->authorizeOperator($actor);
        $stored = $this->storeUpload($file, 'document');

        try {
            return DB::transaction(function () use ($actor, $purchaseOrder, $data, $stored, $master) {
                $gr = $master->createGoodsReceipt($actor, $purchaseOrder, $data);
                $this->publish($actor, $gr, $stored, ['source' => 'create']);

                return $gr;
            });
        } catch (\Throwable $exception) {
            $this->discard([$stored]);
            throw $exception;
        }
    }

    /**
     * @return array{count: int, po: LocalPurchaseOrder}
     */
    public function uploadZip(User $actor, LocalPurchaseOrder $po, UploadedFile $zipFile): array
    {
        $this->authorizePublication($actor, $po);
        app(FileInspectionService::class)->inspectUpload($zipFile, 'po_zip');
        $archives = app(NativeArchiveInspector::class);
        $targets = [];
        $claimed = [];
        foreach ($archives->inspect($zipFile->getPathname()) as $entry) {
            if ($entry['ignored']) {
                continue;
            }
            $gr = $this->matchGr($po, $entry['name']);
            $this->assertAcceptsDocument($gr);
            if (isset($claimed[$gr->id])) {
                throw ValidationException::withMessages(['file' => __('local_procurement.gr_documents.zip_duplicate', ['gr' => $gr->gr_number])]);
            }
            $claimed[$gr->id] = true;
            $targets[$entry['entry_index']] = ['id' => $gr->id, 'name' => $entry['name']];
        }
        if ($targets === []) {
            throw ValidationException::withMessages(['file' => __('local_procurement.document_validation.zip_no_pdf')]);
        }

        $staging = Storage::disk('private')->path('gr-documents/staging/'.Str::uuid());
        File::ensureDirectoryExists($staging);
        $stored = [];

        try {
            foreach ($archives->extractPdfArchive($zipFile->getPathname(), $staging, null, array_keys($targets)) as $file) {
                $target = $targets[$file['entry_index']];
                $stored[$target['id']] = ['name' => $target['name'], 'file' => $this->storeFromPath($file['path'], $target['name'])];
            }
            // Ascending GR id keeps row-lock order identical across concurrent publications.
            ksort($stored);
            DB::transaction(function () use ($actor, $po, $stored) {
                $lockedPo = $this->lockPurchaseOrder($actor, $po);
                foreach ($stored as $grId => $document) {
                    $this->publish($actor, $this->lockedMatch($lockedPo, $document['name'], (int) $grId), $document['file'], ['source' => 'zip', 'po_id' => $lockedPo->id]);
                }
            });
        } catch (\Throwable $exception) {
            $this->discard(array_column($stored, 'file'));
            throw $exception;
        } finally {
            File::deleteDirectory($staging);
        }

        return ['count' => count($targets), 'po' => $po];
    }

    /**
     * Resolve the GR, lock it, then re-verify the match on the locked (latest) row:
     * a plain re-read would use the transaction snapshot and could miss a concurrent rename or move.
     */
    private function lockedMatch(LocalPurchaseOrder $lockedPo, string $filename, ?int $expectedGrId = null): LocalGoodsReceipt
    {
        $id = $expectedGrId ?? $this->matchGr($lockedPo, $filename)->id;
        $gr = $this->lockForPublication($id);
        $candidate = mb_strtolower(trim(pathinfo($filename, PATHINFO_FILENAME)));
        if (mb_strtolower(trim($gr->gr_number)) !== $candidate
            || (int) $gr->local_purchase_order_id !== (int) $lockedPo->id) {
            throw ValidationException::withMessages(['file' => __('local_procurement.gr_documents.changed')]);
        }

        return $gr;
    }

    private function lockForPublication(int $id): LocalGoodsReceipt
    {
        $gr = LocalGoodsReceipt::whereKey($id)->lockForUpdate()->firstOrFail();
        $this->assertAcceptsDocument($gr);

        return $gr;
    }

    private function publish(User $actor, LocalGoodsReceipt $gr, array $stored, array $metadata): Attachment
    {
        $attachment = $gr->attachments()->create([
            'file_path' => $stored['file_path'], 'file_name' => $stored['file_name'], 'file_type' => $stored['file_type'],
            'file_inspection_id' => $stored['file_inspection_id'], 'uploaded_by' => $actor->id,
        ]);
        app(LocalFinanceAuditService::class)->record($gr, 'gr_document_uploaded', $actor, null,
            ['attachment_id' => $attachment->id, 'file_name' => $stored['file_name']], $metadata);

        return $attachment;
    }

    private function storeUpload(UploadedFile $file, string $field): array
    {
        return app(FileInspectionService::class)->storeUpload($file, self::PROFILE, $this->destination(), $field);
    }

    private function storeFromPath(string $path, string $name): array
    {
        return app(FileInspectionService::class)->storePath($path, $name, self::PROFILE, $this->destination());
    }

    private function destination(): string
    {
        return 'attachments/gr-documents/'.now()->format('Y/m').'/'.Str::uuid().'.pdf'; // biz-time:ignore storage path
    }

    /** Compensates files stored before a failed publication so no orphan stays readable. */
    private function discard(array $documents): void
    {
        foreach ($documents as $document) {
            try {
                Storage::disk('private')->delete($document['file_path']);
                FileInspection::whereKey($document['file_inspection_id'])->update(['status' => 'ERROR', 'error_code' => 'publication_failed']);
            } catch (\Throwable $failure) {
                report($failure);
            }
        }
    }
}
