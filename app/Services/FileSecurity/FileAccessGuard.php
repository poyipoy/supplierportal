<?php

namespace App\Services\FileSecurity;

use App\Models\FileInspection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FileAccessGuard
{
    public function classification(Model $document): string
    {
        if ($document->file_inspection_id) {
            $inspection = FileInspection::find($document->file_inspection_id);
            if ($inspection?->status === 'HISTORICAL_UNVERIFIED') {
                $table = $inspection->metadata['source_table'] ?? '';
                $id = $inspection->metadata['source_id'] ?? 0;
                if (! in_array($table, ['attachments', 'local_invoice_documents', 'ga_claim_documents', 'supplier_master_documents'], true)) {
                    return 'ERROR';
                }
                $max = DB::table('file_security_cutovers')->where('document_table', $table)->value('legacy_max_id');
                if ($max === null || $id < 1 || $id > $max || DB::table($table)->where('id', $id)->value('file_path') !== $document->file_path) {
                    return 'ERROR';
                }
            }

            return $inspection?->status ?? 'ERROR';
        }
        $boundary = DB::table('file_security_cutovers')->where('document_table', $document->getTable())->value('legacy_max_id');

        return $boundary !== null && $document->exists && $document->getKey() > 0 && $document->getKey() <= $boundary
            ? 'HISTORICAL_UNVERIFIED' : 'ERROR';
    }

    public function inheritedInspection(Model $document): ?int
    {
        $this->assertReadable($document);
        if ($document->file_inspection_id) {
            return $document->file_inspection_id;
        }
        $path = Storage::disk('private')->path($document->file_path);
        abort_unless(is_file($path), 404);
        $inspection = FileInspection::firstOrCreate(['file_path' => $document->file_path], [
            'profile' => 'legacy', 'status' => 'HISTORICAL_UNVERIFIED', 'policy_version' => 'historical',
            'file_size' => filesize($path), 'file_type' => $document->mime_type ?: 'application/octet-stream',
            'sha256' => hash_file('sha256', $path),
            'metadata' => ['source_table' => $document->getTable(), 'source_id' => $document->getKey()],
        ]);

        return $inspection->id;
    }

    public function assertReadable(Model $document): void
    {
        abort_unless(is_file(Storage::disk('private')->path($document->file_path)), 404);
        $classification = $this->classification($document);
        abort_unless(in_array($classification, ['VALIDATED', 'HISTORICAL_UNVERIFIED'], true), 404);
        if ($document->file_inspection_id) {
            $inspection = FileInspection::findOrFail($document->file_inspection_id);
            abort_unless($inspection->file_path === $document->file_path, 404);
            if (isset($inspection->metadata['po_document_batch_id'])) {
                abort_unless(DB::table('local_po_document_batches')->where('id', $inspection->metadata['po_document_batch_id'])->value('status') === 'COMPLETED', 404);
            }
            $path = Storage::disk('private')->path($inspection->file_path);
            abort_unless(is_file($path) && filesize($path) === $inspection->file_size
                && hash_equals($inspection->sha256, (string) hash_file('sha256', $path)), 404);
        }
    }
}
