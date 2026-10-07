<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class LocalPoDocumentService
{
    /**
     * Match a filename to an existing LocalPurchaseOrder belonging to the given supplier.
     *
     * @throws ValidationException
     */
    public function matchPo(User $supplier, string $filename): LocalPurchaseOrder
    {
        $poCandidate = trim(pathinfo($filename, PATHINFO_FILENAME));

        if ($poCandidate === '') {
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.filename'),
            ]);
        }

        $matches = LocalPurchaseOrder::where('supplier_id', $supplier->id)
            ->where(function ($q) use ($poCandidate) {
                $q->where('po_number', $poCandidate)
                    ->orWhereRaw('LOWER(TRIM(po_number)) = ?', [strtolower($poCandidate)]);
            })
            ->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.unmatched', ['filename' => $filename, 'supplier' => $supplier->name]),
            ]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.ambiguous', ['filename' => $filename, 'supplier' => $supplier->name]),
            ]);
        }

        return $matches->first();
    }

    /**
     * Upload and attach a single PO PDF document.
     *
     * @throws ValidationException
     */
    public function uploadSinglePdf(User $actor, User $supplier, UploadedFile $file): LocalPurchaseOrder
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'pdf') {
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.pdf_required', ['filename' => $file->getClientOriginalName()]),
            ]);
        }

        $handle = fopen($file->getPathname(), 'rb');
        $header = fread($handle, 5);
        fclose($handle);
        if ($header !== '%PDF-') {
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.pdf_invalid', ['filename' => $file->getClientOriginalName()]),
            ]);
        }

        $po = $this->matchPo($supplier, $file->getClientOriginalName());

        $written = [];
        $path = 'attachments/'.now()->format('Y/m').'/'.Str::uuid().'.pdf'; // biz-time:ignore storage path
        $stream = fopen($file->getPathname(), 'r');
        try {
            Storage::disk('private')->put($path, $stream);
            $written[] = $path;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            DB::transaction(function () use ($po, $path, $file, $actor) {
                $attachment = $po->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => 'application/pdf',
                    'uploaded_by' => $actor->id,
                ]);

                app(LocalFinanceAuditService::class)->record(
                    $po,
                    'po_document_uploaded',
                    $actor,
                    null,
                    ['attachment_id' => $attachment->id, 'file_name' => $file->getClientOriginalName()],
                    ['source' => 'single_pdf']
                );
            });

            return $po;
        } catch (\Throwable $e) {
            foreach ($written as $p) {
                Storage::disk('private')->delete($p);
            }
            throw $e;
        }
    }

    /**
     * Upload, safely extract, validate, and atomically attach PO PDFs from a ZIP archive.
     *
     * @return array{count: int, pos: Collection}
     *
     * @throws ValidationException
     */
    public function uploadZip(User $actor, User $supplier, UploadedFile $zipFile): array
    {
        $ext = strtolower($zipFile->getClientOriginalExtension());
        if ($ext !== 'zip') {
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.zip_required'),
            ]);
        }

        $tempDir = storage_path('app/private/temp/'.Str::uuid());
        File::ensureDirectoryExists($tempDir);

        $zip = new ZipArchive;
        $res = $zip->open($zipFile->getPathname());
        if ($res !== true) {
            File::deleteDirectory($tempDir);
            throw ValidationException::withMessages([
                'file' => __('local_procurement.document_validation.zip_invalid'),
            ]);
        }

        try {
            if ($zip->numFiles <= 0) {
                throw ValidationException::withMessages([
                    'file' => __('local_procurement.document_validation.zip_empty'),
                ]);
            }

            if ($zip->numFiles > 100) {
                throw ValidationException::withMessages([
                    'file' => __('local_procurement.document_validation.zip_count'),
                ]);
            }

            $maxTotalSize = 100 * 1024 * 1024; // 100 MB
            $totalSize = 0;

            // Step 1: Pre-extraction validation
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entryName = $stat['name'];

                // Path traversal checks
                if (
                    str_contains($entryName, '..')
                    || str_starts_with($entryName, '/')
                    || str_starts_with($entryName, '\\')
                    || preg_match('/^[a-zA-Z]:/', $entryName)
                ) {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.zip_path', ['filename' => $entryName]),
                    ]);
                }

                // Skip directories and Mac metadata
                if (str_ends_with($entryName, '/') || str_contains($entryName, '__MACOSX') || basename($entryName) === '.DS_Store') {
                    continue;
                }

                // Nested archive check
                $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                if (in_array($entryExt, ['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2'], true)) {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.zip_nested', ['filename' => $entryName]),
                    ]);
                }

                // Non-PDF check
                if ($entryExt !== 'pdf') {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.zip_pdf_only', ['filename' => $entryName]),
                    ]);
                }

                $totalSize += $stat['size'];
                if ($totalSize > $maxTotalSize) {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.zip_size'),
                    ]);
                }
            }

            // Step 2: Extraction to isolated staging
            $extractedFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entryName = $stat['name'];

                if (str_ends_with($entryName, '/') || str_contains($entryName, '__MACOSX') || basename($entryName) === '.DS_Store') {
                    continue;
                }

                $baseFilename = basename($entryName);
                $targetPath = $tempDir.DIRECTORY_SEPARATOR.$baseFilename;
                $stream = $zip->getStream($entryName);
                if (! $stream) {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.zip_extract', ['filename' => $entryName]),
                    ]);
                }
                file_put_contents($targetPath, stream_get_contents($stream));
                fclose($stream);

                $extractedFiles[] = ['path' => $targetPath, 'name' => $baseFilename];
            }

            $zip->close();
            $zip = null;

            if (empty($extractedFiles)) {
                throw ValidationException::withMessages([
                    'file' => __('local_procurement.document_validation.zip_no_pdf'),
                ]);
            }

            // Step 3: Validate PDF magic bytes and match all POs before committing
            $preflighted = [];
            $matchedPoIds = [];

            foreach ($extractedFiles as $fileInfo) {
                $fh = fopen($fileInfo['path'], 'rb');
                $magic = fread($fh, 5);
                fclose($fh);
                if ($magic !== '%PDF-') {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.pdf_invalid', ['filename' => $fileInfo['name']]),
                    ]);
                }

                $po = $this->matchPo($supplier, $fileInfo['name']);

                if (in_array($po->id, $matchedPoIds, true)) {
                    throw ValidationException::withMessages([
                        'file' => __('local_procurement.document_validation.zip_duplicate', ['po' => $po->po_number]),
                    ]);
                }
                $matchedPoIds[] = $po->id;

                $preflighted[] = [
                    'po' => $po,
                    'temp_path' => $fileInfo['path'],
                    'filename' => $fileInfo['name'],
                ];
            }

            // Step 4: Atomic storage and DB commit with compensation
            $written = [];
            $batchId = (string) Str::uuid();

            try {
                $staged = [];
                foreach ($preflighted as $item) {
                    $destPath = 'attachments/'.now()->format('Y/m').'/'.Str::uuid().'.pdf'; // biz-time:ignore storage path
                    $stream = fopen($item['temp_path'], 'r');
                    try {
                        Storage::disk('private')->put($destPath, $stream);
                        $written[] = $destPath;
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    $staged[] = [
                        'po' => $item['po'],
                        'dest_path' => $destPath,
                        'filename' => $item['filename'],
                    ];
                }

                DB::transaction(function () use ($staged, $actor, $batchId) {
                    foreach ($staged as $item) {
                        $po = $item['po'];
                        $attachment = $po->attachments()->create([
                            'file_path' => $item['dest_path'],
                            'file_name' => $item['filename'],
                            'file_type' => 'application/pdf',
                            'uploaded_by' => $actor->id,
                        ]);

                        app(LocalFinanceAuditService::class)->record(
                            $po,
                            'po_document_uploaded',
                            $actor,
                            null,
                            ['attachment_id' => $attachment->id, 'file_name' => $item['filename']],
                            ['batch' => $batchId, 'source' => 'zip']
                        );
                    }
                });

                return [
                    'count' => count($staged),
                    'pos' => collect($staged)->pluck('po'),
                ];
            } catch (\Throwable $e) {
                foreach ($written as $p) {
                    Storage::disk('private')->delete($p);
                }
                throw $e;
            }
        } finally {
            if ($zip instanceof ZipArchive) {
                @$zip->close();
            }
            File::deleteDirectory($tempDir);
        }
    }
}
