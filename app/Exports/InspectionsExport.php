<?php

namespace App\Exports;

use App\Models\QcInspection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class InspectionsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $startDate, $endDate, $status;
    public function __construct($startDate = null, $endDate = null, $status = null) { $this->startDate = $startDate; $this->endDate = $endDate; $this->status = $status; }

    public function collection()
    {
        $q = QcInspection::with(['purchaseOrder.supplier', 'items.prItem'])->orderBy('inspected_at', 'desc');
        if ($this->startDate) $q->whereDate('inspected_at', '>=', $this->startDate);
        if ($this->endDate) $q->whereDate('inspected_at', '<=', $this->endDate);
        if ($this->status) $q->where('status', $this->status);
        $rows = collect();
        foreach ($q->get() as $insp) {
            foreach ($insp->items as $item) {
                $pi = $item->prItem;
                $specD = collect([$pi && $pi->thickness ? "T:{$pi->thickness}" : null, $pi && $pi->width ? "W:{$pi->width}" : null, $pi && $pi->length ? "L:{$pi->length}" : null])->filter()->implode(' | ') ?: '-';
                $dimA = collect([$item->actual_thickness ? "T:{$item->actual_thickness}" : null, $item->actual_width ? "W:{$item->actual_width}" : null, $item->actual_length ? "L:{$item->actual_length}" : null])->filter()->implode(' | ') ?: '-';
                $rows->push([optional($insp->purchaseOrder)->po_number ?? '-', optional(optional($insp->purchaseOrder)->supplier)->name ?? '-', optional($pi)->material_name ?? '-', $specD, $dimA, strtoupper($item->status), strtoupper($insp->status), $insp->inspected_at ? $insp->inspected_at->format('d/m/Y H:i') : '-']);
            }
        }
        return $rows;
    }

    public function headings(): array { return ['Nomor PO', 'Supplier', 'Material', 'Spesifikasi Diminta', 'Dimensi Aktual', 'Status Item', 'Status Inspeksi', 'Tanggal Inspeksi']; }
}
