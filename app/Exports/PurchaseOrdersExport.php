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
        $q = PurchaseOrder::with(['supplier', 'quotations.purchaseRequirement', 'quotations.items.prItem', 'quotations.exchange_rate'])->orderBy('created_at', 'desc');
        if ($this->supplierId) $q->where('supplier_id', $this->supplierId);
        if ($this->startDate) $q->whereDate('created_at', '>=', $this->startDate);
        if ($this->endDate) $q->whereDate('created_at', '<=', $this->endDate);
        $rows = collect();
        foreach ($q->get() as $po) {
            $prNumbers = $po->quotations->map(fn($qt) => optional($qt->purchaseRequirement)->pr_number)->filter()->implode(', ') ?: '-';
            $materials = $po->quotations->flatMap(fn($qt) => $qt->items->map(fn($i) => optional($i->prItem)->material_name))->filter()->implode(', ') ?: '-';
            $totalAmount = $po->quotations->sum(fn($qt) => $qt->items->sum('amount'));
            $currency = $po->currency ?? '-';

            $totalIdr = 0;
            foreach ($po->quotations as $qt) {
                $rate = $qt->exchange_rate;
                $rateVal = $rate ? $rate->rate_to_idr : 0;
                foreach ($qt->items as $item) {
                    $totalIdr += $item->amount * $rateVal;
                }
            }

            $rows->push([$po->po_number, $prNumbers, optional($po->supplier)->name ?? '-', $materials, number_format($totalAmount, 2) . " {$currency}", 'Rp ' . number_format($totalIdr, 0, ',', '.'), $po->estimated_arrival ?? '-', strtoupper($po->status)]);
        }
        return $rows;
    }

    public function headings(): array { return ['Nomor PO', 'Nomor PR', 'Supplier', 'Material', 'Harga (Mata Uang)', 'Total IDR', 'Est. Kedatangan', 'Status']; }
}
