<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class RequisitionsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $periodId, $status;
    public function __construct($periodId = null, $status = null) { $this->periodId = $periodId; $this->status = $status; }

    public function collection()
    {
        $q = PurchaseRequisition::with(['period', 'items'])->orderBy('created_at', 'desc');
        if ($this->periodId) $q->where('period_id', $this->periodId);
        if ($this->status) $q->where('status', $this->status);
        $rows = collect();
        foreach ($q->get() as $pr) {
            foreach ($pr->items as $item) {
                $spec = collect([$item->shape, $item->dimension_label !== '-' ? $item->dimension_label : null])->filter()->implode(' | ');
                $rows->push([
                    $pr->pr_number ?? 'DRAFT',
                    optional($pr->period)->name,
                    $item->material_name,
                    $spec ?: '-',
                    $item->quantity_value,
                    $item->weight_needed,
                    $item->total_weight,
                    strtoupper($pr->status),
                    $pr->created_at->format('d/m/Y H:i'),
                ]);
            }
        }
        return $rows;
    }

    public function headings(): array { return ['PR Number', 'Period', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'Status', 'Date Created']; }
}
