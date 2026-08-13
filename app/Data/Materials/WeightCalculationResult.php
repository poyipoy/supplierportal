<?php

namespace App\Data\Materials;

final readonly class WeightCalculationResult
{
    public function __construct(
        public string $status,
        public ?float $unitKg,
        public ?float $totalKg,
        public ?string $formulaKey,
        public ?float $factor,
        public string $message,
    ) {}

    public function isCalculated(): bool
    {
        return $this->status === 'calculated' && $this->unitKg !== null;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'unit_kg' => $this->unitKg,
            'total_kg' => $this->totalKg,
            'formula_key' => $this->formulaKey,
            'factor' => $this->factor,
            'message' => $this->message,
        ];
    }
}
