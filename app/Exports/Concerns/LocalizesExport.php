<?php

namespace App\Exports\Concerns;

use App\Services\UserPreferenceService;

trait LocalizesExport
{
    private string $exportLocale = 'en';

    public function setExportLocale(string $locale): void
    {
        $this->exportLocale = UserPreferenceService::normalizeLocale($locale);
    }

    public function preferredLocale(): string
    {
        return $this->exportLocale;
    }
}
