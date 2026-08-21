<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\NotificationService;
use App\Support\ExportDispatcher;
use App\Support\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 600;

    public function __construct(public readonly int $exportJobId) {}

    public function handle(NotificationService $notifications): void
    {
        $record = ExportJob::with('user')->find($this->exportJobId);

        if ($record === null || $record->status === ExportJob::STATUS_FAILED) {
            return;
        }

        if ($record->status === ExportJob::STATUS_COMPLETED && $record->isDownloadable()) {
            return;
        }

        if (! ExportDispatcher::isSupported($record->export_class) || ! class_exists($record->export_class)) {
            throw new RuntimeException('Unsupported export class.');
        }

        $path = 'exports/'.$record->user_id.'/'.$record->getKey().'/'.$record->file_name;

        $record->forceFill([
            'status' => ExportJob::STATUS_PROCESSING,
            'file_path' => $path,
            'error_message' => null,
            'completed_at' => null,
            'expires_at' => null,
        ])->save();

        $exportClass = $record->export_class;
        $export = new $exportClass(...$record->export_args);
        $stored = Excel::store($export, $path, $record->disk);

        if (! $stored || ! Storage::disk($record->disk)->exists($path)) {
            throw new RuntimeException('The export file could not be stored.');
        }

        $record->forceFill([
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => $path,
            'completed_at' => now(),
            'expires_at' => now()->addDays(3),
            'error_message' => null,
        ])->save();

        if ($record->user !== null) {
            $notifications->send(
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

    public function failed(Throwable $exception): void
    {
        $record = ExportJob::with('user')->find($this->exportJobId);

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

        $record->forceFill([
            'status' => ExportJob::STATUS_FAILED,
            'file_path' => $fileDeleted ? null : $filePath,
            'error_message' => Str::limit($exception->getMessage(), 500, ''),
            'completed_at' => null,
            'expires_at' => now()->addDays(3),
        ])->save();

        Log::error('Export processing failed.', [
            'export_job_id' => $record->getKey(),
            'user_id' => $record->user_id,
            'export_class' => $record->export_class,
            'exception_class' => $exception::class,
        ]);

        if ($record->user !== null) {
            app(NotificationService::class)->send(
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
}
