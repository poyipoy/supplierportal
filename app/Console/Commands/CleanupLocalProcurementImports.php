<?php

namespace App\Console\Commands;

use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalProcurementImport;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class CleanupLocalProcurementImports extends Command
{
    protected $signature = 'imports:cleanup';

    protected $description = 'Expire previews and remove terminal import staging and private uploads.';

    public function handle(): int
    {
        LocalProcurementImport::whereNotIn('kind', LocalProcurementImport::KINDS)->whereIn('status', LocalProcurementImport::ACTIVE)
            ->eachById(fn ($import) => app(LocalProcurementImportService::class)->retireUnsupported($import->id));
        LocalProcurementImport::whereIn('status', ['QUEUED', 'READY'])->where('expires_at', '<', now())
            ->update(['status' => 'EXPIRED', 'finished_at' => now()]);
        // Queue retries own recovery while a live or recently reserved job exists.
        LocalProcurementImport::whereIn('status', ['READING', 'VALIDATING'])->where('lease_expires_at', '<', now()->subSeconds(660))
            ->eachById(function ($candidate) {
                DB::transaction(function () use ($candidate) {
                    $import = LocalProcurementImport::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($import->status, ['READING', 'VALIDATING'], true) || ! $import->lease_expires_at?->lt(now()->subSeconds(660))) {
                        return;
                    }
                    $job = new ProcessLocalProcurementImport($import->id, 'preview');
                    $import->update(['status' => 'QUEUED', 'lease_expires_at' => null, 'attempt' => $import->attempt + 1, 'owner_job_key' => $job->runKey]);
                    dispatch($job->onQueue(config('local_procurement_imports.queue')));
                });
            });
        LocalProcurementImport::whereNotIn('status', LocalProcurementImport::ACTIVE)->whereNull('cleaned_at')
            ->where('finished_at', '<', now()->subDays((int) config('local_procurement_imports.retention_days')))
            ->eachById(function ($candidate) {
                DB::transaction(function () use ($candidate) {
                    $import = LocalProcurementImport::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if (in_array($import->status, LocalProcurementImport::ACTIVE, true)) {
                        return;
                    }
                    foreach ($import->attachments as $attachment) {
                        Storage::disk('private')->delete($attachment->file_path);
                        $attachment->delete();
                    }
                    DB::table('local_procurement_import_rows')->where('import_id', $import->id)->delete();
                    DB::table('local_procurement_import_records')->where('import_id', $import->id)->delete();
                    $import->update(['cleaned_at' => now()]);
                });
            });
        if (! LocalProcurementImport::where('status', 'READING')->exists()) {
            $root = storage_path('app/private/imports/reader-cache');
            if (is_dir($root)) {
                $resolvedRoot = realpath($root).DIRECTORY_SEPARATOR;
                foreach (File::directories($root) as $directory) {
                    $resolved = realpath($directory);
                    if ($resolved && ! is_link($directory) && str_starts_with($resolved.DIRECTORY_SEPARATOR, $resolvedRoot)
                        && File::lastModified($directory) < now()->subDays(3)->getTimestamp()) {
                        File::deleteDirectory($directory);
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
