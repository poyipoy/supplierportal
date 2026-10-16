<?php

namespace App\Imports;

use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use Maatwebsite\Excel\Events\BeforeImport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

abstract class AbstractPreviewImport implements WithReadFilter
{
    use RegistersEventListeners;

    protected const MAX_ROWS = 1000;

    public const MAX_COLUMNS = 64;

    public array $rows = [];

    public array $errors = [];

    public array $warnings = [];

    protected int $totalRowCount = 0;

    protected int $invalidRowCount = 0;

    protected bool $fileInvalid = false;

    /**
     * Decision 05: when true, any row error makes the whole preview
     * non-importable (rows: [], HTTP 422). Opt-in per import so the Local
     * PO/GR pipelines keep their own contract.
     */
    protected bool $allOrNothing = false;

    /** Reject raw dimensions before the workbook is hydrated, including blank rows. */
    public function acceptsDimensions(int $rows, int $columns): bool
    {
        $this->totalRowCount = max(0, $rows - 1);
        if ($rows > self::MAX_ROWS + 1) {
            $this->invalidRowCount = $this->totalRowCount;
            $this->addFileError(null, 'import_file', __('purchasing.imports.max_rows', ['count' => self::MAX_ROWS]));
        }
        if ($columns > self::MAX_COLUMNS) {
            $this->addFileError(null, 'import_file', __('file_security_resources.sheet_columns', ['count' => self::MAX_COLUMNS]));
        }

        return ! $this->fileInvalid;
    }

    public function readFilter(): IReadFilter
    {
        return new class(self::MAX_ROWS + 1, self::MAX_COLUMNS) implements IReadFilter
        {
            public function __construct(private int $rows, private int $columns) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= 1 && $row <= $this->rows
                    && Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
            }
        };
    }

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
                __('purchasing.imports.additional_sheets', ['count' => count($worksheets) - 1])
            );
        }
    }

    public function preview(): array
    {
        $blocked = $this->allOrNothing && $this->errors !== [];

        return [
            'success' => $this->errors === [],
            // summary.valid still reports rows that passed individually so the
            // operator sees the scale of the problem; none of them is importable.
            'rows' => $blocked ? [] : $this->rows,
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

    /** Whether the preview must be answered with HTTP 422. */
    public function hasBlockingErrors(): bool
    {
        return $this->fileInvalid || ($this->allOrNothing && $this->errors !== []);
    }

    protected function validateCollectionContract(
        Collection $collection,
        array $requiredHeadings,
        array $allowedHeadings
    ): bool {
        $this->totalRowCount = $collection->count();

        if ($collection->isEmpty()) {
            $this->addFileError(null, 'import_file', __('purchasing.imports.empty'));

            return false;
        }

        if ($collection->count() > self::MAX_ROWS) {
            $this->invalidRowCount = $collection->count();
            $this->addFileError(
                null,
                'import_file',
                __('purchasing.imports.max_rows', ['count' => self::MAX_ROWS])
            );

            return false;
        }

        $headings = collect($collection->first()->keys())
            ->filter(fn ($heading) => is_string($heading) && $heading !== '')
            ->values();

        foreach ($requiredHeadings as $requiredHeading) {
            if (! $headings->contains($requiredHeading)) {
                $this->addFileError(1, $requiredHeading, __('purchasing.imports.required_heading', ['heading' => $requiredHeading]));
            }
        }

        $headings->diff($allowedHeadings)->each(function (string $heading): void {
            $this->addWarning(1, $heading, __('purchasing.imports.unknown_heading', ['heading' => $heading]));
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

    protected function addRowError(?int $row, string $column, string $message, mixed $value = null): void
    {
        $error = compact('row', 'column', 'message');
        if ($value !== null) {
            // Echo the offending cell back safely (formula-neutralized, bounded).
            $error['value'] = mb_substr(SpreadsheetCellSanitizer::text((string) $value), 0, 100);
        }

        $this->errors[] = $error;
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
