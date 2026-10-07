<?php

namespace App\Contracts;

use App\Support\Export\ExportOptions;

interface AcceptsExportOptions
{
    public function applyOptions(ExportOptions $options): void;
}
