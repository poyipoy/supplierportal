<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoiceDocument;
use App\Models\LocalInvoiceRevision;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InvoiceDocumentService
{
    public function store(
        LocalInvoiceRevision $revision,
        User $actor,
        array $files,
        array &$written,
        bool $requiresTaxInvoice = true,
        bool $requiresDeliveryNote = false,
        array $retainedDocIds = []
    ): void {
        if (isset($files['surat_jalan']) && ! isset($files['delivery_note'])) {
            $files['delivery_note'] = $files['surat_jalan'];
            unset($files['surat_jalan']);
        }

        $retainedDocs = ! empty($retainedDocIds)
            ? LocalInvoiceDocument::where('local_invoice_id', $revision->local_invoice_id)
                ->whereIn('id', $retainedDocIds)
                ->get()
            : collect();

        foreach ($retainedDocs as $doc) {
            $revision->documents()->create([
                'local_invoice_id' => $revision->local_invoice_id,
                'document_type' => $doc->document_type,
                'file_path' => $doc->file_path,
                'original_filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'file_size' => $doc->file_size,
                'uploaded_by' => $doc->uploaded_by,
            ]);
        }

        $baseRules = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

        foreach (['invoice', 'tax_invoice', 'delivery_note', 'supporting'] as $type) {
            $isRequired = match ($type) {
                'invoice' => true,
                'tax_invoice' => $requiresTaxInvoice,
                'delivery_note' => $requiresDeliveryNote,
                default => false,
            };

            $hasRetained = $retainedDocs->where('document_type', $type)->isNotEmpty();
            $uploaded = $files[$type] ?? null;

            if ($isRequired && ! $hasRetained && empty($uploaded)) {
                throw ValidationException::withMessages([
                    $type => "Berkas {$type} wajib diunggah.",
                ]);
            }

            if (empty($uploaded)) {
                continue;
            }

            $list = is_array($uploaded) ? $uploaded : [$uploaded];
            $retainedCount = $retainedDocs->where('document_type', $type)->count();

            if ($retainedCount + count($list) > 5) {
                throw ValidationException::withMessages([
                    $type => "Total berkas untuk {$type} melebihi batas maksimal 5 berkas.",
                ]);
            }

            foreach ($list as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                Validator::make(['file' => $file], ['file' => $baseRules])->validate();

                $path = 'local-invoices/'.$revision->local_invoice_id.'/revisions/'.$revision->revision_number.'/'.$file->hashName();
                $written[] = $path;
                $stream = fopen($file->getPathname(), 'r');
                if ($stream === false) {
                    throw new RuntimeException('Unable to read uploaded document.');
                }
                try {
                    if (! Storage::disk('private')->put($path, $stream)) {
                        throw new RuntimeException('Unable to store document.');
                    }
                } finally {
                    fclose($stream);
                }

                $revision->documents()->create([
                    'local_invoice_id' => $revision->local_invoice_id,
                    'document_type' => $type,
                    'file_path' => $path,
                    'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $actor->id,
                ]);
            }
        }
    }

    public function compensate(array $written): void
    {
        foreach ($written as $path) {
            try {
                if (! Storage::disk('private')->delete($path)) {
                    throw new RuntimeException('Document compensation failed.');
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
