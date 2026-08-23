<?php

namespace App\Services;

use App\Events\ExportProgressUpdated;
use App\Models\ExportJob;
use App\Support\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExportProgressService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function prepare(ExportJob $record, string $path): ExportJob
    {
        $record->forceFill([
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
        ])->save();

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
        $record = ExportJob::with('user')->find($exportJobId);

        if ($record === null || $record->status === ExportJob::STATUS_FAILED) {
            return;
        }

        if ($record->status === ExportJob::STATUS_COMPLETED && $record->isDownloadable()) {
            return;
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
        $record = ExportJob::with('user')->find($exportJobId);

        if ($record === null || $record->status === ExportJob::STATUS_FAILED) {
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

        $record->forceFill([
            'status' => ExportJob::STATUS_FAILED,
            'progress_stage' => ExportJob::STAGE_FAILED,
            'progress' => null,
            'file_path' => $fileDeleted ? null : $filePath,
            'error_message' => Str::limit($exception->getMessage(), 500, ''),
            'completed_at' => null,
            'expires_at' => now()->addDays(3),
        ])->save();

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
