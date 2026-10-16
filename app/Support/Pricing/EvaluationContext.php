<?php

namespace App\Support\Pricing;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

/**
 * The single evaluation date of one request (Q9, I-14): business today in
 * app.business_timezone, resolved once. Bound as a scoped instance, so every
 * analytical value in the request uses the same date even if the clock crosses
 * midnight while the request runs. There is no manual date picker.
 */
final readonly class EvaluationContext
{
    public function __construct(
        public CarbonImmutable $date,
    ) {}

    public static function forBusinessToday(): self
    {
        return new self(BusinessTime::today());
    }

    public static function current(): self
    {
        return app(self::class);
    }

    public function dateString(): string
    {
        return $this->date->toDateString();
    }
}
