<?php

namespace App\Exports\Concerns;

use App\Jobs\Middleware\TrackExportChunkProgress;

trait InteractsWithExportProgress
{
    private ?int $exportProgressJobId = null;

    private ?int $exportProgressTotalRows = null;

    public function setExportProgressContext(int $exportJobId): void
    {
        $this->exportProgressJobId = $exportJobId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return $this->exportProgressJobId === null
            ? []
            : [new TrackExportChunkProgress($this->exportProgressJobId)];
    }

    public function querySize(): int
    {
        return $this->exportProgressTotalRows ??= max(0, $this->query()->count());
    }
}
