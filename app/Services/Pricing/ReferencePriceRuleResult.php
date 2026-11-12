<?php

namespace App\Services\Pricing;

use App\Models\ReferencePriceRule;

class ReferencePriceRuleResult
{
    public function __construct(
        public readonly bool $matched,
        public readonly ?ReferencePriceRule $rule = null,
        public readonly ?string $dimensionField = null,
        public readonly ?string $rawDimension = null,
        public readonly ?int $roundedDimension = null,
        public readonly ?string $reasonCode = null,
    ) {}

    public function isMatched(): bool
    {
        return $this->matched && $this->rule !== null;
    }
}
