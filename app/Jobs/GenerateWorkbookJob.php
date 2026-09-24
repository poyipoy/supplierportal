<?php

namespace App\Jobs;

use App\Contracts\GeneratesWorkbook;
use App\Contracts\TracksExportProgress;
use App\Models\ExportJob;
use App\Services\ExportProgressService;
use App\Support\ExportDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class GenerateWorkbookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 600;

    public function __construct(public readonly int $exportJobId) {}

    public function handle(ExportProgressService $progress): void
    {
        $record = ExportJob::find($this->exportJobId);

        if ($record === null || $record->status !== ExportJob::STATUS_PROCESSING) {
            return;
        }

        if (! ExportDispatcher::isSupported($record->export_class) || ! class_exists($record->export_class)) {
            throw new RuntimeException('Unsupported export class.');
        }

        $exportClass = $record->export_class;
        $export = new $exportClass(...$record->export_args);

        if (! $export instanceof GeneratesWorkbook) {
            throw new RuntimeException('The export does not support workbook generation.');
        }

        if ($export instanceof TracksExportProgress) {
            $export->setExportProgressContext((int) $record->getKey());
        }

        $path = $record->file_path ?: ('exports/'.$record->user_id.'/'.$record->getKey().'/'.$record->file_name);
        $disk = $record->disk ?: 'private';

        $export->generateWorkbook($path, $disk);

        FinalizeExportJob::dispatch((int) $record->getKey())->onQueue('exports');
    }

    public function failed(Throwable $exception): void
    {
        app(ExportProgressService::class)->fail($this->exportJobId, $exception);
    }
}
