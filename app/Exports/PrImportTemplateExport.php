<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PrImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public const REQUIRED_HEADER_FILL = 'FDE68A';

    public const CONDITIONAL_HEADER_FILL = 'FEF3C7';

    public const OPTIONAL_HEADER_FILL = 'F1F5F9';

    // Q5: technical heading keys are unchanged; dimension order is
    // Thickness, Width, Outer Diameter, Inner Diameter, Length, followed by Quantity.
    // Parsing is heading-based, so files in the previous order still import.
    public function array(): array
    {
        return [[
            'SCM440',
            'Round',
            null,  // thickness
            null,  // width
            25,    // d_outer
            null,  // d_inner
            6000,  // length
            10,    // quantity
            'Example material remark',
        ]];
    }

    public function headings(): array
    {
        return [
            'material_name',
            'shape',
            'thickness',
            'width',
            'd_outer',
            'd_inner',
            'length',
            'quantity',
            'remark',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        self::applyPrMaterialHeaderStyles($sheet);

        return [];
    }

    public static function applyPrMaterialHeaderStyles(Worksheet $sheet): void
    {
        // Always required columns: material_name (A), shape (B), length (G), quantity (H)
        foreach (['A1', 'B1', 'G1', 'H1'] as $cell) {
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '78350F']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::REQUIRED_HEADER_FILL]],
            ]);
        }

        // Shape-dependent required dimension columns: thickness (C), width (D), d_outer (E), d_inner (F)
        foreach (['C1', 'D1', 'E1', 'F1'] as $cell) {
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '92400E']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CONDITIONAL_HEADER_FILL]],
            ]);
        }

        // Optional column: remark (I)
        $sheet->getStyle('I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '334155']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::OPTIONAL_HEADER_FILL]],
        ]);
    }
}
