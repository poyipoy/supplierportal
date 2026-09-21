<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class LocalPoGrImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['po_number', 'supplier_name', 'po_date', 'po_amount', 'po_remarks', 'gr_number', 'gr_date', 'gr_amount', 'gr_remarks'];
    }

    public function array(): array
    {
        return [['PO-LOCAL-001', 'PT Supplier Contoh', now()->format('Y-m-d'), 100000000, 'Optional', 'GR-LOCAL-001', now()->format('Y-m-d'), 50000000, 'Optional']];
    }
}
