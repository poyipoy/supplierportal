<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait StylesOperationalWorkbook
{
    abstract protected function workbookPresentationEnabled(): bool;

    /** @return list<string> */
    abstract protected function workbookWrappedColumns(): array;

    public function styles(Worksheet $sheet): array
    {
        if (! $this->workbookPresentationEnabled()) {
            return [];
        }

        $columnCount = count($this->headings());
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        // Only the header's vertical height is fixed; column widths come from
        // WithColumnWidths and the selected catalog, and data rows stay automatic.
        $sheet->getRowDimension(1)->setRowHeight(24, 'px');
        $styles = [
            'A1:'.$lastColumn.'1' => [
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '9C4A0F']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => false,
                    'shrinkToFit' => true,
                ],
            ],
        ];

        $lastRow = $sheet->getHighestDataRow();
        if ($lastRow > 1) {
            foreach ($this->workbookWrappedColumns() as $column) {
                $styles[$column.'2:'.$column.$lastRow] = [
                    'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
                ];
            }
        }

        return $styles;
    }
}
