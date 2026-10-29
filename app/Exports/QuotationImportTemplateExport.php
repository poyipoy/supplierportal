<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class QuotationImportTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * GEN-02: the quotation form's editable order (Qty, dimensions in FIXED_DIMENSION_ORDER,
     * KG/Unit, Price/kg, Notes, Availability). The import is heading-based, so files in the
     * previous order still import.
     */
    public const CANONICAL_HEADINGS = [
        'pr_item_id',
        'material_name',
        'requested_dimension',
        'available_qty',
        // Q5: Thickness, Width, Outer Diameter, Inner Diameter, Length.
        'available_thickness',
        'available_width',
        'available_d_outer',
        'available_d_inner',
        'available_length',
        'offered_weight_per_unit',
        'price_per_kg',
        'notes',
        'availability',
    ];

    public function __construct(private readonly int $prId) {}

    public function collection()
    {
        $pr = PurchaseRequisition::with('items')->findOrFail($this->prId);

        return $pr->items->map(fn ($item) => array_values(array_replace(
            array_fill_keys(self::CANONICAL_HEADINGS, null),
            [
                'pr_item_id' => $item->id,
                'material_name' => SpreadsheetCellSanitizer::text($item->material_name),
                'requested_dimension' => SpreadsheetCellSanitizer::text($item->dimension_label),
                'availability' => 'Available',
            ],
        )));
    }

    public function headings(): array
    {
        return self::CANONICAL_HEADINGS;
    }
}
