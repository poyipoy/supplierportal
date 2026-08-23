<?php

namespace App\Jobs\Middleware;

use App\Services\ExportProgressService;
use Closure;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Jobs\AppendDataToSheet;
use Maatwebsite\Excel\Jobs\AppendPaginatedToSheet;
use Maatwebsite\Excel\Jobs\AppendQueryToSheet;
use Throwable;

class TrackExportChunkProgress
{
    public function __construct(private readonly int $exportJobId) {}

    public function handle(object $job, Closure $next): void
    {
        $next($job);

        [$chunkKey, $processedRows] = $this->chunkDetails($job);

        if ($chunkKey === null || $processedRows < 1) {
            return;
        }

        try {
            app(ExportProgressService::class)->recordChunk(
                $this->exportJobId,
                $chunkKey,
                $processedRows,
            );
        } catch (Throwable $exception) {
            // Progress reporting must never retry a chunk after its rows were written.
            Log::warning('Export chunk progress could not be recorded.', [
                'export_job_id' => $this->exportJobId,
                'chunk_key' => $chunkKey,
                'exception_class' => $exception::class,
            ]);
        }
    }

    /** @return array{0: string|null, 1: int} */
    private function chunkDetails(object $job): array
    {
        if ($job instanceof AppendQueryToSheet) {
            return [
                'query:'.$job->sheetIndex.':'.$job->page,
                max(0, (int) $job->chunkSize),
            ];
        }

        if ($job instanceof AppendPaginatedToSheet) {
            return [
                'query:'.$job->sheetIndex.':'.$job->page,
                max(0, (int) $job->perPage),
            ];
        }

        if ($job instanceof AppendDataToSheet) {
            $queueJob = $job->job ?? null;
            $queueJobId = is_object($queueJob) && method_exists($queueJob, 'getJobId')
                ? $queueJob->getJobId()
                : null;
            $fallbackKey = hash('sha256', serialize($job->data));

            return [
                'data:'.$job->sheetIndex.':'.($queueJobId ?: $fallbackKey),
                count($job->data),
            ];
        }

        return [null, 0];
    }
}
