<?php

namespace App\Data\Materials;

final readonly class ProcessedPrItemResult
{
    public function __construct(
        public array $data,
        public array $errors,
        public HsCodeResolutionResult $hsCode,
        public WeightCalculationResult $weight,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
