<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LocalPoImportTemplateExport implements FromArray, WithHeadings, WithTitle
{
    public function headings(): array
    {
        return [
            'Import Status',
            'Import Code',
            'Import Message',
            'Company',
            'Order',
            'Buy-from Business Partner',
            '',
            'Buyer',
            '',
            'Order Date',
            'Order Amount',
            '',
            'Status',
            'Workflow Status',
            'Reference A',
            'Tax Classification',
        ];
    }

    public function array(): array
    {
        return [
            [
                '',
                '',
                '',
                520,
                'PNR261178',
                'SNL00352',
                'PT SURYA UTAMA TEKNOLOGI',
                '0000',
                'FAJAR BAGASKARA',
                now()->format('Y-m-d H:i'),
                633000,
                'IDR',
                'Closed',
                'Approved',
                'Contoh Referensi',
                'PPH',
            ],
        ];
    }

    public function title(): string
    {
        return 'data';
    }
}
