<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LocalPoImport extends AbstractPreviewImport implements SkipsEmptyRows, ToCollection, WithEvents, WithMultipleSheets
{
    protected const MAX_DATA_ROWS = 1000;

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

            $poNumber = self::nullableText($rowArray[4] ?? null);
            $supplierName = self::nullableText($rowArray[6] ?? null);
            $poDate = $rowArray[9] ?? null;
            $poAmount = self::nullableNumber($rowArray[10] ?? null);

            // Skip completely empty rows
            if ($poNumber === null && $supplierName === null && ($poDate === null || $poDate === '') && $poAmount === null) {
                continue;
            }

            $formulaCols = [];
            foreach ([4 => 'po_number', 6 => 'supplier_name', 9 => 'po_date', 10 => 'po_amount'] as $colIdx => $colName) {
                $val = $rowArray[$colIdx] ?? null;
                if (is_string($val) && str_starts_with($val, '=')) {
                    $formulaCols[] = $colName;
                }
            }

            $this->rows[] = [
                '_row' => $rowNumber,
                'po_number' => $poNumber,
                'supplier_name' => $supplierName,
                'po_date' => $poDate,
                'po_amount' => $poAmount,
                '_formula_columns' => $formulaCols,
            ];
        }

        if (empty($this->rows) && ! $this->fileInvalid) {
            $this->addFileError(null, 'import_file', 'The spreadsheet does not contain any populated PO data rows.');
        }
    }

    private function validateHeaderPositions(array $header): void
    {
        $orderHeader = mb_strtolower(trim((string) ($header[4] ?? '')));
        if ($orderHeader !== '' && ! str_contains($orderHeader, 'order') && ! str_contains($orderHeader, 'po')) {
            $this->addWarning(1, 'E', "Expected 'Order' header at column E, found '{$header[4]}'.");
        }

        $dateHeader = mb_strtolower(trim((string) ($header[9] ?? '')));
        if ($dateHeader !== '' && ! str_contains($dateHeader, 'date') && ! str_contains($dateHeader, 'tanggal')) {
            $this->addWarning(1, 'J', "Expected 'Order Date' header at column J, found '{$header[9]}'.");
        }

        $amountHeader = mb_strtolower(trim((string) ($header[10] ?? '')));
        if ($amountHeader !== '' && ! str_contains($amountHeader, 'amount') && ! str_contains($amountHeader, 'nilai')) {
            $this->addWarning(1, 'K', "Expected 'Order Amount' header at column K, found '{$header[10]}'.");
        }
    }
}
