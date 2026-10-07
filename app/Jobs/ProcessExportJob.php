<?php

namespace App\Jobs;

use App\Contracts\AcceptsExportOptions;
use App\Contracts\GeneratesWorkbook;
use App\Contracts\TracksExportProgress;
use App\Jobs\Callbacks\MarkExportFailed;
use App\Models\ExportJob;
use App\Services\ExportProgressService;
use App\Services\UserPreferenceService;
use App\Support\Export\ExportOptions;
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

    public string $locale = 'en';

    public function __construct(public readonly int $exportJobId, string $locale = 'en')
    {
        $this->locale = UserPreferenceService::normalizeLocale($locale);
    }

    public function handle(ExportProgressService $progress): void
    {
        $record = ExportJob::find($this->exportJobId);

        if ($record === null || $record->status !== ExportJob::STATUS_QUEUED) {
            return;
        }

        if (! ExportDispatcher::isSupported($record->export_class) || ! class_exists($record->export_class)) {
            throw new RuntimeException('Unsupported export class.');
        }

        $previousLocale = app()->getLocale();
        try {
            app()->setLocale(UserPreferenceService::normalizeLocale($this->locale));
            $exportClass = $record->export_class;
            $export = new $exportClass(...$record->export_args);
            $export->setExportLocale($this->locale);

            $format = $record->format ?? 'xlsx';
            if (! in_array($format, ['xlsx', 'csv'], true)) {
                throw new RuntimeException('Unsupported export format.');
            }
            if ($record->export_options !== null) {
                $options = ExportOptions::fromArray($record->export_options);
                if (! $export instanceof AcceptsExportOptions || $options->format !== $format || $export instanceof GeneratesWorkbook) {
                    throw new RuntimeException('The export does not accept these options.');
                }
                $export->applyOptions($options);
            } elseif ($format !== 'xlsx') {
                throw new RuntimeException('CSV exports require explicit export options.');
            }

            if (! $export instanceof TracksExportProgress) {
                throw new RuntimeException('The export does not support row progress tracking.');
            }

            // Expensive construction/counting happens while the durable record is
            // still queued. A process death here is therefore safe for worker retry.
            $totalRows = max(0, $export->progressTotalRows());
            $export->setExportProgressContext((int) $record->getKey());
            $path = 'exports/'.$record->user_id.'/'.$record->getKey().'/'.$record->file_name;

            if ($export instanceof GeneratesWorkbook) {
                $progress->handoffToExportQueue(
                    $record,
                    $path,
                    $totalRows,
                    function () use ($record): void {
                        $pending = GenerateWorkbookJob::dispatch((int) $record->getKey(), $this->locale)
                            ->onQueue('exports');

                        // Force dispatch before the surrounding database transaction commits.
                        unset($pending);
                    },
                );

                return;
            }

            $progress->handoffToExportQueue(
                $record,
                $path,
                $totalRows,
                function () use ($export, $path, $record): void {
                    $pending = Excel::queue($export, $path, $record->disk, ($record->format ?? 'xlsx') === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX)
                        ->allOnQueue('exports')
                        ->appendToChain(new FinalizeExportJob((int) $record->getKey()));

                    $pending->getJob()->chainCatchCallbacks = [
                        new MarkExportFailed((int) $record->getKey()),
                    ];

                    // Force dispatch before the surrounding database transaction commits.
                    unset($pending);
                },
            );
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(ExportProgressService::class)->fail($this->exportJobId, $exception);
    }
}
