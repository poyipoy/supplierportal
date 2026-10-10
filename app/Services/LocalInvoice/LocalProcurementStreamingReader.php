<?php

namespace App\Services\LocalInvoice;

use App\Services\FileSecurity\FileInspectionService;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use ZipArchive;

final class LocalProcurementStreamingReader
{
    public const MIME_TYPES = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', 'csv' => 'text/csv'];

    private const COLUMNS = [
        'PO' => ['po_number' => 4, 'supplier_name' => 6, 'po_date' => 9, 'po_amount' => 10],
        'GR' => ['description' => 8, 'gr_number' => 9, 'po_number' => 11, 'qty' => 15, 'uom' => 16, 'gr_date' => 19],
    ];

    public array $warnings = [];

    public function inspectFile(string $path, ?string $extension = null): string
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
        if (! isset(self::MIME_TYPES[$extension]) || ! is_file($path) || ! is_readable($path) || filesize($path) > (int) config('local_procurement_imports.max_file_kib') * 1024) {
            $this->invalidFile();
        }
        app(FileInspectionService::class)->inspectPath($path, 'import.'.$extension, 'spreadsheet_import', 'import_file');
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            $this->invalidFile();
        }
        try {
            $signature = fread($stream, 65536);
            if (! is_string($signature)) {
                $this->invalidFile();
            }
        } finally {
            fclose($stream);
        }
        if ($extension === 'xlsx') {
            $this->inspectArchive($path);
        } elseif ($extension === 'xls') {
            if (! str_starts_with($signature, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                $this->invalidFile();
            }
        } elseif (str_starts_with($signature, "PK\x03\x04") || str_starts_with($signature, "PK\x05\x06") || str_starts_with($signature, '%PDF-')
            || str_starts_with($signature, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") || preg_match('/^\s*(?:<!doctype\b|<html\b|<table\b|<\?xml\b|<\?php\b)/i', $signature)
            || (str_contains($signature, "\0") && ! str_starts_with($signature, "\xFF\xFE") && ! str_starts_with($signature, "\xFE\xFF"))) {
            $this->invalidFile();
        }

        return $extension;
    }

    public function rows(string $path, string $kind): Generator
    {
        if (! isset(self::COLUMNS[$kind])) {
            throw new \InvalidArgumentException('Unsupported import kind.');
        }
        $format = $this->inspectFile($path);
        $this->warnings = [];
        $mapping = self::COLUMNS[$kind];
        $reader = match ($format) {
            'csv' => new LocalProcurementCsvReader,
            'xls' => new LocalProcurementXlsReader,
            default => null,
        };
        $source = match ($format) {
            'csv' => $reader->rows($path, max($mapping) + 1),
            'xls' => $reader->rows($path, array_values($mapping)),
            default => $this->xlsxRows($path),
        };
        $count = 0;
        foreach ($source as [$number, $cells, $formulas, $calendar]) {
            if ($number === 1) {
                $this->headerWarnings(array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $cells), $kind);

                continue;
            }
            $values = ['_row' => $number, '_formula_columns' => []];
            foreach ($mapping as $field => $index) {
                $value = $cells[$index] ?? null;
                if (in_array($index, $formulas, true) || (is_string($value) && str_starts_with($value, '='))) {
                    $values['_formula_columns'][] = $field;
                }
                $values[$field] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
                if (in_array($field, ['po_date', 'gr_date'], true) && is_numeric($value)) {
                    $previousCalendar = ExcelDate::getExcelCalendar();
                    try {
                        ExcelDate::setExcelCalendar($calendar);
                        $values[$field] = ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
                    } finally {
                        ExcelDate::setExcelCalendar($previousCalendar);
                    }
                }
            }
            if (collect($values)->except(['_row', '_formula_columns'])->every(fn ($v) => $v === null || $v === '')) {
                continue;
            }
            $limit = min((int) config('local_procurement_imports.max_rows'), $format === 'xls' ? 65535 : 70000);
            if (++$count > $limit) {
                throw ValidationException::withMessages(['import_file' => __('local_procurement.import.row_limit', ['count' => $limit])]);
            }
            yield $values;
        }
        if ($reader) {
            $this->warnings = array_merge($this->warnings, $reader->warnings);
        }
        if ($count === 0) {
            throw ValidationException::withMessages(['import_file' => __('local_procurement.import.empty_rows')]);
        }
    }

    private function xlsxRows(string $path): Generator
    {
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $temporary = storage_path('app/private/imports/reader-cache');
        File::ensureDirectoryExists($temporary);
        $options->setTempFolder($temporary);
        $reader = new Reader($options);
        try {
            $reader->open($path);
            $extraSheets = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getIndex() !== 0) {
                    $extraSheets++;

                    continue;
                }
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = $formulas = [];
                    foreach ($row->getCells() as $index => $cell) {
                        $values[$index] = $cell->getValue();
                        if ($cell instanceof FormulaCell) {
                            $formulas[] = $index;
                        }
                    }
                    yield [$number, $values, $formulas, $options->SHOULD_USE_1904_DATES ? 1904 : 1900];
                }
            }
            if ($extraSheets) {
                $this->warnings[] = ['row' => null, 'column' => 'worksheet', 'message' => __('purchasing.imports.additional_sheets', ['count' => $extraSheets])];
            }
        } finally {
            $reader->close();
            unset($sheet, $row, $cell, $reader);
            gc_collect_cycles();
        }
    }

    private function invalidFile(): never
    {
        throw ValidationException::withMessages(['import_file' => __('local_procurement.formats.invalid_file')]);
    }

    private function headerWarnings(array $values, string $kind): void
    {
        $mapping = match ($kind) {
            'PO' => [4 => ['Order', 'E', ['order', 'po']], 9 => ['Order Date', 'J', ['date', 'tanggal']], 10 => ['Order Amount', 'K', ['amount', 'nilai']]],
            'GR' => [9 => ['Receipt', 'J', ['receipt', 'gr']], 11 => ['Order Line', 'L', ['order', 'po', 'line']], 15 => ['Received Quantity', 'P', ['quant', 'qty']], 19 => ['Actual Receipt Date', 'T', ['date', 'tanggal']]],
            default => [],
        };
        foreach ($mapping as $index => [$expected, $column, $needles]) {
            $actual = $values[$index] ?? '';
            if ($actual !== '' && ! collect($needles)->contains(fn ($needle) => str_contains(mb_strtolower(trim($actual)), $needle))) {
                $this->warnings[] = ['row' => 1, 'column' => $column, 'message' => __('local_procurement.import.header_warning', ['header' => $expected, 'column' => $column, 'actual' => $actual])];
            }
        }
    }

    private function inspectArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['import_file' => __('local_procurement.validation.sheet_invalid')]);
        }
        try {
            $size = 0;
            if ($zip->numFiles > (int) config('local_procurement_imports.max_zip_entries')) {
                throw ValidationException::withMessages(['import_file' => __('local_procurement.large_import.archive_limit')]);
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (($entry['encryption_method'] ?? 0) !== 0) {
                    $this->invalidFile();
                }
                $name = str_replace('\\', '/', $entry['name']);
                $size += $entry['size'];
                if (str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)|^[A-Za-z]:~', $name) || $size > (int) config('local_procurement_imports.max_expanded_bytes')) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.large_import.archive_limit')]);
                }
            }
            foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
                if ($zip->locateName($required) === false) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.validation.sheet_invalid')]);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
