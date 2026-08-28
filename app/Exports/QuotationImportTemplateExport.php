<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class QuotationImportTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(private readonly int $prId) {}

    public function collection()
    {
        $pr = PurchaseRequisition::with('items')->findOrFail($this->prId);

        return $pr->items->map(fn ($item) => [
            $item->id,
            SpreadsheetCellSanitizer::text($item->material_name),
            SpreadsheetCellSanitizer::text($item->dimension_label),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'Available',
            null,
        ]);
    }

    public function headings(): array
    {
        return [
            'pr_item_id',
            'material_name',
            'requested_dimension',
            'price_per_kg',
            'available_qty',
            'available_thickness',
            'available_d_inner',
            'available_d_outer',
            'available_width',
            'available_length',
            'notes',
            'availability',
            'offered_weight_per_unit',
        ];
    }
}
