<?php

namespace App\Jobs\Callbacks;

use App\Services\ExportProgressService;
use Throwable;

class MarkExportFailed
{
    public function __construct(private readonly int $exportJobId) {}

    public function __invoke(Throwable $exception): void
    {
        app(ExportProgressService::class)->fail($this->exportJobId, $exception);
    }
}
