<?php

namespace App\Jobs\Middleware;

use App\Models\ExportJob;
use Closure;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use RuntimeException;
use Throwable;

class StopCancelledExport
{
    public function __construct(private readonly int $exportJobId) {}

    public function handle(object $job, Closure $next): void
    {
        if ($this->isCancelled()) {
            $this->abort($job);

            return;
        }

        $next($job);

        // A cancellation can arrive while the current chunk is writing. Do
        // not allow this queue job to dispatch the next chained job in that
        // case; the current chunk remains the last cooperative unit of work.
        if ($this->isCancelled()) {
            $this->abort($job);
        }
    }

    private function isCancelled(): bool
    {
        return ExportJob::query()
            ->whereKey($this->exportJobId)
            ->value('status') === ExportJob::STATUS_CANCELLED;
    }

    private function abort(object $job): void
    {
        $this->deleteTemporaryFile($job);

        // Mark the queue job failed rather than merely deleting it. Laravel's
        // chain dispatcher otherwise considers a deleted command successful
        // and dispatches the next chunk. A failed queue command stops the
        // chain while the export record remains in its terminal cancelled
        // state. Unit fakes without a bound queue job fall back to delete().
        if (method_exists($job, 'fail') && property_exists($job, 'job') && $job->job !== null) {
            try {
                $job->fail(new RuntimeException('Export cancelled.'));

                return;
            } catch (Throwable $exception) {
                Log::warning('Cancelled export queue job could not be failed cleanly.', [
                    'export_job_id' => $this->exportJobId,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        if (method_exists($job, 'delete')) {
            $job->delete();
        }
    }

    private function deleteTemporaryFile(object $job): void
    {
        $temporaryFile = null;
        $reflection = new ReflectionObject($job);

        while ($reflection !== false) {
            if ($reflection->hasProperty('temporaryFile')) {
                $property = $reflection->getProperty('temporaryFile');
                $property->setAccessible(true);
                $temporaryFile = $property->getValue($job);
                break;
            }

            $reflection = $reflection->getParentClass();
        }

        if (! is_object($temporaryFile) || ! method_exists($temporaryFile, 'delete')) {
            return;
        }

        try {
            $temporaryFile->delete();
        } catch (Throwable $exception) {
            Log::warning('Cancelled export temporary-file cleanup failed.', [
                'export_job_id' => $this->exportJobId,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
