<?php

namespace App\Jobs;

use App\Exceptions\ImportAttemptSuperseded;
use App\Models\LocalProcurementImport;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

class ProcessLocalProcurementImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 30;

    public string $runKey;

    public function __construct(public int $importId, public string $stage)
    {
        $this->runKey = (string) Str::uuid();
    }

    public function handle(LocalProcurementImportService $service): void
    {
        $import = LocalProcurementImport::find($this->importId);
        if (! $import) {
            return;
        }
        $previous = app()->getLocale();
        app()->setLocale($import->locale);
        try {
            if ($this->stage === 'preview') {
                $service->preview($this->importId, $this->runKey);
            } elseif ($this->stage === 'confirm') {
                $service->commit($this->importId, $this->runKey);
            } else {
                throw new \RuntimeException('Unsupported import stage.');
            }
        } catch (ImportAttemptSuperseded $exception) {
            // An older delivery must acknowledge quietly, never fail the newer attempt.
            return;
        } finally {
            app()->setLocale($previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        LocalProcurementImport::whereKey($this->importId)->where('owner_job_key', $this->runKey)
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))
            ->whereIn('status', ['QUEUED', 'READING', 'VALIDATING', 'IMPORTING'])
            ->update(['status' => 'FAILED', 'failure' => __('local_procurement.large_import.failed'), 'finished_at' => now(), 'lease_expires_at' => null]);
    }
}
