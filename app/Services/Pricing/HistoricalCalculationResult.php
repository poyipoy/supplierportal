<?php

namespace App\Services\Pricing;

use App\Models\ReferencePriceRule;

class HistoricalCalculationResult
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reasonCode = null,
        public readonly ?int $materialMasterId = null,
        public readonly ?string $shape = null,
        public readonly ?string $dimensionField = null,
        public readonly ?string $rawDimension = null,
        public readonly ?int $roundedDimension = null,
        public readonly ?int $ruleSetId = null,
        public readonly ?int $ruleId = null,
        public readonly ?string $categoryKey = null,
        public readonly ?int $benchmarkYear = null,
        public readonly bool $isCurrentYear = true,
        public readonly string $currency = 'IDR',
        public readonly string $calculationMethod = 'weighted_average_kg',
        public readonly ?string $weightedAveragePricePerKg = null,
        public readonly ?string $lowestPricePerKg = null,
        public readonly ?string $highestPricePerKg = null,
        public readonly ?string $lastPurchasePricePerKg = null,
        public readonly ?string $latestPoAt = null,
        public readonly int $lineCount = 0,
        public readonly int $poCount = 0,
        public readonly string $totalKg = '0.0000',
        public readonly int $excludedLineCount = 0,
        public readonly string $excludedTotalKg = '0.0000',
        public readonly array $excludedCurrencies = [],
        public readonly ?string $historyAsOfAt = null,
        public readonly array $rateMetadata = [],
        public readonly array $sourceLines = [],
        public readonly ?ReferencePriceRule $rule = null,
        public readonly array $yearlySummaries = [],
    ) {}

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED && $this->weightedAveragePricePerKg !== null;
    }

    public static function notAvailable(
        string $reasonCode,
        string $currency = 'IDR',
        ?int $materialMasterId = null,
        ?string $shape = null,
        ?string $dimensionField = null,
        ?string $rawDimension = null,
        ?int $roundedDimension = null,
        ?int $ruleSetId = null,
        ?int $ruleId = null,
        ?string $categoryKey = null,
        ?int $benchmarkYear = null,
        bool $isCurrentYear = true,
        ?string $historyAsOfAt = null,
        array $rateMetadata = [],
        array $yearlySummaries = [],
        ?ReferencePriceRule $rule = null,
        int $excludedLineCount = 0,
        string $excludedTotalKg = '0.0000',
        array $excludedCurrencies = [],
        array $sourceLines = [],
    ): self {
        return new self(
            status: self::STATUS_NOT_AVAILABLE,
            reasonCode: $reasonCode,
            materialMasterId: $materialMasterId,
            shape: $shape,
            dimensionField: $dimensionField,
            rawDimension: $rawDimension,
            roundedDimension: $roundedDimension,
            ruleSetId: $ruleSetId,
            ruleId: $ruleId,
            categoryKey: $categoryKey,
            benchmarkYear: $benchmarkYear,
            isCurrentYear: $isCurrentYear,
            currency: $currency,
            excludedLineCount: $excludedLineCount,
            excludedTotalKg: $excludedTotalKg,
            excludedCurrencies: $excludedCurrencies,
            historyAsOfAt: $historyAsOfAt,
            rateMetadata: $rateMetadata,
            sourceLines: $sourceLines,
            rule: $rule,
            yearlySummaries: $yearlySummaries,
        );
    }
}
