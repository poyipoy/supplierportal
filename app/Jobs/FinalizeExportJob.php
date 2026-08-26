<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\ExportProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 600;

    public function __construct(public readonly int $exportJobId) {}

    public function handle(ExportProgressService $progress): void
    {
        if (ExportJob::query()->whereKey($this->exportJobId)->value('status') === ExportJob::STATUS_CANCELLED) {
            $progress->cleanupCancelledFile($this->exportJobId);

            return;
        }

        $progress->complete($this->exportJobId);
    }
}
