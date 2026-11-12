<?php

namespace App\Services\Pricing;

class ReferencePriceBenchmarkResult
{
    public function __construct(
        public readonly HistoricalCalculationResult $main,
        public readonly ?HistoricalCalculationResult $supplierAlternative = null,
        public readonly ?string $offeredPricePerKg = null,
        public readonly ?string $convertedOfferedPricePerKg = null,
        public readonly ?string $offeredCurrency = null,
        public readonly string $targetCurrency = 'IDR',
        public readonly ?string $mainDelta = null,
        public readonly ?float $mainDeltaPercent = null,
        public readonly ?string $alternativeDelta = null,
        public readonly ?float $alternativeDeltaPercent = null,
        public readonly bool $hasAlternative = false,
        public readonly array $metadata = [],
    ) {}

    public function isMainMatched(): bool
    {
        return $this->main->isMatched();
    }

    public function isAlternativeMatched(): bool
    {
        return $this->supplierAlternative !== null && $this->supplierAlternative->isMatched();
    }
}
