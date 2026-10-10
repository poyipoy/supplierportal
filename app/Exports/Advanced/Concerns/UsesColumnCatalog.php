<?php

namespace App\Exports\Advanced\Concerns;

use App\Exports\Advanced\ColumnType;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use App\Support\SpreadsheetCellSanitizer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

trait UsesColumnCatalog
{
    // Instance state is scalar-only; callbacks/catalog/definition objects are
    // resolved in local variables at runtime and never retained on this export.
    private ?array $columnKeys = null;

    private string $columnAudience = 'purchasing';

    private string $columnFormat = 'xlsx';

    public function applyOptions(ExportOptions $options): void
    {
        $definition = ExportDefinitions::forClass(static::class, $options->audience);
        $this->columnKeys = ExportDefinitions::sanitizeKeys($definition, $options->columns);
        $this->columnAudience = $options->audience;
        $this->columnFormat = $options->format;
    }

    protected function hasExportOptions(): bool
    {
        return $this->columnKeys !== null;
    }

    private function selectedColumns(): array
    {
        $definition = ExportDefinitions::forClass(static::class, $this->columnAudience);
        $keys = ExportDefinitions::sanitizeKeys($definition, $this->columnKeys ?? []);
        $catalog = [];
        foreach (ExportDefinitions::allowedColumns($definition) as $column) {
            $catalog[$column->key] = $column;
        }

        return array_map(fn ($key) => $catalog[$key], $keys);
    }

    protected function catalogHeadings(): array
    {
        return array_map(fn ($c) => __('exports.headings.'.$c->headingKey).($c->headingSuffix ? ($c->headingSuffix)() : ''), $this->selectedColumns());
    }

    protected function catalogMap($row): array
    {
        return array_map(function ($column) use ($row) {
            $value = ($column->value)($row);

            return $column->type === ColumnType::Text ? SpreadsheetCellSanitizer::text($value) : $value;
        }, $this->selectedColumns());
    }

    protected function catalogWidths(): array
    {
        if ($this->columnFormat === 'csv') {
            return [];
        }
        $widths = [];
        foreach ($this->selectedColumns() as $index => $column) {
            $widths[Coordinate::stringFromColumnIndex($index + 1)] = fmod($column->width, 1.0) === 0.0 ? (int) $column->width : $column->width;
        }

        return $widths;
    }

    protected function catalogEagerLoads(): array
    {
        $with = [];
        foreach ($this->selectedColumns() as $column) {
            $with = [...$with, ...$column->with];
        }

        return array_values(array_unique($with));
    }

    protected function catalogStylesEnabled(): bool
    {
        return $this->columnFormat !== 'csv';
    }

    /** @return list<string> */
    protected function catalogWrappedColumns(): array
    {
        $wrapped = [];
        foreach ($this->selectedColumns() as $index => $column) {
            if ($column->wrapText) {
                $wrapped[] = Coordinate::stringFromColumnIndex($index + 1);
            }
        }

        return $wrapped;
    }

    public function getCsvSettings(): array
    {
        return $this->columnFormat === 'csv' ? config('exports.csv') : [];
    }
}
