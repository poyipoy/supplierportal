<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PurchaseOrdersExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $supplierId, $startDate, $endDate, $poNumber, $status, $search;

    public function __construct(
        $supplierId = null,
        $startDate = null,
        $endDate = null,
        $poNumber = null,
        $status = null,
        $search = null
    ) {
        $this->supplierId = $supplierId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->poNumber = $poNumber;
        $this->status = $status;
        $this->search = $search;
    }

    public function collection()
    {
        $q = PurchaseOrder::with([
            'supplier',
            'quotations.purchaseRequisition.period',
            'quotations.items.prItem',
            'quotations.exchange_rate',
        ]);

        if ($this->supplierId) {
            $q->where('supplier_id', $this->supplierId);
        }

        if ($this->startDate) {
            $q->whereDate('created_at', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $q->whereDate('created_at', '<=', $this->endDate);
        }

        if ($poNumber = trim((string) $this->poNumber)) {
            $q->where('po_number', 'like', '%'.$poNumber.'%');
        }

        if ($this->status === 'overdue') {
            $q->where(function ($query) {
                $query->where('status', 'overdue')
                    ->orWhere(function ($activeQuery) {
                        $activeQuery->where('status', 'active')
                            ->whereNotNull('estimated_arrival')
                            ->whereDate('estimated_arrival', '<', today())
                            ->whereNull('actual_arrival');
                    });
            });
        } elseif ($this->status) {
            $q->where('status', $this->status);
        }

        if ($search = trim((string) $this->search)) {
            $q->where(function ($query) use ($search) {
                $query->where('po_number', 'like', '%'.$search.'%')
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('quotations.purchaseRequisition.period', fn ($period) => $period->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('quotations.purchaseRequisition', fn ($pr) => $pr->where('pr_number', 'like', '%'.$search.'%'))
                    ->orWhere('notes', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%')
                    ->orWhere('estimated_arrival', 'like', '%'.$search.'%');
            });
        }

        $rows = collect();

        foreach ($q->lazyByIdDesc(500) as $po) {
            $prNumbers = $po->pr_reference;
            $materials = $po->quotations->flatMap(fn($qt) => $qt->items->map(fn($i) => optional($i->prItem)->material_name))->filter()->implode(', ') ?: '-';
            $totalAmount = (float) $po->quotations->sum(fn($qt) => $qt->items->sum('amount'));
            $currency = $po->currency ?? '-';

            $totalIdr = 0.0;
            foreach ($po->quotations as $qt) {
                $rate = $qt->exchange_rate;
                $rateVal = (float) ($rate?->rate_to_idr ?? 0);
                foreach ($qt->items as $item) {
                    $totalIdr += (float) $item->amount * $rateVal;
                }
            }

            $rows->push([
                SpreadsheetCellSanitizer::text($po->po_number),
                SpreadsheetCellSanitizer::text($prNumbers),
                SpreadsheetCellSanitizer::text(optional($po->supplier)->name),
                SpreadsheetCellSanitizer::text($materials),
                SpreadsheetCellSanitizer::text($currency),
                $totalAmount,
                $totalIdr,
                $po->estimated_arrival?->format('Y-m-d') ?? '-',
                SpreadsheetCellSanitizer::text($po->notes),
                SpreadsheetCellSanitizer::text(StatusHelper::poLabel($po->status, $po->is_overdue)),
            ]);
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['PO Number', 'PR Number', 'Supplier', 'Material', 'Currency', 'Total Amount', 'Total IDR', 'Est. Arrival', 'Remark', 'Status'];
    }
}
