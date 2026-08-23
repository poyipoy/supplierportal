<?php

namespace App\Jobs;

use App\Contracts\TracksExportProgress;
use App\Jobs\Callbacks\MarkExportFailed;
use App\Models\ExportJob;
use App\Services\ExportProgressService;
use App\Support\ExportDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(ExportProgressService $progress): void
    {
        $record = ExportJob::find($this->exportJobId);

        if ($record === null || $record->status !== ExportJob::STATUS_QUEUED) {
            return;
        }

        if (! ExportDispatcher::isSupported($record->export_class) || ! class_exists($record->export_class)) {
            throw new RuntimeException('Unsupported export class.');
        }

        $exportClass = $record->export_class;
        $export = new $exportClass(...$record->export_args);

        if (! $export instanceof TracksExportProgress) {
            throw new RuntimeException('The export does not support row progress tracking.');
        }

        // Expensive construction/counting happens while the durable record is
        // still queued. A process death here is therefore safe for worker retry.
        $totalRows = max(0, $export->progressTotalRows());
        $export->setExportProgressContext((int) $record->getKey());
        $path = 'exports/'.$record->user_id.'/'.$record->getKey().'/'.$record->file_name;

        $progress->handoffToExportQueue(
            $record,
            $path,
            $totalRows,
            function () use ($export, $path, $record): void {
                $pending = Excel::queue($export, $path, $record->disk)
                    ->allOnQueue('exports')
                    ->appendToChain(new FinalizeExportJob((int) $record->getKey()));

                $pending->getJob()->chainCatchCallbacks = [
                    new MarkExportFailed((int) $record->getKey()),
                ];

                // Force dispatch before the surrounding database transaction commits.
                unset($pending);
            },
        );
    }

    public function failed(Throwable $exception): void
    {
        app(ExportProgressService::class)->fail($this->exportJobId, $exception);
    }
}
