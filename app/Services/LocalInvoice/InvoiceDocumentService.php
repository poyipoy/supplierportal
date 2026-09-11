<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoiceRevision;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class InvoiceDocumentService
{
    public function store(LocalInvoiceRevision $revision, User $actor, array $files, array &$written): void
    {
        $rules = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
        Validator::make($files, ['invoice' => $rules, 'tax_invoice' => $rules, 'supporting' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']])->validate();
        foreach (['invoice', 'tax_invoice', 'supporting'] as $type) {
            $file = $files[$type] ?? null;
            if (! $file) {
                continue;
            }
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
                'local_invoice_id' => $revision->local_invoice_id, 'document_type' => $type,
                'file_path' => $path, 'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'uploaded_by' => $actor->id,
            ]);
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
