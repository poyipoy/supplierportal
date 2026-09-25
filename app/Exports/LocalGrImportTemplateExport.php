<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class LocalGrImportTemplateExport implements FromArray, WithHeadings, WithTitle
{
    public function headings(): array
    {
        return [
            'Import Status',
            'Import Code',
            'Import Message',
            'Company',
            'Warehouse',
            '',
            'Item',
            '',
            '',
            'Receipt',
            'Line',
            'Order Line',
            '',
            '',
            '',
            'Received Quantity',
            '',
            'Final Receipt',
            'Line Status',
            'Actual Receipt Date',
            'Packing Slip',
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
                'CPWP1',
                'CKG WIP',
                '',
                'FCORODD-DHW161000',
                'DHW Ø 1.6 X 1000',
                'REC060436',
                10,
                'PNR261178',
                1,
                10,
                1,
                4,
                'pcs',
                'Yes',
                'Confirmed',
                now()->format('Y-m-d H:i'),
                '',
            ],
        ];
    }

    public function title(): string
    {
        return 'data';
    }
}
