<?php

namespace App\Data\VendorMaster;

final readonly class SupplierScorecardData
{
    /**
     * @param  array<string, array{key: string, label_key: string, percentage: string, numerator: int, denominator: int, has_data: bool}>  $metrics
     */
    public function __construct(
        public int $supplierUserId,
        public string $scope,
        public string $compositeScore,
        public string $grade,
        public bool $hasData,
        public array $metrics,
    ) {}

    public function gradeTone(): string
    {
        return match ($this->grade) {
            'A' => 'success',
            'B' => 'info',
            'C' => 'warning',
            default => 'danger',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'supplier_user_id' => $this->supplierUserId,
            'scope' => $this->scope,
            'composite_score' => $this->compositeScore,
            'grade' => $this->grade,
            'grade_tone' => $this->gradeTone(),
            'has_data' => $this->hasData,
            'metrics' => $this->metrics,
        ];
    }
}
