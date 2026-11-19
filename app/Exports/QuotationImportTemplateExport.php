<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class QuotationImportTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
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
        // Q5: Thickness, Width, Outer Diameter, Inner Diameter, Length, followed by Qty.
        'available_thickness',
        'available_width',
        'available_d_outer',
        'available_d_inner',
        'available_length',
        'available_qty',
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

    public function styles(Worksheet $sheet): array
    {
        self::applyQuotationTemplateHeaderStyles($sheet);

        return [];
    }

    public static function applyQuotationTemplateHeaderStyles(Worksheet $sheet): void
    {
        // System/Required columns: pr_item_id (A), material_name (B), requested_dimension (C), available_qty (I), price_per_kg (K), availability (M)
        foreach (['A1', 'B1', 'C1', 'I1', 'K1', 'M1'] as $cell) {
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '78350F']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => PrImportTemplateExport::REQUIRED_HEADER_FILL]],
            ]);
        }

        // Conditional dimension & weight columns: D1..H1, J1
        foreach (['D1', 'E1', 'F1', 'G1', 'H1', 'J1'] as $cell) {
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '92400E']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => PrImportTemplateExport::CONDITIONAL_HEADER_FILL]],
            ]);
        }

        // Optional notes column: L1
        $sheet->getStyle('L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '334155']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => PrImportTemplateExport::OPTIONAL_HEADER_FILL]],
        ]);
    }
}
