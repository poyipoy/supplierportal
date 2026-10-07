<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ExportDefinition;
use App\Exports\SupplierPriceHistoryExport;
use App\Models\User;

final class PriceHistoryDefinition implements ExportDefinition
{
    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return SupplierPriceHistoryExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->isImportEligible();
    }

    public function filterSchema(): array
    {
        return [];
    }

    // Tier 2 exposes formats only; no selectable columns or personal presets.
    public static function columns(): array
    {
        return [];
    }
}
