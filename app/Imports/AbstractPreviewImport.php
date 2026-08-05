<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Events\BeforeImport;

abstract class AbstractPreviewImport
{
    use RegistersEventListeners;

    protected const MAX_ROWS = 1000;

    public array $rows = [];

    public array $errors = [];

    public array $warnings = [];

    protected int $totalRowCount = 0;

    protected int $invalidRowCount = 0;

    protected bool $fileInvalid = false;

    public function sheets(): array
    {
        return [0 => $this];
    }

    public function beforeImport(BeforeImport $event): void
    {
        $worksheets = $event->getReader()->getTotalRows();

        if (count($worksheets) > 1) {
            $this->addWarning(
                null,
                'worksheet',
                'Only the first worksheet is imported; '.(count($worksheets) - 1).' additional worksheet(s) were ignored.'
            );
        }
    }

    public function preview(): array
    {
        return [
            'success' => $this->errors === [],
            'rows' => $this->rows,
            'warnings' => $this->warnings,
            'summary' => [
                'total' => $this->totalRowCount,
                'valid' => count($this->rows),
                'invalid' => $this->invalidRowCount,
            ],
            'errors' => $this->errors,
        ];
    }

    public function hasFileErrors(): bool
    {
        return $this->fileInvalid;
    }

    protected function validateCollectionContract(
        Collection $collection,
        array $requiredHeadings,
        array $allowedHeadings
    ): bool {
        $this->totalRowCount = $collection->count();

        if ($collection->isEmpty()) {
            $this->addFileError(null, 'import_file', 'The spreadsheet does not contain any data rows.');

            return false;
        }

        if ($collection->count() > self::MAX_ROWS) {
            $this->invalidRowCount = $collection->count();
            $this->addFileError(
                null,
                'import_file',
                'The spreadsheet may contain no more than '.self::MAX_ROWS.' non-empty data rows.'
            );

            return false;
        }

        $headings = collect($collection->first()->keys())
            ->filter(fn ($heading) => is_string($heading) && $heading !== '')
            ->values();

        foreach ($requiredHeadings as $requiredHeading) {
            if (! $headings->contains($requiredHeading)) {
                $this->addFileError(1, $requiredHeading, "Required heading '{$requiredHeading}' is missing.");
            }
        }

        $headings->diff($allowedHeadings)->each(function (string $heading): void {
            $this->addWarning(1, $heading, "Unknown heading '{$heading}' was ignored.");
        });

        return ! $this->fileInvalid;
    }

    protected function formulaColumns(array $row, array $columns): array
    {
        return collect($columns)
            ->filter(function (string $column) use ($row): bool {
                $value = $row[$column] ?? null;

                return is_string($value) && str_starts_with($value, '=');
            })
            ->values()
            ->all();
    }

    protected function addRowError(?int $row, string $column, string $message): void
    {
        $this->errors[] = compact('row', 'column', 'message');
    }

    protected function addFileError(?int $row, string $column, string $message): void
    {
        $this->fileInvalid = true;
        $this->addRowError($row, $column, $message);
    }

    protected function addWarning(?int $row, string $column, string $message): void
    {
        $this->warnings[] = compact('row', 'column', 'message');
    }

    protected function markInvalidRow(): void
    {
        $this->invalidRowCount++;
    }

    protected static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected static function nullableNumber(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }
}
