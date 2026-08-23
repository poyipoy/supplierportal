<?php

namespace App\Services;

use App\Events\ExportProgressUpdated;
use App\Models\ExportJob;
use App\Support\ExportDispatcher;
use App\Support\NotificationCategory;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExportProgressService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Atomically hand an export record to Laravel's database queue.
     *
     * The record transition and the root queue-job insert share one database
     * transaction. A worker termination before commit therefore leaves both
     * absent, while a committed processing state always has a durable root job.
     */
    public function handoffToExportQueue(
        ExportJob $record,
        string $path,
        int $totalRows,
        Closure $enqueue,
    ): ?ExportJob {
        ExportDispatcher::assertAtomicQueueConfiguration($record);
        $totalRows = max(0, $totalRows);

        $claimed = $record->getConnection()->transaction(function () use ($record, $path, $totalRows, $enqueue): ?ExportJob {
            $locked = ExportJob::query()
                ->lockForUpdate()
                ->find($record->getKey());

            if ($locked === null || $locked->status !== ExportJob::STATUS_QUEUED) {
                return null;
            }

            $locked->forceFill([
                'status' => ExportJob::STATUS_PROCESSING,
                'progress_stage' => $totalRows === 0
                    ? ExportJob::STAGE_FINALIZING
                    : ExportJob::STAGE_GENERATING,
                'progress' => $totalRows === 0 ? 100 : 0,
                'total_rows' => $totalRows,
                'processed_rows' => 0,
                'processed_chunks' => [],
                'file_path' => $path,
                'error_message' => null,
                'completed_at' => null,
                'expires_at' => null,
            ])->save();

            // PendingDispatch must be forced by the caller before this closure
            // returns so the database queue insert belongs to this transaction.
            $enqueue();

            return $locked;
        }, 3);

        if ($claimed !== null) {
            // The testing/local sync driver may finish the entire chain before
            // returning. Broadcast the committed current state, never a stale
            // processing snapshot.
            $claimed->refresh();
            $this->broadcast($claimed);
        }

        return $claimed;
    }

    public function prepare(ExportJob $record, string $path): ?ExportJob
    {
        $claimed = ExportJob::query()
            ->whereKey($record->getKey())
            ->where('status', ExportJob::STATUS_QUEUED)
            ->update([
                'status' => ExportJob::STATUS_PROCESSING,
                'progress_stage' => ExportJob::STAGE_PREPARING,
                'progress' => 0,
                'total_rows' => 0,
                'processed_rows' => 0,
                'processed_chunks' => [],
                'file_path' => $path,
                'error_message' => null,
                'completed_at' => null,
                'expires_at' => null,
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $record->refresh();

        $this->broadcast($record);

        return $record;
    }

    public function startGenerating(ExportJob $record, int $totalRows): ExportJob
    {
        $totalRows = max(0, $totalRows);

        $record->forceFill([
            'progress_stage' => $totalRows === 0
                ? ExportJob::STAGE_FINALIZING
                : ExportJob::STAGE_GENERATING,
            'progress' => $totalRows === 0 ? 100 : 0,
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'processed_chunks' => [],
        ])->save();

        $this->broadcast($record);

        return $record;
    }

    public function recordChunk(int $exportJobId, string $chunkKey, int $processedRows): void
    {
        $record = DB::transaction(function () use ($exportJobId, $chunkKey, $processedRows): ?ExportJob {
            $record = ExportJob::query()->lockForUpdate()->find($exportJobId);

            if ($record === null || ! $record->isPending()) {
                return null;
            }

            $processedChunks = $record->processed_chunks ?? [];
            if (in_array($chunkKey, $processedChunks, true)) {
                return null;
            }

            $processedChunks[] = $chunkKey;
            $totalRows = max(0, (int) $record->total_rows);
            $completedRows = min(
                $totalRows,
                max(0, (int) $record->processed_rows) + max(0, $processedRows),
            );
            $progress = $totalRows > 0
                ? min(100, (int) floor(($completedRows / $totalRows) * 100))
                : 100;

            $record->forceFill([
                'progress_stage' => $completedRows >= $totalRows
                    ? ExportJob::STAGE_FINALIZING
                    : ExportJob::STAGE_GENERATING,
                'progress' => $progress,
                'processed_rows' => $completedRows,
                'processed_chunks' => $processedChunks,
            ])->save();

            return $record;
        }, 3);

        if ($record !== null) {
            $this->broadcast($record);
        }
    }

    public function complete(int $exportJobId): void
    {
        $record = DB::transaction(function () use ($exportJobId): ?ExportJob {
            $record = ExportJob::with('user')->lockForUpdate()->find($exportJobId);

            if (
                $record === null
                || in_array($record->status, [ExportJob::STATUS_COMPLETED, ExportJob::STATUS_FAILED], true)
            ) {
                return null;
            }

            if (! $record->hasSafeFilePath() || ! Storage::disk($record->disk)->exists($record->file_path)) {
                throw new RuntimeException('The export file could not be stored.');
            }

            $record->forceFill([
                'status' => ExportJob::STATUS_COMPLETED,
                'progress_stage' => ExportJob::STAGE_COMPLETED,
                'progress' => 100,
                'processed_rows' => $record->total_rows,
                'completed_at' => now(),
                'expires_at' => now()->addDays(3),
                'error_message' => null,
            ])->save();

            return $record;
        }, 3);

        if ($record === null) {
            return;
        }

        $this->broadcast($record);

        if ($record->user !== null) {
            $this->notifications->send(
                $record->user,
                'export.completed',
                'export:'.$record->getKey().':completed',
                'Export Completed',
                'Export :label is ready to download.',
                route('exports.download', $record, absolute: false),
                'file-spreadsheet',
                [
                    'category' => NotificationCategory::OTHER,
                    'export_job_id' => $record->getRouteKey(),
                ],
                ['label' => $record->label],
            );
        }
    }

    public function fail(int $exportJobId, Throwable $exception): void
    {
        $record = DB::transaction(function () use ($exportJobId, $exception): ?ExportJob {
            $record = ExportJob::with('user')->lockForUpdate()->find($exportJobId);

            if (
                $record === null
                || in_array($record->status, [ExportJob::STATUS_COMPLETED, ExportJob::STATUS_FAILED], true)
            ) {
                return null;
            }

            $record->forceFill([
                'status' => ExportJob::STATUS_FAILED,
                'progress_stage' => ExportJob::STAGE_FAILED,
                'progress' => null,
                'error_message' => Str::limit($exception->getMessage(), 500, ''),
                'completed_at' => null,
                'expires_at' => now()->addDays(3),
            ])->save();

            return $record;
        }, 3);

        if ($record === null) {
            return;
        }

        $filePath = $record->hasSafeFilePath() ? $record->file_path : null;
        $fileDeleted = $filePath === null;

        if ($filePath !== null) {
            try {
                $disk = Storage::disk($record->disk);
                $fileDeleted = ! $disk->exists($filePath) || $disk->delete($filePath);
            } catch (Throwable $cleanupException) {
                $fileDeleted = false;

                Log::warning('Export partial-file cleanup failed.', [
                    'export_job_id' => $record->getKey(),
                    'exception_class' => $cleanupException::class,
                ]);
            }
        }

        if ($fileDeleted) {
            $cleanup = ExportJob::query()
                ->whereKey($record->getKey())
                ->where('status', ExportJob::STATUS_FAILED);

            if ($filePath !== null) {
                $cleanup->where('file_path', $filePath);
            }

            $cleanup->update(['file_path' => null]);
            $record->file_path = null;
        }

        $this->broadcast($record);

        Log::error('Export processing failed.', [
            'export_job_id' => $record->getKey(),
            'user_id' => $record->user_id,
            'export_class' => $record->export_class,
            'exception_class' => $exception::class,
        ]);

        if ($record->user !== null) {
            $this->notifications->send(
                $record->user,
                'export.failed',
                'export:'.$record->getKey().':failed',
                'Export Failed',
                'The export could not be processed. Please try again.',
                route('exports.index', absolute: false),
                'triangle-alert',
                [
                    'category' => NotificationCategory::OTHER,
                    'export_job_id' => $record->getRouteKey(),
                ],
            );
        }
    }

    private function broadcast(ExportJob $record): void
    {
        try {
            event(new ExportProgressUpdated(
                (int) $record->user_id,
                (string) $record->getRouteKey(),
                (string) $record->status,
                (string) $record->progress_stage,
                $record->progressMessage(),
                $record->progress,
                (int) $record->processed_rows,
                (int) $record->total_rows,
            ));
        } catch (Throwable $exception) {
            Log::warning('Export progress broadcast failed.', [
                'export_job_id' => $record->getKey(),
                'stage' => $record->progress_stage,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
