<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalProcurementImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Advisory worker telemetry. Only the committed database status proves success. */
final class LocalProcurementImportProgress
{
    private function key(LocalProcurementImport $import): string
    {
        return 'local-import-progress:'.$import->id.':'.$import->owner_job_key;
    }

    public function write(LocalProcurementImport $import, string $phase, int $current, int $total, string $execution, bool $onlyIfMissing = false): void
    {
        try {
            $value = ['owner' => $import->owner_job_key, 'attempt' => (int) $import->attempt,
                'execution' => $execution, 'phase' => $phase, 'current' => $current,
                'total' => $total, 'updated_at' => now()->toIso8601String()];
            $store = Cache::store('file');
            $stored = $onlyIfMissing
                ? $store->add($this->key($import), $value, 3600)
                : $store->put($this->key($import), $value, 3600);
            if (! $stored && ! $onlyIfMissing) {
                $this->warn('Import progress could not be published.', $import->id);
            }
        } catch (\Throwable $exception) {
            // Progress must never change the outcome of the financial transaction.
            $this->warn('Import progress storage is unavailable.', $import->id);
        }
    }

    public function forget(LocalProcurementImport $import): void
    {
        try {
            Cache::store('file')->forget($this->key($import));
        } catch (\Throwable $exception) {
            $this->warn('Import progress cleanup is unavailable.', $import->id);
        }
    }

    private function warn(string $message, int $importId): void
    {
        try {
            Log::warning($message, ['import_id' => $importId]);
        } catch (\Throwable $exception) {
            // Diagnostic storage is advisory too, including when the disk is full.
        }
    }

    public function read(LocalProcurementImport $import, ?string $status = null): array
    {
        $status ??= $import->status;
        $phase = $status;
        $current = (int) $import->processed_rows;
        $total = null;
        $execution = null;
        $updated = $import->updated_at?->toIso8601String();
        $mode = in_array($status, ['QUEUED', 'READING', 'VALIDATING', 'IMPORTING'], true) ? 'indeterminate' : 'none';
        $stale = false;
        if ($status === 'IMPORTING') {
            $phase = 'AWAITING_PROGRESS';
            try {
                $snapshot = Cache::store('file')->get($this->key($import));
                if (is_array($snapshot) && ($snapshot['owner'] ?? null) === $import->owner_job_key
                    && ($snapshot['attempt'] ?? null) === (int) $import->attempt
                    && in_array($snapshot['phase'] ?? null, ['WAITING_SAVE', 'PREPARING', 'SAVING', 'FINALIZING', 'RETRYING'], true)) {
                    $phase = $snapshot['phase'];
                    $current = max(0, (int) $snapshot['current']);
                    $total = max(0, (int) $snapshot['total']);
                    $execution = $snapshot['execution'];
                    $updated = $snapshot['updated_at'];
                    $stale = CarbonImmutable::parse($updated)->lt(now()->subSeconds(60));
                    if ($phase === 'SAVING' && $total > 0 && $current < $total) {
                        $mode = 'determinate';
                    }
                }
            } catch (\Throwable $exception) {
                // Missing telemetry is unknown progress, never an import failure.
            }
        } elseif ($status === 'COMPLETED') {
            $current = (int) (($import->summary['newPo'] ?? 0) + ($import->summary['existingPo'] ?? 0)
                + ($import->summary['newGr'] ?? 0) + ($import->summary['existingGr'] ?? 0));
            $total = $current;
            $mode = 'determinate';
        }

        return ['phase' => $phase, 'mode' => $mode, 'current' => $current, 'total' => $total,
            'percent' => $status === 'COMPLETED' ? 100 : ($mode === 'determinate' ? min(99, intdiv($current * 100, max(1, $total))) : null),
            'execution' => $execution, 'updated_at' => $updated, 'stale' => $stale];
    }
}
