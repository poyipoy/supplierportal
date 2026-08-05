<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PurchaseRequisitionDetailExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly int $prId) {}

    public function collection()
    {
        $pr = PurchaseRequisition::with(['period', 'items'])->findOrFail($this->prId);

        return $pr->items->map(function ($item) use ($pr) {
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
                SpreadsheetCellSanitizer::text($item->remark),
            ];
        });
    }

    public function headings(): array
    {
        return ['PR Number', 'HS Code', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'Remark'];
    }
}
