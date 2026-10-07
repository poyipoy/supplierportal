<?php

namespace App\Exports\Advanced;

use Closure;

// Runtime catalog entries live ONLY in a definition's static cache.
// No export/job instance may store this object or its callbacks.
final readonly class ExportColumn
{
    public function __construct(
        public string $key,
        public string $headingKey,
        public Closure $value,
        public float $width = 18,
        public ColumnType $type = ColumnType::Text,
        public bool $default = true,
        public bool $required = false,
        public array $audiences = ['purchasing', 'supplier'],
        public array $with = [],
        public ?Closure $headingSuffix = null,
    ) {}

    public function visibleTo(string $audience): bool
    {
        return in_array($audience, $this->audiences, true);
    }
}
