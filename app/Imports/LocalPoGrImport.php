<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LocalPoGrImport extends AbstractPreviewImport implements SkipsEmptyRows, ToCollection, WithEvents, WithHeadingRow, WithMultipleSheets
{
    private const HEADINGS = ['po_number', 'supplier_name', 'po_date', 'po_amount', 'po_remarks', 'gr_number', 'gr_date', 'gr_amount', 'gr_remarks'];
    private const REQUIRED = ['po_number', 'supplier_name', 'po_date', 'po_amount'];

    public function collection(Collection $collection): void
    {
        if (! $this->validateCollectionContract($collection, self::REQUIRED, self::HEADINGS)) return;
        foreach ($collection as $index => $row) {
            $raw = $row->toArray();
            $this->rows[] = [
                '_row' => $index + 2,
                'po_number' => self::nullableText($raw['po_number'] ?? null),
                'supplier_name' => self::nullableText($raw['supplier_name'] ?? null),
                'po_date' => $raw['po_date'] ?? null,
                'po_amount' => self::nullableNumber($raw['po_amount'] ?? null),
                'po_remarks' => self::nullableText($raw['po_remarks'] ?? null),
                'gr_number' => self::nullableText($raw['gr_number'] ?? null),
                'gr_date' => $raw['gr_date'] ?? null,
                'gr_amount' => self::nullableNumber($raw['gr_amount'] ?? null),
                'gr_remarks' => self::nullableText($raw['gr_remarks'] ?? null),
                '_formula_columns' => $this->formulaColumns($raw, self::HEADINGS),
            ];
        }
    }
}
