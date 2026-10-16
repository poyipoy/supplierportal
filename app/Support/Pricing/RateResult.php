<?php

namespace App\Support\Pricing;

/**
 * A benchmark rate to IDR. IDR itself is the identity rate and has no row.
 */
final readonly class RateResult
{
    public function __construct(
        public string $rate,
        public ?int $exchangeRateId,
        public ?string $validFrom,
    ) {}
}
