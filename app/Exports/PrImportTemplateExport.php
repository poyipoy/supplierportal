<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PrImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function array(): array
    {
        return [[
            'SCM440',
            'Round',
            10,
            null,
            null,
            25,
            null,
            6000,
            'Example material remark',
        ]];
    }

    public function headings(): array
    {
        return [
            'material_name',
            'shape',
            'quantity',
            'thickness',
            'd_inner',
            'd_outer',
            'width',
            'length',
            'remark',
        ];
    }
}
