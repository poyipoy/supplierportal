<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PrImportTemplateExport extends DefaultValueBinder implements FromArray, WithHeadings, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder
{
    public function bindValue(Cell $cell, $value)
    {
        if ($cell->getColumn() === 'B' && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function array(): array
    {
        return [[
            'SCM440',
            '72283010',
            'Round',
            10,
            null,
            null,
            25,
            null,
            6000,
            125.5,
            'Example material remark',
        ]];
    }

    public function headings(): array
    {
        return [
            'material_name',
            'hs_code',
            'shape',
            'quantity',
            'thickness',
            'd_inner',
            'd_outer',
            'width',
            'length',
            'weight_needed',
            'remark',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
