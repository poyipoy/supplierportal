<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PurchaseRequisitionDetailExport implements FromCollection, WithColumnWidths, WithHeadings
{
    public function __construct(private readonly int $prId) {}

    public function collection()
    {
        $pr = PurchaseRequisition::with(['period', 'items'])->findOrFail($this->prId);
        $prTotalKg = $pr->items->sum(fn ($prItem) => $prItem->total_weight);

        return $pr->items->map(function ($item) use ($pr, $prTotalKg) {
            $specification = collect([
                $item->shape,
                $item->dimension_label !== '-' ? $item->dimension_label : null,
            ])->filter()->implode(' | ');

            return [
                SpreadsheetCellSanitizer::text($pr->pr_number, 'DRAFT'),
                SpreadsheetCellSanitizer::text($item->hs_code),
                SpreadsheetCellSanitizer::text($item->material_name),
                SpreadsheetCellSanitizer::text($specification),
                $item->quantity_value,
                (float) $item->weight_needed,
                (float) $item->total_weight,
                (float) $prTotalKg,
                SpreadsheetCellSanitizer::text($item->remark),
            ];
        });
    }

    public function headings(): array
    {
        return ['PR Number', 'HS Code', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'PR Total KG', 'Remark'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 16,
            'C' => 30,
            'D' => 38,
            'E' => 10,
            'F' => 15,
            'G' => 15,
            'H' => 15,
            'I' => 30,
        ];
    }
}
