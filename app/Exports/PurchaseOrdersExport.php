<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use App\Models\ExchangeRate;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PurchaseOrdersExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $supplierId, $startDate, $endDate;
    public function __construct($supplierId = null, $startDate = null, $endDate = null) { $this->supplierId = $supplierId; $this->startDate = $startDate; $this->endDate = $endDate; }

    public function collection()
    {
        $q = PurchaseOrder::with(['quotation.supplier', 'quotation.purchaseRequirement', 'quotation.items.prItem'])->orderBy('created_at', 'desc');
        if ($this->supplierId) $q->whereHas('quotation', fn($qb) => $qb->where('supplier_id', $this->supplierId));
        if ($this->startDate) $q->whereDate('created_at', '>=', $this->startDate);
        if ($this->endDate) $q->whereDate('created_at', '<=', $this->endDate);
        $rows = collect();
        foreach ($q->get() as $po) {
            $quot = $po->quotation;
            $pr = optional($quot)->purchaseRequirement;
            $materials = optional($quot)->items ? $quot->items->map(fn($i) => optional($i->prItem)->material_name)->filter()->implode(', ') : '-';
            $totalAmount = optional($quot)->items ? $quot->items->sum('amount') : 0;
            $currency = optional($quot)->currency ?? '-';
            $rate = ExchangeRate::where('currency', $currency)->orderBy('valid_from', 'desc')->first();
            $rateVal = $rate ? $rate->rate_to_idr : 0;
            $totalIdr = $totalAmount * $rateVal;
            $rows->push([$po->po_number, optional($pr)->pr_number ?? '-', optional(optional($quot)->supplier)->name ?? '-', $materials, number_format($totalAmount, 2) . " {$currency}", 'Rp ' . number_format($rateVal, 0, ',', '.'), 'Rp ' . number_format($totalIdr, 0, ',', '.'), $po->estimated_arrival ?? '-', strtoupper($po->status)]);
        }
        return $rows;
    }

    public function headings(): array { return ['Nomor PO', 'Nomor PR', 'Supplier', 'Material', 'Harga (Mata Uang)', 'Kurs', 'Total IDR', 'Est. Kedatangan', 'Status']; }
}
