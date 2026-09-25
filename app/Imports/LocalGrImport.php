<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LocalGrImport extends AbstractPreviewImport implements SkipsEmptyRows, ToCollection, WithEvents, WithMultipleSheets
{
    protected const MAX_DATA_ROWS = 2000;

    public function collection(Collection $collection): void
    {
        if ($collection->isEmpty()) {
            $this->addFileError(null, 'import_file', 'The spreadsheet does not contain any data rows.');

            return;
        }

        $headerRow = $collection->first();
        $headerArray = $headerRow instanceof Collection ? $headerRow->toArray() : (array) $headerRow;

        $this->validateHeaderPositions($headerArray);

        $dataRows = $collection->slice(1);
        $this->totalRowCount = $dataRows->count();

        if ($this->totalRowCount > self::MAX_DATA_ROWS) {
            $this->invalidRowCount = $this->totalRowCount;
            $this->addFileError(
                null,
                'import_file',
                'The spreadsheet may contain no more than '.self::MAX_DATA_ROWS.' non-empty data rows.'
            );

            return;
        }

        foreach ($dataRows as $offset => $row) {
            $rowArray = $row instanceof Collection ? $row->toArray() : (array) $row;
            $rowNumber = $offset + 1; // 1-indexed Excel row number

            $description = self::nullableText($rowArray[8] ?? null);
            $grNumber = self::nullableText($rowArray[9] ?? null);
            $poNumber = self::nullableText($rowArray[11] ?? null);
            $qty = self::nullableNumber($rowArray[15] ?? null);
            $grDate = $rowArray[19] ?? null;

            // Skip completely empty rows
            if ($grNumber === null && $poNumber === null && $qty === null && ($grDate === null || $grDate === '') && $description === null) {
                continue;
            }

            $formulaCols = [];
            foreach ([8 => 'description', 9 => 'gr_number', 11 => 'po_number', 15 => 'qty', 19 => 'gr_date'] as $colIdx => $colName) {
                $val = $rowArray[$colIdx] ?? null;
                if (is_string($val) && str_starts_with($val, '=')) {
                    $formulaCols[] = $colName;
                }
            }

            $this->rows[] = [
                '_row' => $rowNumber,
                'description' => $description,
                'gr_number' => $grNumber,
                'po_number' => $poNumber,
                'qty' => $qty,
                'gr_date' => $grDate,
                '_formula_columns' => $formulaCols,
            ];
        }

        if (empty($this->rows) && ! $this->fileInvalid) {
            $this->addFileError(null, 'import_file', 'The spreadsheet does not contain any populated GR data rows.');
        }
    }

    private function validateHeaderPositions(array $header): void
    {
        $receiptHeader = mb_strtolower(trim((string) ($header[9] ?? '')));
        if ($receiptHeader !== '' && ! str_contains($receiptHeader, 'receipt') && ! str_contains($receiptHeader, 'gr')) {
            $this->addWarning(1, 'J', "Expected 'Receipt' header at column J, found '{$header[9]}'.");
        }

        $orderLineHeader = mb_strtolower(trim((string) ($header[11] ?? '')));
        if ($orderLineHeader !== '' && ! str_contains($orderLineHeader, 'order') && ! str_contains($orderLineHeader, 'po') && ! str_contains($orderLineHeader, 'line')) {
            $this->addWarning(1, 'L', "Expected 'Order Line' header at column L, found '{$header[11]}'.");
        }

        $qtyHeader = mb_strtolower(trim((string) ($header[15] ?? '')));
        if ($qtyHeader !== '' && ! str_contains($qtyHeader, 'quant') && ! str_contains($qtyHeader, 'qty')) {
            $this->addWarning(1, 'P', "Expected 'Received Quantity' header at column P, found '{$header[15]}'.");
        }

        $dateHeader = mb_strtolower(trim((string) ($header[19] ?? '')));
        if ($dateHeader !== '' && ! str_contains($dateHeader, 'date') && ! str_contains($dateHeader, 'tanggal')) {
            $this->addWarning(1, 'T', "Expected 'Actual Receipt Date' header at column T, found '{$header[19]}'.");
        }
    }
}
