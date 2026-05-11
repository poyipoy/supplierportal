<?php

namespace App\Exports;

use App\Models\PurchaseRequirement;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class RequirementsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $periodId, $status;
    public function __construct($periodId = null, $status = null) { $this->periodId = $periodId; $this->status = $status; }

    public function collection()
    {
        $q = PurchaseRequirement::with(['period', 'items'])->orderBy('created_at', 'desc');
        if ($this->periodId) $q->where('period_id', $this->periodId);
        if ($this->status) $q->where('status', $this->status);
        $rows = collect();
        foreach ($q->get() as $pr) {
            foreach ($pr->items as $item) {
                $spec = collect([$item->shape, $item->thickness ? "T:{$item->thickness}" : null, $item->width ? "W:{$item->width}" : null, $item->length ? "L:{$item->length}" : null])->filter()->implode(' | ');
                $rows->push([$pr->pr_number ?? 'DRAFT', optional($pr->period)->name, $item->material_name, $spec ?: '-', $item->weight_needed, strtoupper($pr->status), $pr->created_at->format('d/m/Y H:i')]);
            }
        }
        return $rows;
    }

    public function headings(): array { return ['Nomor PR', 'Periode', 'Nama Material', 'Spesifikasi', 'Berat Diminta', 'Status', 'Tanggal Dibuat']; }
}
