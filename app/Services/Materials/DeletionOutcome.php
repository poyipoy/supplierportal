<?php

namespace App\Services\Materials;

/**
 * Result of a guarded master-data delete. A refusal carries the reference
 * counts shown to the user and the audited reason (`referenced` or
 * `fk_restrict`).
 */
final class DeletionOutcome
{
    /**
     * @param  array<string, int>  $counts
     */
    private function __construct(
        public readonly bool $deleted,
        public readonly array $counts = [],
        public readonly ?string $reason = null,
    ) {}

    public static function deleted(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function refused(array $counts, string $reason): self
    {
        return new self(false, $counts, $reason);
    }
}
