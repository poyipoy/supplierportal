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

    /** @return array<string, string> */
    protected function workbookNumberFormats(): array
    {
        return [];
    }

    public function styles(Worksheet $sheet): array
    {
        if (! $this->workbookPresentationEnabled()) {
            return [];
        }

        $columnCount = count($this->headings());
        if ($columnCount < 1) {
            return [];
        }

        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        // Only the header's vertical height is fixed; column widths come from
        // WithColumnWidths and the selected catalog, and data rows stay automatic.
        $sheet->getRowDimension(1)->setRowHeight(24, 'px');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.'1');
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

            foreach ($this->workbookNumberFormats() as $column => $formatCode) {
                $sheet->getStyle($column.'2:'.$column.$lastRow)
                    ->getNumberFormat()
                    ->setFormatCode($formatCode);
            }
        }

        return $styles;
    }
}
