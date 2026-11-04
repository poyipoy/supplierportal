<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PrImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
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
}
