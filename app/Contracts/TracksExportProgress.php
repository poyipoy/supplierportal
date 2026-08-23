<?php

namespace App\Contracts;

interface TracksExportProgress
{
    public function setExportProgressContext(int $exportJobId): void;

    public function progressTotalRows(): int;

    /** @return array<int, object> */
    public function middleware(): array;
}
