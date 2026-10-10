<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LocalPoDocumentService
{
    public function matchPo(User $supplier, string $filename): LocalPurchaseOrder
    {
        $candidate = trim(pathinfo($filename, PATHINFO_FILENAME));
        if ($candidate === '') {
            throw ValidationException::withMessages(['file' => __('local_procurement.document_validation.filename')]);
        }
        $matches = LocalPurchaseOrder::where('supplier_id', $supplier->id)
            ->where(fn ($query) => $query->where('po_number', $candidate)
                ->orWhereRaw('LOWER(TRIM(po_number)) = ?', [mb_strtolower($candidate)]))->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['file' => __('local_procurement.document_validation.'.($matches->isEmpty() ? 'unmatched' : 'ambiguous'),
                ['filename' => $filename, 'supplier' => $supplier->name])]);
        }

        return $matches->first();
    }

    public function authorizePublication(User $actor, User $supplier): void
    {
        Gate::forUser($actor)->authorize('uploadDocument', LocalPurchaseOrder::class);
        if (! $supplier->isLocalEligible()) {
            throw ValidationException::withMessages(['supplier_id' => __('local_procurement.validation.supplier_ineligible')]);
        }
    }

    public function uploadSinglePdf(User $actor, User $supplier, UploadedFile $file): LocalPurchaseOrder
    {
        $this->authorizePublication($actor, $supplier);
        $po = $this->matchPo($supplier, $file->getClientOriginalName());
        $path = 'attachments/'.now()->format('Y/m').'/'.Str::uuid().'.pdf'; // biz-time:ignore storage path
        $stored = app(FileInspectionService::class)->storeUpload($file, 'po_pdf', $path);
        try {
            return DB::transaction(function () use ($actor, $supplier, $po, $file, $stored) {
                $lockedSupplier = User::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                $this->authorizePublication($actor->fresh(), $lockedSupplier);
                $locked = LocalPurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
                if ((int) $locked->supplier_id !== (int) $supplier->id || $locked->po_number !== $po->po_number
                    || $this->matchPo($lockedSupplier, $file->getClientOriginalName())->id !== $locked->id) {
                    throw ValidationException::withMessages(['file' => __('po_documents.changed')]);
                }
                $attachment = $locked->attachments()->create([
                    'file_path' => $stored['file_path'], 'file_name' => $stored['file_name'], 'file_type' => $stored['file_type'],
                    'file_inspection_id' => $stored['file_inspection_id'], 'uploaded_by' => $actor->id,
                ]);
                app(LocalFinanceAuditService::class)->record($locked, 'po_document_uploaded', $actor, null,
                    ['attachment_id' => $attachment->id, 'file_name' => $stored['file_name']], ['source' => 'single_pdf']);

                return $locked;
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    public function uploadZip(User $actor, User $supplier, UploadedFile $zipFile, ?string $requestKey = null): array
    {
        $service = app(LocalPoDocumentBatchService::class);
        $batch = $service->start($actor, $supplier, $zipFile, $requestKey ?? (string) Str::uuid(), false, false);
        $service->process($batch->id, $batch->dispatch_token);
        $batch->refresh();
        if ($batch->status !== 'COMPLETED') {
            throw ValidationException::withMessages(['file' => __('po_documents.'.($batch->failure_code ?? 'failed'))]);
        }

        return ['count' => $batch->pdf_count, 'pos' => LocalPurchaseOrder::whereIn('id', $batch->entries()->pluck('local_purchase_order_id'))->get(), 'batch' => $batch];
    }
}
