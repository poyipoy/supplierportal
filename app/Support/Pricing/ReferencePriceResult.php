<?php

namespace App\Support\Pricing;

use App\Models\MaterialReferencePrice;

/**
 * Outcome of ReferencePriceResolver (Q6A). On a miss every price and delta is
 * null and the reason code explains why; offer data stays visible to callers.
 */
final readonly class ReferencePriceResult
{
    public const MATERIAL_UNMAPPED = 'material_unmapped';

    public const OFFERED_DIMENSION_MISSING = 'offered_dimension_missing';

    public const NO_PRICE_FOR_MATERIAL = 'no_price_for_material';

    public const DIMENSION_NOT_COVERED = 'dimension_not_covered';

    public const PERIOD_NOT_EFFECTIVE = 'period_not_effective';

    public const EXCHANGE_RATE_UNAVAILABLE = 'exchange_rate_unavailable';

    public const AMBIGUOUS_CONFIGURATION = 'ambiguous_configuration';

    public const REASONS = [
        self::MATERIAL_UNMAPPED,
        self::OFFERED_DIMENSION_MISSING,
        self::NO_PRICE_FOR_MATERIAL,
        self::DIMENSION_NOT_COVERED,
        self::PERIOD_NOT_EFFECTIVE,
        self::EXCHANGE_RATE_UNAVAILABLE,
        self::AMBIGUOUS_CONFIGURATION,
    ];

    private function __construct(
        public ?MaterialReferencePrice $reference,
        public ?string $reasonCode,
        public string $evaluationDate,
        public string $targetCurrency,
        public ?string $dimensionField,
        public ?string $dimensionValue,
        public ?string $offerPricePerKg,
        public ?string $convertedReferencePricePerKg = null,
        public ?int $referenceRateId = null,
        public ?int $targetRateId = null,
        public ?string $diffAmountPerKg = null,
        public ?string $diffPercent = null,
    ) {}

    public static function matched(
        MaterialReferencePrice $reference,
        string $evaluationDate,
        string $targetCurrency,
        string $dimensionField,
        string $dimensionValue,
        ?string $offer,
        string $converted,
        ?int $referenceRateId,
        ?int $targetRateId,
        ?string $diff,
        ?string $diffPercent,
    ): self {
        return new self($reference, null, $evaluationDate, $targetCurrency, $dimensionField, $dimensionValue, $offer,
            $converted, $referenceRateId, $targetRateId, $diff, $diffPercent);
    }

    public static function notAvailable(
        string $reason,
        string $evaluationDate,
        string $targetCurrency,
        ?string $dimensionField,
        ?string $dimensionValue,
        ?string $offer,
    ): self {
        return new self(null, $reason, $evaluationDate, $targetCurrency, $dimensionField, $dimensionValue, $offer);
    }

    public function isMatched(): bool
    {
        return $this->reference !== null;
    }
}
