<?php

namespace App\Data\Materials;

final readonly class HsCodeResolutionResult
{
    public function __construct(
        public string $status,
        public ?string $hsCode,
        public ?int $ruleId,
        public array $candidates,
        public string $message,
        public array $matchedInputs = [],
    ) {}

    public function isMatched(): bool
    {
        return $this->status === 'matched' && $this->hsCode !== null;
    }

    public function allowsManualSelection(): bool
    {
        return in_array($this->status, ['ambiguous', 'no_rule', 'unmapped_material'], true);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'code' => $this->hsCode,
            'rule_id' => $this->ruleId,
            'candidates' => $this->candidates,
            'message' => $this->message,
            'matched_inputs' => $this->matchedInputs,
        ];
    }
}
