<?php

namespace App\Support\Export;

use InvalidArgumentException;

final readonly class ExportOptions
{
    public function __construct(
        public array $columns = [],
        public string $format = 'xlsx',
        public string $audience = 'purchasing',
    ) {
        if (! in_array($format, ['xlsx', 'csv'], true)
            || ! in_array($audience, ['purchasing', 'supplier', 'qc', 'finance', 'accounting', 'admin'], true)
            || ! array_is_list($columns)
            || count($columns) !== count(array_unique($columns, SORT_REGULAR))) {
            throw new InvalidArgumentException('Invalid export options.');
        }
        foreach ($columns as $key) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)) {
                throw new InvalidArgumentException('Invalid export column key.');
            }
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['columns', 'format', 'audience']) !== []
            || ! isset($data['columns'], $data['format'], $data['audience'])
            || ! is_array($data['columns']) || ! is_string($data['format']) || ! is_string($data['audience'])) {
            throw new InvalidArgumentException('Invalid stored export options.');
        }

        return new self($data['columns'], $data['format'], $data['audience']);
    }

    public function toArray(): array
    {
        return ['columns' => $this->columns, 'format' => $this->format, 'audience' => $this->audience];
    }
}
