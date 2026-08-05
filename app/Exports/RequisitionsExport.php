<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RequisitionsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $periodId, $status, $search;

    public function __construct($periodId = null, $status = null, $search = null)
    {
        $this->periodId = $periodId;
        $this->status = $status;
        $this->search = $search;
    }

    public function collection()
    {
        $q = PurchaseRequisition::with(['period', 'items', 'creator']);

        if ($this->periodId) {
            $q->where('period_id', $this->periodId);
        }

        if ($this->status) {
            $q->where('status', $this->status);
        }

        if ($search = trim((string) $this->search)) {
            $q->where(function ($query) use ($search) {
                $query->where('pr_number', 'like', '%'.$search.'%')
                    ->orWhereHas('period', fn ($period) => $period->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('creator', fn ($creator) => $creator->where('name', 'like', '%'.$search.'%'))
                    ->orWhere('created_at', 'like', '%'.$search.'%');
            });
        }

        $rows = collect();

        foreach ($q->lazyByIdDesc(500) as $pr) {
            foreach ($pr->items as $item) {
                $spec = collect([$item->shape, $item->dimension_label !== '-' ? $item->dimension_label : null])->filter()->implode(' | ');
                $rows->push([
                    SpreadsheetCellSanitizer::text($pr->pr_number, 'DRAFT'),
                    SpreadsheetCellSanitizer::text(optional($pr->period)->name),
                    SpreadsheetCellSanitizer::text($item->material_name),
                    SpreadsheetCellSanitizer::text($spec),
                    $item->quantity_value,
                    (float) $item->weight_needed,
                    (float) $item->total_weight,
                    SpreadsheetCellSanitizer::text($item->remark),
                    SpreadsheetCellSanitizer::text(strtoupper($pr->status)),
                    $pr->created_at->format('Y-m-d H:i:s'),
                ]);
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['PR Number', 'Period', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'Remark', 'Status', 'Date Created'];
    }
}
