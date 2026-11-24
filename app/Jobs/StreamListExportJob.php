<?php

namespace App\Jobs;

use App\Contracts\AcceptsExportOptions;
use App\Contracts\GeneratesWorkbook;
use App\Models\ExportJob;
use App\Services\Export\OpenSpoutListWriter;
use App\Services\ExportProgressService;
use App\Services\UserPreferenceService;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportOptionsResolver;
use App\Support\ExportDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Concerns\FromQuery;
use RuntimeException;
use Throwable;

class StreamListExportJob implements ShouldQueue
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

    public function handle(ExportProgressService $progress, OpenSpoutListWriter $writer): void
    {
        $record = ExportJob::find($this->exportJobId);

        if ($record === null) {
            return;
        }

        if ($record->status === ExportJob::STATUS_CANCELLED) {
            $progress->cleanupCancelledFile($this->exportJobId);

            return;
        }

        if ($record->status !== ExportJob::STATUS_PROCESSING) {
            return;
        }

        if (
            ! ExportDispatcher::isSupported($record->export_class)
            || ! ExportDispatcher::isListExport($record->export_class)
            || ! class_exists($record->export_class)
        ) {
            throw new RuntimeException('Unsupported export class for streaming.');
        }

        $previousLocale = app()->getLocale();
        try {
            app()->setLocale(UserPreferenceService::normalizeLocale($this->locale));

            $exportClass = $record->export_class;
            $export = new $exportClass(...$record->export_args);
            $export->setExportLocale($this->locale);

            if (! $export instanceof FromQuery || $export instanceof GeneratesWorkbook) {
                throw new RuntimeException('The export does not support OpenSpout query streaming.');
            }

            $format = $record->format ?? 'xlsx';
            if (! in_array($format, ['xlsx', 'csv'], true)) {
                throw new RuntimeException('Unsupported export format.');
            }

            if ($record->export_options !== null) {
                $options = ExportOptions::fromArray($record->export_options);
                if (! $export instanceof AcceptsExportOptions || $options->format !== $format) {
                    throw new RuntimeException('The export does not accept these options.');
                }
                $export->applyOptions($options);
                ExportOptionsResolver::authorizeStored($exportClass, $record->user()->firstOrFail(), $options);
            } elseif ($format !== 'xlsx') {
                throw new RuntimeException('CSV exports require explicit export options.');
            }

            $path = $record->file_path ?: ('exports/'.$record->user_id.'/'.$record->getKey().'/'.$record->file_name);
            $disk = $record->disk ?: 'private';

            $written = $writer->write($record, $export, $path, $disk, $progress);
        } finally {
            app()->setLocale($previousLocale);
        }

        if (! $written) {
            $progress->cleanupCancelledFile($this->exportJobId);

            return;
        }

        FinalizeExportJob::dispatch((int) $record->getKey())->onQueue('exports');
    }

    public function failed(Throwable $exception): void
    {
        app(ExportProgressService::class)->fail($this->exportJobId, $exception);
    }
}
